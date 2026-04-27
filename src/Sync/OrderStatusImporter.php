<?php

namespace WooIdeaERP\Sync;

use WooIdeaERP\Api\Client;
use WooIdeaERP\Api\Endpoints\OrdersEndpoint;
use WooIdeaERP\Helpers\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Step 5 — Scheduled order-status sync from IdeaERP back into WooCommerce.
 *
 * Mirrors the StockPriceSyncer pattern: one recurring coordinator action calls
 * OrdersEndpoint::get_statuses_for_woocommerce() (one paginated download), stores
 * the result in a transient, then dispatches staggered batch jobs. Each batch reads
 * the transient (zero ERP API calls) and updates WC orders whose ERP status no
 * longer matches the WC status — looked up via wideaerp_erp_to_wc_status_map (the
 * inverse of wideaerp_order_status_map, with a "first WC status wins" tie-break).
 *
 * Sync direction: ERP → WC only (pull). WC → ERP is OrderExporter::handle_status_update().
 */
class OrderStatusImporter {

	private const ACTION_COORDINATOR = 'wideaerp_sync_order_statuses';
	private const ACTION_BATCH       = 'wideaerp_sync_order_statuses_batch';
	private const TRANSIENT          = 'wideaerp_order_status_sync_data';

	public const OPT_INTERVAL = 'wideaerp_order_status_sync_interval'; // minutes, default 60

	private const AS_GROUP    = 'woocommerce-ideaerp';
	private const BATCH_SIZE  = 50;
	private const BATCH_DELAY = 30; // seconds between batches

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'schedule_coordinator' ] );

		add_action( self::ACTION_COORDINATOR, [ $this, 'run_coordinator' ] );
		add_action( self::ACTION_BATCH,       [ $this, 'run_batch' ] );

		add_action( 'update_option_' . self::OPT_INTERVAL, [ $this, 'reschedule' ], 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Scheduling
	// -------------------------------------------------------------------------

	public function schedule_coordinator(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( self::ACTION_COORDINATOR ) ) {
			$interval = $this->interval_seconds();
			as_schedule_recurring_action( time() + $interval, $interval, self::ACTION_COORDINATOR, [], self::AS_GROUP );
			Logger::debug( 'OrderStatusImporter: scheduled coordinator.' );
		}
	}

	public function reschedule( mixed $old_value, mixed $new_value ): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		as_unschedule_all_actions( self::ACTION_COORDINATOR, [], self::AS_GROUP );
		$interval = max( 1, absint( $new_value ) ) * MINUTE_IN_SECONDS;
		as_schedule_recurring_action( time() + $interval, $interval, self::ACTION_COORDINATOR, [], self::AS_GROUP );
		Logger::debug( sprintf( 'OrderStatusImporter: coordinator rescheduled to every %d minutes.', absint( $new_value ) ) );
	}

	// -------------------------------------------------------------------------
	// Coordinator
	// -------------------------------------------------------------------------

	public function run_coordinator(): void {
		$url   = get_option( 'wideaerp_erp_url', '' );
		$token = get_option( 'wideaerp_api_token', '' );

		if ( ! $url || ! $token ) {
			Logger::error( 'OrderStatusImporter: ERP URL or API token not configured.' );
			return;
		}

		try {
			$endpoint = new OrdersEndpoint( new Client( $url, $token ) );
			$data     = $endpoint->get_statuses_for_woocommerce();
		} catch ( \RuntimeException $e ) {
			Logger::error( 'OrderStatusImporter: failed to fetch order statuses — ' . $e->getMessage() );
			return;
		}

		if ( empty( $data ) ) {
			Logger::debug( 'OrderStatusImporter: ERP returned no woocommerce-integration orders.' );
			return;
		}

		$interval = absint( get_option( self::OPT_INTERVAL, 60 ) );
		set_transient( self::TRANSIENT, $data, $interval * 2 * MINUTE_IN_SECONDS );

		$delay = 0;
		foreach ( array_chunk( array_keys( $data ), self::BATCH_SIZE, true ) as $chunk ) {
			as_schedule_single_action( time() + $delay, self::ACTION_BATCH, [ array_values( $chunk ) ], self::AS_GROUP );
			$delay += self::BATCH_DELAY;
		}

		Logger::info( sprintf(
			'OrderStatusImporter: coordinator fetched %d ERP order statuses, dispatched batches.',
			count( $data )
		) );
	}

	// -------------------------------------------------------------------------
	// Batch — read transient, update WC orders
	// -------------------------------------------------------------------------

	/**
	 * @param int[] $wc_order_ids
	 */
	public function run_batch( array $wc_order_ids ): void {
		$data = get_transient( self::TRANSIENT );
		if ( ! is_array( $data ) ) {
			Logger::debug( 'OrderStatusImporter: transient expired, skipping batch.' );
			return;
		}

		$reverse_map = $this->build_reverse_map();
		if ( empty( $reverse_map ) ) {
			Logger::debug( 'OrderStatusImporter: empty reverse map, skipping batch (configure wideaerp_order_status_map).' );
			return;
		}

		$valid_wc_statuses = array_map(
			fn( $slug ) => str_replace( 'wc-', '', $slug ),
			array_keys( wc_get_order_statuses() )
		);

		$updated = 0;
		foreach ( $wc_order_ids as $wc_order_id ) {
			$wc_order_id = (int) $wc_order_id;
			if ( ! isset( $data[ $wc_order_id ] ) ) {
				continue;
			}

			$erp_state = (string) $data[ $wc_order_id ];
			if ( ! isset( $reverse_map[ $erp_state ] ) ) {
				continue; // ERP state not mapped to any WC status
			}

			$target_wc_status = $reverse_map[ $erp_state ];
			if ( ! in_array( $target_wc_status, $valid_wc_statuses, true ) ) {
				continue;
			}

			$order = wc_get_order( $wc_order_id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			if ( $order->get_status() === $target_wc_status ) {
				continue;
			}

			// update_status() with the source string short-circuits OrderExporter's
			// outbound PATCH (handle_status_update is on the same hook), so guard:
			// suppress the outbound PATCH while applying the inbound status.
			$order->update_meta_data( '_wideaerp_inbound_status_sync', '1' );
			$order->save_meta_data();

			$order->update_status(
				$target_wc_status,
				sprintf(
					/* translators: %s: ERP order state */
					__( 'IdeaERP: status pulled from ERP (%s).', 'woocommerce-ideaerp' ),
					$erp_state
				)
			);

			$order->delete_meta_data( '_wideaerp_inbound_status_sync' );
			$order->save_meta_data();

			$updated++;
		}

		Logger::debug( sprintf(
			'OrderStatusImporter: batch — %d of %d WC orders updated.',
			$updated,
			count( $wc_order_ids )
		) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Invert wideaerp_order_status_map. When multiple WC statuses map to the same
	 * ERP state, the first one declared wins (deterministic by option iteration).
	 *
	 * @return array<string, string>  erp_state => wc_status
	 */
	private function build_reverse_map(): array {
		$map     = (array) get_option( 'wideaerp_order_status_map', [] );
		$reverse = [];
		foreach ( $map as $wc_status => $erp_state ) {
			$erp_state = (string) $erp_state;
			if ( $erp_state === '' || isset( $reverse[ $erp_state ] ) ) {
				continue;
			}
			$reverse[ $erp_state ] = (string) $wc_status;
		}
		return $reverse;
	}

	private function interval_seconds(): int {
		return max( 1, absint( get_option( self::OPT_INTERVAL, 60 ) ) ) * MINUTE_IN_SECONDS;
	}
}
