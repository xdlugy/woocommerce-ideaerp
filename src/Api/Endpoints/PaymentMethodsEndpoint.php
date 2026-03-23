<?php

namespace WooIdeaERP\Api\Endpoints;

use WooIdeaERP\Api\Client;
use WooIdeaERP\Api\DTO\ErpPaymentMethod;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps GET /v2/payment_methods — fetches all active IdeaERP payment methods.
 */
class PaymentMethodsEndpoint {

	private const PATH  = 'v2/payment_methods';
	private const LIMIT = 100;

	private Client $client;

	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Fetch every active payment method, handling pagination automatically.
	 *
	 * @return ErpPaymentMethod[]
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
			$items = $data['payment_methods'] ?? [];

			foreach ( $items as $row ) {
				$results[] = ErpPaymentMethod::from_array( $row );
			}

			$total  = (int) ( $data['total_count'] ?? count( $results ) );
			$offset += self::LIMIT;
		} while ( count( $results ) < $total );

		return $results;
	}
}
