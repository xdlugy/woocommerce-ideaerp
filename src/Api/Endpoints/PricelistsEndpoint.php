<?php

namespace WooIdeaERP\Api\Endpoints;

use WooIdeaERP\Api\Client;
use WooIdeaERP\Api\DTO\ErpPricelist;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps GET /v2/pricelists — fetches all active IdeaERP pricelists.
 */
class PricelistsEndpoint {

	private const PATH  = 'v2/pricelists';
	private const LIMIT = 100;

	private Client $client;

	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Fetch every active pricelist, handling pagination automatically.
	 *
	 * @return ErpPricelist[]
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
			$items = $data['pricelists'] ?? [];

			foreach ( $items as $row ) {
				$results[] = ErpPricelist::from_array( $row );
			}

			$total  = (int) ( $data['total_count'] ?? count( $results ) );
			$offset += self::LIMIT;
		} while ( count( $results ) < $total );

		return $results;
	}
}
