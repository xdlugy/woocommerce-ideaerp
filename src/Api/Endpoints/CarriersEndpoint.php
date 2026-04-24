<?php

namespace WooIdeaERP\Api\Endpoints;

use WooIdeaERP\Api\Client;
use WooIdeaERP\Api\DTO\ErpCarrier;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps GET /v2/shipment/shipment_carriers — fetches all active IdeaERP carriers.
 */
class CarriersEndpoint {

	private const PATH  = 'v2/shipment/shipment_carriers';
	private const LIMIT = 100;

	private Client $client;

	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Fetch every active carrier, handling pagination automatically.
	 *
	 * @return ErpCarrier[]
	 * @throws \RuntimeException On API error.
	 */
	public function get_all(): array {
		$results = [];
		$offset  = 0;

		do {
			$data  = $this->client->get( self::PATH, [
				'limit'  => self::LIMIT,
				'offset' => $offset,
				'active' => 'true',
			] );
			$items = $data['shipment_carriers'] ?? [];
			$count = count( $items );

			if ( $count === 0 ) {
				break;
			}

			foreach ( $items as $row ) {
				$results[] = ErpCarrier::from_array( $row );
			}

			$total  = (int) ( $data['total_count'] ?? count( $results ) );
			$offset += self::LIMIT;

			if ( $count < self::LIMIT ) {
				break;
			}
		} while ( count( $results ) < $total );

		return $results;
	}
}
