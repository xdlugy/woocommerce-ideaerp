<?php

namespace WooIdeaERP\Sync;

use WooIdeaERP\Api\Client;
use WooIdeaERP\Api\Endpoints\OrdersEndpoint;
use WooIdeaERP\Helpers\Logger;
use WooIdeaERP\Sync\InvoiceImporter;

defined( 'ABSPATH' ) || exit;

/**
 * Step 2 — Exports a WooCommerce order to IdeaERP as a sale order.
 *
 * Hooks into woocommerce_order_status_changed and fires when the order
 * reaches the configured trigger status (default: processing).
 *
 * Guard: skips silently when _erp_order_id meta is already set so that
 * re-triggering the same status change never double-pushes an order.
 */
class OrderExporter {

	private const META_ERP_ORDER_ID = '_erp_order_id';
	private const ASYNC_ACTION      = 'wideaerp_export_order';
	private const AS_GROUP          = 'woocommerce-ideaerp';

	public function register_hooks(): void {
		add_action( 'woocommerce_order_status_changed', [ $this, 'handle' ], 10, 4 );
		add_action( self::ASYNC_ACTION, [ $this, 'run_async_export' ] );
	}

	/**
	 * Called by WooCommerce on every order status transition.
	 *
	 * Enqueues an async Action Scheduler job instead of hitting the ERP inline, so
	 * bulk status changes don't fan out to N synchronous HTTP calls in one request.
	 *
	 * @param int       $order_id   WC order ID.
	 * @param string    $old_status Previous status slug (without "wc-" prefix).
	 * @param string    $new_status New status slug.
	 * @param \WC_Order $order      WC order object.
	 */
	public function handle( int $order_id, string $old_status, string $new_status, \WC_Order $order ): void {
		$trigger = get_option( 'wideaerp_order_trigger_status', 'processing' );

		if ( $new_status !== $trigger ) {
			return;
		}

		if ( $order->get_meta( self::META_ERP_ORDER_ID ) ) {
			Logger::debug( sprintf( 'OrderExporter: order #%d already pushed (erp_order_id=%s), skipping.', $order_id, $order->get_meta( self::META_ERP_ORDER_ID ) ) );
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ASYNC_ACTION, [ $order_id ], self::AS_GROUP );
			return;
		}

		// Fallback when Action Scheduler is unavailable.
		$this->export( $order );
	}

	/**
	 * Async handler invoked by Action Scheduler.
	 */
	public function run_async_export( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			Logger::debug( sprintf( 'OrderExporter: order #%d no longer exists, async export skipped.', $order_id ) );
			return;
		}

		if ( $order->get_meta( self::META_ERP_ORDER_ID ) ) {
			return;
		}

		$this->export( $order );
	}

	/**
	 * Build the CreateSaleOrder payload and POST it to IdeaERP.
	 * On success stores _erp_order_id on the WC order.
	 * On failure logs the error and adds an order note.
	 */
	public function export( \WC_Order $order ): void {
		$url   = get_option( 'wideaerp_erp_url', '' );
		$token = get_option( 'wideaerp_api_token', '' );

		if ( ! $url || ! $token ) {
			Logger::error( 'OrderExporter: ERP URL or API token not configured.' );
			$order->add_order_note( __( 'IdeaERP: export skipped — ERP URL or API token not configured.', 'woocommerce-ideaerp' ) );
			return;
		}

		try {
			$endpoint = new OrdersEndpoint( new Client( $url, $token ) );
			$payload  = $this->build_payload( $order );
			$erp      = $endpoint->create( $payload );

			$order->update_meta_data( self::META_ERP_ORDER_ID, $erp->id );

			// Flag this order so InvoiceImporter knows to poll for its invoice.
			$order->update_meta_data( InvoiceImporter::META_INVOICE_PENDING, '1' );

			$order->save_meta_data();

			$order->add_order_note(
				sprintf(
					/* translators: %d: IdeaERP order ID */
					__( 'IdeaERP: order exported successfully (ERP order ID: %d).', 'woocommerce-ideaerp' ),
					$erp->id
				)
			);

			Logger::info( sprintf( 'OrderExporter: WC order #%d → ERP order #%d.', $order->get_id(), $erp->id ) );

		} catch ( \RuntimeException $e ) {
			Logger::error( sprintf( 'OrderExporter: failed to export WC order #%d — %s', $order->get_id(), $e->getMessage() ) );
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'IdeaERP: order export failed — %s', 'woocommerce-ideaerp' ),
					$e->getMessage()
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Payload builders
	// -------------------------------------------------------------------------

	/** @return array<string,mixed> */
	private function build_payload( \WC_Order $order ): array {
		$shop_id            = get_option( 'wideaerp_shop_id', '' );
		$integration_config = (int) get_option( 'wideaerp_integration_config', 0 );

		$payload = [
			'name'             => null,
			'date_order'       => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
			'is_paid'          => $order->is_paid(),
			'amount_paid'      => (float) $order->get_total(),
			'delivery_price'   => (float) $order->get_shipping_total() + (float) $order->get_shipping_tax(),
			'integration_id'   => (string) $order->get_id(),
			'integration_type' => 'woocommerce',
			'integration_email'=> $order->get_billing_email(),
			'partner'          => $this->build_partner( $order ),
			'order_lines'      => $this->build_order_lines( $order ),
			'note'             => $order->get_customer_note() ?: null,
		];

		$partner_invoice = $this->build_invoice_partner( $order );
		if ( $partner_invoice !== null ) {
			$payload['partner_invoice'] = $partner_invoice;
		}

		$partner_shipping = $this->build_shipping_partner( $order );
		if ( $partner_shipping !== null ) {
			$payload['partner_shipping'] = $partner_shipping;
		}

		$erp_pm_id = $this->resolve_erp_payment_method_id( $order );
		if ( $erp_pm_id ) {
			$payload['payment_term'] = [ 'id' => $erp_pm_id, 'name' => null ];
		}

		$erp_pl_id = $this->resolve_erp_pricelist_id( $order );
		if ( $erp_pl_id ) {
			$payload['pricelist'] = [ 'id' => $erp_pl_id, 'name' => null ];
		}

		$erp_carrier_id = $this->resolve_erp_carrier_id( $order );
		if ( $erp_carrier_id ) {
			$payload['carrier'] = [ 'id' => $erp_carrier_id, 'name' => null ];
		}

		if ( $integration_config ) {
			$payload['integration_config'] = $integration_config;
		}

		if ( $shop_id ) {
			$payload['shop'] = is_numeric( $shop_id )
				? [ 'id' => (int) $shop_id, 'name' => null ]
				: [ 'id' => null, 'name' => $shop_id ];
		}

		return $payload;
	}

	/**
	 * Look up the IdeaERP payment method ID mapped to the WC order's payment method.
	 * Returns 0 when no mapping is configured for this gateway.
	 */
	private function resolve_erp_payment_method_id( \WC_Order $order ): int {
		$wc_method = $order->get_payment_method();
		if ( ! $wc_method ) {
			return 0;
		}

		$map = (array) get_option( 'wideaerp_payment_method_map', [] );

		return isset( $map[ $wc_method ] ) ? (int) $map[ $wc_method ] : 0;
	}

	/**
	 * Look up the IdeaERP carrier ID mapped to the WC order's first shipping method.
	 * Returns 0 when no mapping is configured.
	 */
	private function resolve_erp_carrier_id( \WC_Order $order ): int {
		$shipping_methods = $order->get_shipping_methods();
		if ( empty( $shipping_methods ) ) {
			return 0;
		}

		$first_method = reset( $shipping_methods );
		$wc_method_id = $first_method->get_method_id();

		if ( ! $wc_method_id ) {
			return 0;
		}

		$map = (array) get_option( 'wideaerp_carrier_map', [] );

		return isset( $map[ $wc_method_id ] ) ? (int) $map[ $wc_method_id ] : 0;
	}

	/**
	 * Look up the IdeaERP pricelist ID mapped to the WC order's currency.
	 * Returns 0 when no mapping is configured for this currency.
	 */
	private function resolve_erp_pricelist_id( \WC_Order $order ): int {
		$currency = $order->get_currency();
		if ( ! $currency ) {
			return 0;
		}

		$map = (array) get_option( 'wideaerp_pricelist_map', [] );

		return isset( $map[ $currency ] ) ? (int) $map[ $currency ] : 0;
	}

	/** @return array<string,mixed> */
	private function build_partner( \WC_Order $order ): array {
		$first = $order->get_billing_first_name();
		$last  = $order->get_billing_last_name();
		$name  = trim( $first . ' ' . $last )
			?: $order->get_billing_company()
			?: $order->get_billing_email();

		$partner = array_filter( [
			'name'         => $name ?: null,
			'company_name' => $order->get_billing_company() ?: null,
			'email'        => $order->get_billing_email() ?: null,
			'phone'        => $order->get_billing_phone() ?: null,
			'street'       => $order->get_billing_address_1() ?: null,
			'city'         => $order->get_billing_city() ?: null,
			'zip'          => $order->get_billing_postcode() ?: null,
			'country_code' => $order->get_billing_country() ?: null,
			'vat'          => $this->get_vat( $order ),
			'type'         => 'contact',
		], fn( $v ) => $v !== null );

		// API requires at least one of id or name — guarantee name is always present.
		if ( empty( $partner['name'] ) ) {
			$partner['name'] = sprintf( 'WC-Order-%d', $order->get_id() );
		}

		return $partner;
	}

	/** @return array<string,mixed>|null */
	private function build_invoice_partner( \WC_Order $order ): ?array {
		$first = $order->get_billing_first_name();
		$last  = $order->get_billing_last_name();
		$name  = trim( $first . ' ' . $last )
			?: $order->get_billing_company()
			?: $order->get_billing_email();

		$partner = array_filter( [
			'name'         => $name ?: null,
			'company_name' => $order->get_billing_company() ?: null,
			'email'        => $order->get_billing_email() ?: null,
			'phone'        => $order->get_billing_phone() ?: null,
			'street'       => $order->get_billing_address_1() ?: null,
			'city'         => $order->get_billing_city() ?: null,
			'zip'          => $order->get_billing_postcode() ?: null,
			'country_code' => $order->get_billing_country() ?: null,
			'vat'          => $this->get_vat( $order ),
			'type'         => 'invoice',
		], fn( $v ) => $v !== null );

		if ( empty( $partner ) ) {
			return null;
		}

		if ( empty( $partner['name'] ) ) {
			$partner['name'] = sprintf( 'WC-Order-%d', $order->get_id() );
		}

		return $partner;
	}

	/** @return array<string,mixed>|null */
	private function build_shipping_partner( \WC_Order $order ): ?array {
		$first = $order->get_shipping_first_name();
		$last  = $order->get_shipping_last_name();
		$name  = trim( $first . ' ' . $last ) ?: $order->get_shipping_company();

		if ( ! $name && ! $order->get_shipping_address_1() ) {
			return null;
		}

		$partner = array_filter( [
			'name'         => $name ?: null,
			'company_name' => $order->get_shipping_company() ?: null,
			'street'       => $order->get_shipping_address_1() ?: null,
			'city'         => $order->get_shipping_city() ?: null,
			'zip'          => $order->get_shipping_postcode() ?: null,
			'country_code' => $order->get_shipping_country() ?: null,
			'type'         => 'delivery',
		], fn( $v ) => $v !== null );

		if ( empty( $partner ) ) {
			return null;
		}

		if ( empty( $partner['name'] ) ) {
			$partner['name'] = sprintf( 'WC-Order-%d', $order->get_id() );
		}

		return $partner;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function build_order_lines( \WC_Order $order ): array {
		$lines = [];

		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$product = $item->get_product();
			$erp_id  = null;
			$erp_sku = null;
			$erp_ean = null;

			if ( $product instanceof \WC_Product ) {
				$erp_id  = (int) $product->get_meta( '_erp_product_id' );
				$erp_sku = $product->get_sku();
				$erp_ean = $product->get_meta( '_global_unique_id' ) ?: null;
			}

			$product_field = $erp_id
				? [ 'id' => $erp_id, 'name' => null ]
				: [ 'id' => null, 'name' => $item->get_name() ];

			$qty = (float) $item->get_quantity();

			// WC stores line totals as net (excl. tax). Add tax back to get the
			// gross amount and send price_include: true so IdeaERP stores the
			// same gross price the customer saw.
			$total_net   = (float) $item->get_total();
			$total_tax   = (float) $item->get_total_tax();
			$total_gross = $total_net + $total_tax;
			$price_unit  = $total_gross / max( 1.0, $qty );

			$subtotal_net   = (float) $item->get_subtotal();
			$subtotal_tax   = (float) $item->get_subtotal_tax();
			$subtotal_gross = $subtotal_net + $subtotal_tax;

			// Express any coupon discount as a percentage of the pre-discount gross price.
			$discount = 0.0;
			if ( $subtotal_gross > 0 && $total_gross < $subtotal_gross ) {
				$discount = round( ( ( $subtotal_gross - $total_gross ) / $subtotal_gross ) * 100, 2 );
			}

			$tax_rate = $this->get_item_tax_rate( $item );

			$line = [
				'product'         => $product_field,
				'name'            => $item->get_name(),
				'product_uom_qty' => $qty,
				'price_unit'      => round( $price_unit, 4 ),
				'discount'        => $discount,
				'tax'             => [ 'amount' => $tax_rate, 'price_include' => true ],
				'integration_id'  => (string) $item->get_id(),
			];

			if ( $erp_sku ) {
				$line['integration_code'] = $erp_sku;
			}

			if ( $erp_ean ) {
				$line['integration_ean'] = $erp_ean;
			}

			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Derive the effective tax rate (%) for a line item.
	 *
	 * Uses the post-discount total and its tax so the rate is consistent with
	 * the price_unit sent to IdeaERP. Falls back to the pre-discount subtotal
	 * when the total is zero (e.g. 100 % coupon). Returns 0.0 when no tax data
	 * is available.
	 */
	private function get_item_tax_rate( \WC_Order_Item_Product $item ): float {
		$taxes = $item->get_taxes();

		if ( empty( $taxes['total'] ) && empty( $taxes['subtotal'] ) ) {
			return 0.0;
		}

		$net = (float) $item->get_total();
		$tax = (float) $item->get_total_tax();

		// Fall back to pre-discount figures when total is zero.
		if ( $net <= 0 ) {
			$net = (float) $item->get_subtotal();
			$tax = (float) $item->get_subtotal_tax();
		}

		if ( $net <= 0 ) {
			return 0.0;
		}

		return round( ( $tax / $net ) * 100, 2 );
	}

	/**
	 * Try to read a VAT number stored in order meta by common plugins
	 * (e.g. WooCommerce EU VAT Number, Germanized, etc.).
	 */
	private function get_vat( \WC_Order $order ): ?string {
		$candidates = [
			'_billing_eu_vat_number',
			'_billing_vat_number',
			'_vat_number',
			'vat_number',
		];

		foreach ( $candidates as $key ) {
			$val = $order->get_meta( $key );
			if ( $val ) {
				return (string) $val;
			}
		}

		return null;
	}
}
