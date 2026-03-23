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

	private const PATH = 'v2/orders';

	private Client $client;

	public function __construct( Client $client ) {
		$this->client = $client;
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
