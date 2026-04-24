<?php

namespace WooIdeaERP\Sync;

use WooIdeaERP\Api\Client;
use WooIdeaERP\Api\Endpoints\InvoicesEndpoint;
use WooIdeaERP\Api\DTO\ErpInvoice;
use WooIdeaERP\Helpers\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Step 3 — Downloads invoices from IdeaERP and stores them on the WooCommerce order.
 *
 * Trigger strategy:
 *   1. Every order status change — if the order has been exported (_erp_order_id set)
 *      and has no invoice yet (_erp_invoice_pending = 1), attempt to fetch.
 *   2. Action Scheduler recurring job (every 10 minutes) — queries only orders
 *      flagged with _erp_invoice_pending = 1 for an efficient, index-friendly lookup.
 *
 * The _erp_invoice_pending flag:
 *   - Set to 1 by OrderExporter immediately after a successful export.
 *   - Deleted by InvoiceImporter as soon as at least one invoice is saved.
 *   - Used as the sole criterion for the scheduled query (no complex meta_query).
 *
 * Each invoice is stored as order meta:
 *   _erp_invoice_{n}_id, _erp_invoice_{n}_number, _erp_invoice_{n}_date,
 *   _erp_invoice_{n}_date_due, _erp_invoice_{n}_amount_total,
 *   _erp_invoice_{n}_currency, _erp_invoice_{n}_state, _erp_invoice_{n}_pdf_url
 *
 * Invoices are displayed on the WC order admin screen in a dedicated metabox.
 *
 * A proxy AJAX action (wideaerp_download_invoice_pdf) streams the PDF through
 * WordPress so the API token is never exposed in the browser.
 */
class InvoiceImporter {

	private const META_ERP_ORDER_ID   = '_erp_order_id';
	private const META_INVOICE_PREFIX = '_erp_invoice_';
	private const META_INVOICE_COUNT  = '_erp_invoice_count';
	public  const META_INVOICE_PENDING = '_erp_invoice_pending';

	private const SCHEDULED_ACTION    = 'wideaerp_check_pending_invoices';
	private const SCHEDULE_INTERVAL   = 10 * MINUTE_IN_SECONDS;
	private const SCHEDULED_BATCH     = 50;

	public function register_hooks(): void {
		// Fire after OrderExporter (priority 10) so _erp_order_id is already saved.
		add_action( 'woocommerce_order_status_changed', [ $this, 'handle_status_changed' ], 20, 4 );

		// Register the scheduled action handler.
		add_action( self::SCHEDULED_ACTION, [ $this, 'run_scheduled_check' ] );

		// Async per-order fetch triggered from handle_status_changed.
		add_action( 'wideaerp_fetch_invoice_for_order', [ $this, 'run_async_fetch' ] );

		// Schedule the recurring job on init (idempotent — only schedules if not already queued).
		add_action( 'init', [ $this, 'schedule_recurring_check' ] );

		// Admin order screen metabox.
		add_action( 'add_meta_boxes', [ $this, 'register_metabox' ] );

		// PDF download via admin-post.php — streams the PDF binary, no JSON wrapping.
		add_action( 'admin_post_wideaerp_download_invoice_pdf', [ $this, 'handle_download_pdf' ] );

		// Re-fetch invoices form POST via admin-post.php — redirects back to the order.
		add_action( 'admin_post_wideaerp_refetch_invoices', [ $this, 'handle_refetch_invoices' ] );
	}

	// -------------------------------------------------------------------------
	// Scheduling
	// -------------------------------------------------------------------------

	/**
	 * Register the recurring Action Scheduler job if it is not already queued.
	 * Called on 'init' so Action Scheduler functions are available.
	 */
	public function schedule_recurring_check(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( self::SCHEDULED_ACTION ) ) {
			as_schedule_recurring_action(
				time() + self::SCHEDULE_INTERVAL,
				self::SCHEDULE_INTERVAL,
				self::SCHEDULED_ACTION,
				[],
				'woocommerce-ideaerp'
			);

			Logger::debug( 'InvoiceImporter: scheduled recurring invoice check (every 10 min).' );
		}
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Fires on every order status transition.
	 * Attempts to fetch invoices for any exported order that does not have one yet.
	 */
	public function handle_status_changed( int $order_id, string $old_status, string $new_status, \WC_Order $order ): void {
		if ( ! $order->get_meta( self::META_ERP_ORDER_ID ) ) {
			return;
		}

		if ( ! $this->is_invoice_pending( $order ) ) {
			return;
		}

		// Defer the ERP roundtrip — bulk status changes would otherwise fire N
		// synchronous HTTP calls in one admin request.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'wideaerp_fetch_invoice_for_order', [ $order_id ], 'woocommerce-ideaerp' );
			return;
		}

		$this->import_for_order( $order );
	}

	public function run_async_fetch( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! $this->is_invoice_pending( $order ) ) {
			return;
		}

		$this->import_for_order( $order );
	}

	/**
	 * Scheduled job — queries all orders flagged as pending invoice and attempts
	 * to fetch their invoices from IdeaERP.
	 *
	 * Uses a single, simple meta_key lookup on _erp_invoice_pending which is
	 * index-friendly and avoids expensive OR/NOT EXISTS queries on large tables.
	 */
	public function run_scheduled_check(): void {
		$orders = wc_get_orders( [
			'limit'      => self::SCHEDULED_BATCH,
			'status'     => [ 'processing', 'on-hold', 'completed' ],
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery
				[
					'key'   => self::META_INVOICE_PENDING,
					'value' => '1',
				],
			],
		] );

		if ( empty( $orders ) ) {
			Logger::debug( 'InvoiceImporter: scheduled check — no pending orders.' );
			return;
		}

		Logger::info( sprintf(
			'InvoiceImporter: scheduled check — processing %d pending order(s).',
			count( $orders )
		) );

		foreach ( $orders as $order ) {
			$this->import_for_order( $order );
		}
	}

	// -------------------------------------------------------------------------
	// Core import logic
	// -------------------------------------------------------------------------

	/**
	 * Fetch all invoices for the given WC order from IdeaERP and store them as order meta.
	 * Clears the _erp_invoice_pending flag on success.
	 */
	public function import_for_order( \WC_Order $order ): void {
		$erp_order_id = (int) $order->get_meta( self::META_ERP_ORDER_ID );

		if ( ! $erp_order_id ) {
			Logger::debug( sprintf(
				'InvoiceImporter: order #%d has no _erp_order_id — skipping.',
				$order->get_id()
			) );
			return;
		}

		$url   = get_option( 'wideaerp_erp_url', '' );
		$token = get_option( 'wideaerp_api_token', '' );

		if ( ! $url || ! $token ) {
			Logger::error( 'InvoiceImporter: ERP URL or API token not configured.' );
			return;
		}

		try {
			$endpoint = new InvoicesEndpoint( new Client( $url, $token ) );
			$invoices = $endpoint->get_by_order_id( $erp_order_id );

			Logger::debug( sprintf(
				'InvoiceImporter: order #%d (ERP #%d) — %d invoice(s) returned.',
				$order->get_id(),
				$erp_order_id,
				count( $invoices )
			) );

			if ( empty( $invoices ) ) {
				return;
			}

			$this->save_invoices( $order, $invoices );

		} catch ( \RuntimeException $e ) {
			Logger::error( sprintf(
				'InvoiceImporter: failed to fetch invoices for WC order #%d — %s',
				$order->get_id(),
				$e->getMessage()
			) );
		}
	}

	// -------------------------------------------------------------------------
	// Meta persistence
	// -------------------------------------------------------------------------

	/**
	 * Persist invoice data as order meta, add order notes, and clear the pending flag.
	 *
	 * @param \WC_Order    $order
	 * @param ErpInvoice[] $invoices
	 */
	private function save_invoices( \WC_Order $order, array $invoices ): void {
		$existing_count = (int) $order->get_meta( self::META_INVOICE_COUNT );
		$n              = 0;

		foreach ( $invoices as $invoice ) {
			$n++;
			$prefix = self::META_INVOICE_PREFIX . $n . '_';

			$order->update_meta_data( $prefix . 'id',           $invoice->id );
			$order->update_meta_data( $prefix . 'number',       $invoice->name );
			$order->update_meta_data( $prefix . 'date',         $invoice->invoice_date ?? '' );
			$order->update_meta_data( $prefix . 'date_due',     $invoice->invoice_date_due ?? '' );
			$order->update_meta_data( $prefix . 'amount_total', $invoice->amount_total );
			$order->update_meta_data( $prefix . 'currency',     $invoice->currency );
			$order->update_meta_data( $prefix . 'state',        $invoice->state );

			Logger::debug( sprintf(
				'InvoiceImporter: saved invoice #%d (%s) to WC order #%d.',
				$invoice->id,
				$invoice->name,
				$order->get_id()
			) );

			// Only add an order note for new invoices (not on re-fetch).
			if ( $n > $existing_count ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: invoice number from IdeaERP */
						__( 'IdeaERP: invoice %s received.', 'woocommerce-ideaerp' ),
						$invoice->name
					)
				);
			}
		}

		// Drop any stale slots from a previous fetch that returned more invoices.
		for ( $stale = $n + 1; $stale <= $existing_count; $stale++ ) {
			$stale_prefix = self::META_INVOICE_PREFIX . $stale . '_';
			foreach ( [ 'id', 'number', 'date', 'date_due', 'amount_total', 'currency', 'state', 'pdf_url' ] as $field ) {
				$order->delete_meta_data( $stale_prefix . $field );
			}
		}

		$order->update_meta_data( self::META_INVOICE_COUNT, $n );

		// Invoice successfully downloaded — remove the pending flag so this order
		// is excluded from future scheduled checks.
		$order->delete_meta_data( self::META_INVOICE_PENDING );

		$order->save_meta_data();

		Logger::info( sprintf(
			'InvoiceImporter: %d invoice(s) saved to WC order #%d. Pending flag cleared.',
			$n,
			$order->get_id()
		) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns true when the order still needs an invoice fetched from ERP.
	 * Based on the _erp_invoice_pending flag set by OrderExporter on export.
	 */
	private function is_invoice_pending( \WC_Order $order ): bool {
		return (string) $order->get_meta( self::META_INVOICE_PENDING ) === '1';
	}

	// -------------------------------------------------------------------------
	// Admin metabox
	// -------------------------------------------------------------------------

	public function register_metabox(): void {
		$screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];

		foreach ( $screens as $screen ) {
			add_meta_box(
				'wideaerp_invoices',
				__( 'IdeaERP Invoices', 'woocommerce-ideaerp' ),
				[ $this, 'render_metabox' ],
				$screen,
				'side',
				'default'
			);
		}
	}

	public function render_metabox( \WP_Post|\WC_Order $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order
			? $post_or_order
			: wc_get_order( $post_or_order->ID );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$erp_order_id = (int) $order->get_meta( self::META_ERP_ORDER_ID );
		$count        = (int) $order->get_meta( self::META_INVOICE_COUNT );
		$pending      = $this->is_invoice_pending( $order );

		// Show a success/error notice when returning from a re-fetch redirect.
		$refetch_status = sanitize_key( $_GET['wideaerp_refetch'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div id="wideaerp-invoices-wrap">

			<?php if ( $refetch_status === 'done' ) : ?>
				<p style="color:green;">
					<?php esc_html_e( 'Invoices refreshed.', 'woocommerce-ideaerp' ); ?>
				</p>
			<?php elseif ( $refetch_status === 'error' ) : ?>
				<p style="color:red;">
					<?php esc_html_e( 'Could not fetch invoices. Check the debug log.', 'woocommerce-ideaerp' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( ! $erp_order_id ) : ?>
				<p style="color:#888;font-style:italic;">
					<?php esc_html_e( 'This order has not been exported to IdeaERP yet.', 'woocommerce-ideaerp' ); ?>
				</p>
			<?php elseif ( $count === 0 ) : ?>
				<p style="color:#888;font-style:italic;">
					<?php if ( $pending ) : ?>
						<?php esc_html_e( 'Waiting for invoice from IdeaERP…', 'woocommerce-ideaerp' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'No invoices found for this order.', 'woocommerce-ideaerp' ); ?>
					<?php endif; ?>
				</p>
			<?php else : ?>
				<table style="width:100%;border-collapse:collapse;font-size:12px;">
					<?php for ( $n = 1; $n <= $count; $n++ ) : ?>
						<?php
						$prefix     = self::META_INVOICE_PREFIX . $n . '_';
						$inv_id     = (int)    $order->get_meta( $prefix . 'id' );
						$inv_number = (string) $order->get_meta( $prefix . 'number' );
						$inv_date   = (string) $order->get_meta( $prefix . 'date' );
						$inv_total  = (float)  $order->get_meta( $prefix . 'amount_total' );
						$inv_curr   = (string) $order->get_meta( $prefix . 'currency' );
						$inv_state  = (string) $order->get_meta( $prefix . 'state' );

						$pdf_url = $inv_id ? wp_nonce_url(
							add_query_arg( [
								'action'     => 'wideaerp_download_invoice_pdf',
								'invoice_id' => $inv_id,
								'order_id'   => $order->get_id(),
							], admin_url( 'admin-post.php' ) ),
							'wideaerp_pdf_' . $inv_id
						) : '';
						?>
						<tr style="border-bottom:1px solid #eee;">
							<td style="padding:6px 4px;">
								<strong><?php echo esc_html( $inv_number ); ?></strong><br />
								<span style="color:#888;"><?php echo esc_html( $inv_date ); ?></span>
							</td>
							<td style="padding:6px 4px;text-align:right;">
								<?php echo esc_html( number_format( $inv_total, 2 ) . ' ' . $inv_curr ); ?><br />
								<span style="color:#888;font-size:11px;"><?php echo esc_html( $inv_state ); ?></span>
							</td>
							<td style="padding:6px 4px;text-align:right;">
								<?php if ( $pdf_url ) : ?>
									<a href="<?php echo esc_url( $pdf_url ); ?>" target="_blank"
									   class="button button-small"
									   title="<?php esc_attr_e( 'Download PDF', 'woocommerce-ideaerp' ); ?>">
										<?php esc_html_e( 'PDF', 'woocommerce-ideaerp' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endfor; ?>
				</table>
			<?php endif; ?>

			<?php if ( $erp_order_id ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				      style="margin-top:10px;">
					<input type="hidden" name="action"   value="wideaerp_refetch_invoices" />
					<input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>" />
					<?php wp_nonce_field( 'wideaerp_refetch_' . $order->get_id() ); ?>
					<button type="submit" class="button button-small">
						<?php esc_html_e( 'Re-fetch invoices', 'woocommerce-ideaerp' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * Proxy the PDF download through WordPress so the API token is never
	 * exposed in the browser's address bar or network tab.
	 */
	public function handle_download_pdf(): void {
		$invoice_id = absint( $_GET['invoice_id'] ?? 0 );
		$order_id   = absint( $_GET['order_id'] ?? 0 );

		// Verify per-invoice nonce (set by wp_nonce_url in the metabox).
		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ),
			'wideaerp_pdf_' . $invoice_id
		) ) {
			status_header( 403 );
			die( 'Invalid or expired security token. Please reload the order page and try again.' );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			status_header( 403 );
			die( esc_html__( 'Insufficient permissions.', 'woocommerce-ideaerp' ) );
		}

		if ( ! $invoice_id || ! $order_id ) {
			status_header( 400 );
			die( 'Invalid request.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			status_header( 404 );
			die( 'Order not found.' );
		}

		// Confirm the requested invoice is actually attached to this order. The nonce
		// alone only verifies the admin's session — it doesn't bind invoice → order.
		$count           = (int) $order->get_meta( self::META_INVOICE_COUNT );
		$invoice_belongs = false;
		for ( $n = 1; $n <= $count; $n++ ) {
			if ( (int) $order->get_meta( self::META_INVOICE_PREFIX . $n . '_id' ) === $invoice_id ) {
				$invoice_belongs = true;
				break;
			}
		}
		if ( ! $invoice_belongs ) {
			status_header( 403 );
			die( 'Invoice does not belong to this order.' );
		}

		$url   = get_option( 'wideaerp_erp_url', '' );
		$token = get_option( 'wideaerp_api_token', '' );

		if ( ! $url || ! $token ) {
			status_header( 500 );
			die( 'ERP not configured.' );
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
			status_header( 500 );
			die( esc_html( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			status_header( (int) $code );
			/* translators: %d: HTTP status code */
			die( sprintf( esc_html__( 'ERP returned HTTP %d.', 'woocommerce-ideaerp' ), $code ) );
		}

		// The ERP returns JSON: { "invoice_data_base64": "...", "invoice_data_url": "..." }
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		$b64  = $json['invoice_data_base64'] ?? '';

		if ( ! $b64 ) {
			status_header( 502 );
			die( 'ERP returned no PDF data.' );
		}

		$body = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( $body === false ) {
			status_header( 502 );
			die( 'Failed to decode PDF data from ERP.' );
		}

		// We already know which slot the invoice occupies from the ownership check above.
		$filename = 'invoice-' . $invoice_id . '.pdf';
		$raw      = $order->get_meta( self::META_INVOICE_PREFIX . $n . '_number' );
		if ( $raw ) {
			$filename = sanitize_file_name( $raw ) . '.pdf';
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		header( 'Cache-Control: private, no-cache' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $body;
		exit;
	}

	/**
	 * Handles the Re-fetch invoices form POST from the metabox.
	 * Fetches invoices from ERP then redirects back to the order edit screen.
	 * Bypasses the pending flag so admins can always force a refresh.
	 */
	public function handle_refetch_invoices(): void {
		$order_id = absint( $_POST['order_id'] ?? 0 );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'woocommerce-ideaerp' ), 403 );
		}

		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ),
			'wideaerp_refetch_' . $order_id
		) ) {
			wp_die( esc_html__( 'Invalid security token.', 'woocommerce-ideaerp' ), 403 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'woocommerce-ideaerp' ), 404 );
		}

		$status = 'error';
		try {
			$this->import_for_order( $order );
			$status = 'done';
		} catch ( \Throwable $e ) {
			Logger::error( 'handle_refetch_invoices: ' . $e->getMessage() );
		}

		// Redirect back to the order edit page with a status flag.
		$redirect = add_query_arg(
			'wideaerp_refetch',
			$status,
			get_edit_post_link( $order_id, 'url' ) ?: admin_url( 'post.php?post=' . $order_id . '&action=edit' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
