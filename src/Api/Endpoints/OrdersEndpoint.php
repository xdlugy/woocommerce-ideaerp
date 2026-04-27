<?php

namespace WooIdeaERP\Api\Endpoints;

use WooIdeaERP\Api\Client;
use WooIdeaERP\Api\DTO\ErpOrder;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the IdeaERP /v2/orders resource.
 *
 * Supported operations:
 *   POST   /v2/orders          — create a new sale order
 *   PATCH  /v2/orders/{id}     — update / cancel an existing order
 */
class OrdersEndpoint {

	private const PATH        = 'v2/orders';
	private const PAGE_LIMIT  = 100;

	private Client $client;

	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Fetch sale orders that originated from this WooCommerce store, returning the
	 * pair (integration_id, status) for each. integration_id is the WC order ID we
	 * sent on POST /v2/orders (OrderExporter::build_payload sets it as a string).
	 *
	 * @return array<int, string>  WC order ID => ERP status
	 */
	public function get_statuses_for_woocommerce(): array {
		$out    = [];
		$offset = 0;

		do {
			$data  = $this->client->get( self::PATH, [
				'limit'            => self::PAGE_LIMIT,
				'offset'           => $offset,
				'integration_type' => 'woocommerce',
			] );
			$items = $data['orders'] ?? $data['results'] ?? [];
			$count = count( $items );

			if ( $count === 0 ) {
				break;
			}

			foreach ( $items as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$integration_id = $row['integration_id'] ?? null;
				$status         = $row['status'] ?? '';
				if ( $integration_id === null || $integration_id === '' || ! is_numeric( $integration_id ) ) {
					continue;
				}
				$out[ (int) $integration_id ] = (string) $status;
			}

			$total   = (int) ( $data['total_count'] ?? count( $out ) );
			$offset += self::PAGE_LIMIT;

			if ( $count < self::PAGE_LIMIT ) {
				break;
			}
		} while ( count( $out ) < $total );

		return $out;
	}

	/**
	 * Create a new sale order in IdeaERP.
	 *
	 * @param  array<string,mixed> $payload  CreateSaleOrder-shaped array.
	 * @return ErpOrder
	 * @throws \RuntimeException On API error.
	 */
	public function create( array $payload ): ErpOrder {
		$data = $this->client->post( self::PATH, $payload );
		return ErpOrder::from_array( $data );
	}

	/**
	 * Partially update an existing sale order (e.g. cancel it).
	 *
	 * @param  int                 $erp_order_id  IdeaERP order ID.
	 * @param  array<string,mixed> $payload        Fields to update.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On API error.
	 */
	public function update( int $erp_order_id, array $payload ): array {
		return $this->client->patch( self::PATH . '/' . $erp_order_id, $payload );
	}
}
