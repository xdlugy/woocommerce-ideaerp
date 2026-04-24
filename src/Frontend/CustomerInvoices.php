<?php

namespace WooIdeaERP\Frontend;

use WooIdeaERP\Helpers\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes ERP invoice PDFs to customers on the My Account → Orders screen.
 *
 * Security model:
 *   - PDF endpoint requires the user to be logged in.
 *   - Order ownership is verified: $order->get_customer_id() must match get_current_user_id().
 *   - Every download link carries a per-invoice wp_nonce so URLs cannot be guessed or replayed.
 *   - The Bearer API token is never sent to the browser — the proxy reads it server-side only.
 */
class CustomerInvoices {

	private const META_INVOICE_PREFIX = '_erp_invoice_';
	private const META_INVOICE_COUNT  = '_erp_invoice_count';
	private const PDF_ACTION          = 'wideaerp_customer_invoice_pdf';

	public function register(): void {
		// Inject download buttons into the order actions column on /my-account/orders/.
		add_filter( 'woocommerce_my_account_my_orders_actions', [ $this, 'add_invoice_actions' ], 10, 2 );

		// Proxy endpoint — logged-in users only (admin-post.php does not support nopriv here).
		add_action( 'admin_post_' . self::PDF_ACTION, [ $this, 'handle_customer_pdf_download' ] );
	}

	// -------------------------------------------------------------------------
	// Filter: inject invoice buttons per order row
	// -------------------------------------------------------------------------

	/**
	 * @param array<string, array{url: string, name: string, class?: string}> $actions
	 * @param \WC_Order $order
	 * @return array<string, array{url: string, name: string, class?: string}>
	 */
	public function add_invoice_actions( array $actions, \WC_Order $order ): array {
		$count = (int) $order->get_meta( self::META_INVOICE_COUNT );

		if ( $count < 1 ) {
			return $actions;
		}

		for ( $n = 1; $n <= $count; $n++ ) {
			$prefix     = self::META_INVOICE_PREFIX . $n . '_';
			$invoice_id = (int) $order->get_meta( $prefix . 'id' );

			if ( ! $invoice_id ) {
				continue;
			}

			$invoice_number = (string) $order->get_meta( $prefix . 'number' );
			$label = $count === 1
				/* translators: download button label on My Account orders page */
				? __( 'Invoice', 'woocommerce-ideaerp' )
				/* translators: %s: invoice number, shown when an order has multiple invoices */
				: sprintf( __( 'Invoice %s', 'woocommerce-ideaerp' ), $invoice_number );

			$url = wp_nonce_url(
				add_query_arg(
					[
						'action'     => self::PDF_ACTION,
						'invoice_id' => $invoice_id,
						'order_id'   => $order->get_id(),
					],
					admin_url( 'admin-post.php' )
				),
				'wideaerp_customer_pdf_' . $invoice_id
			);

			$actions[ 'wideaerp_invoice_' . $n ] = [
				'url'  => $url,
				'name' => $label,
			];
		}

		return $actions;
	}

	// -------------------------------------------------------------------------
	// PDF proxy — customer-facing, ownership-gated
	// -------------------------------------------------------------------------

	public function handle_customer_pdf_download(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
			exit;
		}

		$invoice_id = absint( $_GET['invoice_id'] ?? 0 );
		$order_id   = absint( $_GET['order_id'] ?? 0 );

		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ),
			'wideaerp_customer_pdf_' . $invoice_id
		) ) {
			status_header( 403 );
			die( esc_html__( 'Security check failed. Please reload the page and try again.', 'woocommerce-ideaerp' ) );
		}

		if ( ! $invoice_id || ! $order_id ) {
			status_header( 400 );
			die( esc_html__( 'Invalid request.', 'woocommerce-ideaerp' ) );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			status_header( 404 );
			die( esc_html__( 'Order not found.', 'woocommerce-ideaerp' ) );
		}

		// Ownership check: admins may also use this endpoint.
		$user_id = get_current_user_id();
		if ( $order->get_customer_id() !== $user_id && ! current_user_can( 'manage_woocommerce' ) ) {
			status_header( 403 );
			die( esc_html__( 'You do not have permission to download this invoice.', 'woocommerce-ideaerp' ) );
		}

		// Verify the invoice actually belongs to this order (guard against ID spoofing).
		$count = (int) $order->get_meta( self::META_INVOICE_COUNT );
		$found = false;
		$filename = 'invoice-' . $invoice_id . '.pdf';

		for ( $n = 1; $n <= $count; $n++ ) {
			if ( (int) $order->get_meta( self::META_INVOICE_PREFIX . $n . '_id' ) === $invoice_id ) {
				$raw = $order->get_meta( self::META_INVOICE_PREFIX . $n . '_number' );
				if ( $raw ) {
					$filename = sanitize_file_name( $raw ) . '.pdf';
				}
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			status_header( 404 );
			die( esc_html__( 'Invoice not found on this order.', 'woocommerce-ideaerp' ) );
		}

		$url   = get_option( 'wideaerp_erp_url', '' );
		$token = get_option( 'wideaerp_api_token', '' );

		if ( ! $url || ! $token ) {
			status_header( 500 );
			die( esc_html__( 'ERP integration is not configured.', 'woocommerce-ideaerp' ) );
		}

		$pdf_url  = rtrim( $url, '/' ) . '/v2/invoices/' . $invoice_id . '/get_pdf';
		$response = wp_remote_get( $pdf_url, [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			],
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) {
			Logger::error( sprintf(
				'CustomerInvoices: PDF fetch failed for invoice #%d (order #%d) — %s',
				$invoice_id,
				$order_id,
				$response->get_error_message()
			) );
			status_header( 500 );
			die( esc_html( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			status_header( (int) $code );
			/* translators: %d: HTTP status code returned by the ERP API */
			die( sprintf( esc_html__( 'ERP returned HTTP %d.', 'woocommerce-ideaerp' ), $code ) );
		}

		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		$b64  = $json['invoice_data_base64'] ?? '';

		if ( ! $b64 ) {
			status_header( 502 );
			die( esc_html__( 'ERP returned no PDF data.', 'woocommerce-ideaerp' ) );
		}

		$body = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( $body === false ) {
			status_header( 502 );
			die( esc_html__( 'Failed to decode PDF data from ERP.', 'woocommerce-ideaerp' ) );
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		header( 'Cache-Control: private, no-cache' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $body;
		exit;
	}
}
