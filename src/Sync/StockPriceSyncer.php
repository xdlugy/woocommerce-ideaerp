<?php

namespace WooIdeaERP\Sync;

use WooIdeaERP\Api\Client;
use WooIdeaERP\Api\Endpoints\ProductsEndpoint;
use WooIdeaERP\Helpers\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Step 4 — Scheduled stock and price sync from IdeaERP to WooCommerce.
 *
 * Architecture: download-once, update in staggered batches, write only on change.
 *
 * Two independent coordinator actions run on configurable intervals:
 *   wideaerp_sync_stock  — fetches all ERP products, stores qty in a transient,
 *                          dispatches stock batch jobs.
 *   wideaerp_sync_price  — same for list_price.
 *
 * Each coordinator:
 *   1. Calls ProductsEndpoint::get_all() — one full paginated ERP download.
 *   2. Extracts int-keyed float map (erp_id => value) and stores as transient.
 *   3. Chunks ERP IDs into batches and schedules them as one-time AS jobs,
 *      spaced by the configured delay to avoid bursting the WordPress DB.
 *
 * Each batch job:
 *   1. Reads the transient (zero ERP API calls).
 *   2. Finds matching WC products via a single get_posts() with meta_query IN.
 *   3. Calls save() only for products whose value actually changed.
 */
class StockPriceSyncer {

	private const ACTION_STOCK_COORDINATOR = 'wideaerp_sync_stock';
	private const ACTION_PRICE_COORDINATOR = 'wideaerp_sync_price';
	private const ACTION_STOCK_BATCH       = 'wideaerp_sync_stock_batch';
	private const ACTION_PRICE_BATCH       = 'wideaerp_sync_price_batch';

	private const TRANSIENT_STOCK = 'wideaerp_stock_sync_data';
	private const TRANSIENT_PRICE = 'wideaerp_price_sync_data';

	public const OPT_STOCK_INTERVAL = 'wideaerp_stock_sync_interval'; // minutes, default 60
	public const OPT_PRICE_INTERVAL = 'wideaerp_price_sync_interval'; // minutes, default 60
	public const OPT_BATCH_SIZE     = 'wideaerp_sync_batch_size';     // products per batch, default 100
	public const OPT_BATCH_DELAY    = 'wideaerp_sync_batch_delay';    // seconds between batches, default 30

	private const ERP_ID_META = '_erp_product_id';
	private const AS_GROUP    = 'woocommerce-ideaerp';

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'schedule_coordinators' ] );

		add_action( self::ACTION_STOCK_COORDINATOR, [ $this, 'run_stock_coordinator' ] );
		add_action( self::ACTION_PRICE_COORDINATOR, [ $this, 'run_price_coordinator' ] );
		add_action( self::ACTION_STOCK_BATCH,       [ $this, 'run_stock_batch' ] );
		add_action( self::ACTION_PRICE_BATCH,       [ $this, 'run_price_batch' ] );

		// Reschedule when the interval options change.
		add_action( 'update_option_' . self::OPT_STOCK_INTERVAL, [ $this, 'reschedule_stock' ], 10, 2 );
		add_action( 'update_option_' . self::OPT_PRICE_INTERVAL, [ $this, 'reschedule_price' ], 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Scheduling
	// -------------------------------------------------------------------------

	public function schedule_coordinators(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( self::ACTION_STOCK_COORDINATOR ) ) {
			$interval = $this->stock_interval_seconds();
			as_schedule_recurring_action( time() + $interval, $interval, self::ACTION_STOCK_COORDINATOR, [], self::AS_GROUP );
			Logger::debug( 'StockPriceSyncer: scheduled stock coordinator.' );
		}

		if ( ! as_has_scheduled_action( self::ACTION_PRICE_COORDINATOR ) ) {
			$interval = $this->price_interval_seconds();
			as_schedule_recurring_action( time() + $interval, $interval, self::ACTION_PRICE_COORDINATOR, [], self::AS_GROUP );
			Logger::debug( 'StockPriceSyncer: scheduled price coordinator.' );
		}
	}

	public function reschedule_stock( mixed $old_value, mixed $new_value ): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		as_unschedule_all_actions( self::ACTION_STOCK_COORDINATOR, [], self::AS_GROUP );
		$interval = max( 1, absint( $new_value ) ) * MINUTE_IN_SECONDS;
		as_schedule_recurring_action( time() + $interval, $interval, self::ACTION_STOCK_COORDINATOR, [], self::AS_GROUP );
		Logger::debug( sprintf( 'StockPriceSyncer: stock coordinator rescheduled to every %d minutes.', absint( $new_value ) ) );
	}

	public function reschedule_price( mixed $old_value, mixed $new_value ): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		as_unschedule_all_actions( self::ACTION_PRICE_COORDINATOR, [], self::AS_GROUP );
		$interval = max( 1, absint( $new_value ) ) * MINUTE_IN_SECONDS;
		as_schedule_recurring_action( time() + $interval, $interval, self::ACTION_PRICE_COORDINATOR, [], self::AS_GROUP );
		Logger::debug( sprintf( 'StockPriceSyncer: price coordinator rescheduled to every %d minutes.', absint( $new_value ) ) );
	}

	// -------------------------------------------------------------------------
	// Coordinators — download all ERP data once, then dispatch batches
	// -------------------------------------------------------------------------

	public function run_stock_coordinator(): void {
		$all = $this->fetch_all_erp_products();
		if ( null === $all ) {
			return;
		}

		$data = [];
		foreach ( $all as $p ) {
			$data[ $p->id ] = $p->available_qty();
		}

		$interval = absint( get_option( self::OPT_STOCK_INTERVAL, 60 ) );
		set_transient( self::TRANSIENT_STOCK, $data, $interval * 2 * MINUTE_IN_SECONDS );

		$this->dispatch_batches( self::ACTION_STOCK_BATCH, array_keys( $data ) );

		Logger::info( sprintf(
			'StockPriceSyncer: stock coordinator fetched %d ERP products, dispatched batches.',
			count( $data )
		) );
	}

	public function run_price_coordinator(): void {
		$all = $this->fetch_all_erp_products();
		if ( null === $all ) {
			return;
		}

		$data = [];
		foreach ( $all as $p ) {
			$data[ $p->id ] = (float) $p->list_price;
		}

		$interval = absint( get_option( self::OPT_PRICE_INTERVAL, 60 ) );
		set_transient( self::TRANSIENT_PRICE, $data, $interval * 2 * MINUTE_IN_SECONDS );

		$this->dispatch_batches( self::ACTION_PRICE_BATCH, array_keys( $data ) );

		Logger::info( sprintf(
			'StockPriceSyncer: price coordinator fetched %d ERP products, dispatched batches.',
			count( $data )
		) );
	}

	// -------------------------------------------------------------------------
	// Batch jobs — read transient, update WC products
	// -------------------------------------------------------------------------

	/**
	 * @param int[] $erp_ids
	 */
	public function run_stock_batch( array $erp_ids ): void {
		$data = get_transient( self::TRANSIENT_STOCK );
		if ( ! is_array( $data ) ) {
			Logger::debug( 'StockPriceSyncer: stock transient expired, skipping batch.' );
			return;
		}

		$wc_posts = $this->find_wc_posts_by_erp_ids( $erp_ids );
		$updated  = 0;

		foreach ( $wc_posts as $wc_id ) {
			$erp_id = (int) get_post_meta( $wc_id, self::ERP_ID_META, true );
			if ( ! array_key_exists( $erp_id, $data ) ) {
				continue;
			}

			$new_qty = (float) $data[ $erp_id ];
			$wc      = wc_get_product( $wc_id );
			if ( ! $wc ) {
				continue;
			}

			if ( (float) $wc->get_stock_quantity() === $new_qty ) {
				continue;
			}

			$wc->set_manage_stock( true );
			$wc->set_stock_quantity( $new_qty );
			$wc->set_stock_status( $new_qty > 0 ? 'instock' : 'outofstock' );
			$wc->save();
			$updated++;
		}

		Logger::debug( sprintf(
			'StockPriceSyncer: stock batch — %d of %d WC products updated.',
			$updated,
			count( $wc_posts )
		) );
	}

	/**
	 * @param int[] $erp_ids
	 */
	public function run_price_batch( array $erp_ids ): void {
		$data = get_transient( self::TRANSIENT_PRICE );
		if ( ! is_array( $data ) ) {
			Logger::debug( 'StockPriceSyncer: price transient expired, skipping batch.' );
			return;
		}

		$wc_posts = $this->find_wc_posts_by_erp_ids( $erp_ids );
		$updated  = 0;

		foreach ( $wc_posts as $wc_id ) {
			$erp_id = (int) get_post_meta( $wc_id, self::ERP_ID_META, true );
			if ( ! array_key_exists( $erp_id, $data ) ) {
				continue;
			}

			$new_price = (string) $data[ $erp_id ];
			$wc        = wc_get_product( $wc_id );
			if ( ! $wc ) {
				continue;
			}

			if ( $wc->get_regular_price() === $new_price ) {
				continue;
			}

			$wc->set_regular_price( $new_price );
			$wc->save();
			$updated++;
		}

		Logger::debug( sprintf(
			'StockPriceSyncer: price batch — %d of %d WC products updated.',
			$updated,
			count( $wc_posts )
		) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * @return \WooIdeaERP\Api\DTO\ErpProduct[]|null  null on error
	 */
	private function fetch_all_erp_products(): ?array {
		$url   = get_option( 'wideaerp_erp_url', '' );
		$token = get_option( 'wideaerp_api_token', '' );

		if ( ! $url || ! $token ) {
			Logger::error( 'StockPriceSyncer: ERP URL or API token not configured.' );
			return null;
		}

		try {
			return ( new ProductsEndpoint( new Client( $url, $token ) ) )->get_all();
		} catch ( \RuntimeException $e ) {
			Logger::error( 'StockPriceSyncer: failed to fetch ERP products — ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * @param int[] $erp_ids
	 */
	private function dispatch_batches( string $action, array $erp_ids ): void {
		$batch_size  = max( 1, absint( get_option( self::OPT_BATCH_SIZE, 100 ) ) );
		$batch_delay = max( 0, absint( get_option( self::OPT_BATCH_DELAY, 30 ) ) );
		$delay       = 0;

		foreach ( array_chunk( $erp_ids, $batch_size ) as $chunk ) {
			as_schedule_single_action( time() + $delay, $action, [ $chunk ], self::AS_GROUP );
			$delay += $batch_delay;
		}
	}

	/**
	 * @param  int[] $erp_ids
	 * @return int[]
	 */
	private function find_wc_posts_by_erp_ids( array $erp_ids ): array {
		if ( empty( $erp_ids ) ) {
			return [];
		}

		return get_posts( [
			'post_type'      => [ 'product', 'product_variation' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [ [
				'key'     => self::ERP_ID_META,
				'value'   => $erp_ids,
				'compare' => 'IN',
			] ],
		] );
	}

	private function stock_interval_seconds(): int {
		return max( 1, absint( get_option( self::OPT_STOCK_INTERVAL, 60 ) ) ) * MINUTE_IN_SECONDS;
	}

	private function price_interval_seconds(): int {
		return max( 1, absint( get_option( self::OPT_PRICE_INTERVAL, 60 ) ) ) * MINUTE_IN_SECONDS;
	}
}
