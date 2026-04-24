<?php

namespace WooIdeaERP\Api\Endpoints;

use WooIdeaERP\Api\Client;
use WooIdeaERP\Api\DTO\ErpInvoice;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the IdeaERP /v2/invoices resource.
 *
 * Supported operations:
 *   GET  /v2/invoices?order_id={id}   — list invoices for a sale order
 *   GET  /v2/invoices/{id}/get_pdf    — download invoice PDF (raw binary)
 */
class InvoicesEndpoint {

	private const PATH = 'v2/invoices';

	private Client $client;

	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Return all invoices linked to an IdeaERP sale order.
	 *
	 * @param  int $erp_order_id  The IdeaERP order ID stored in _erp_order_id meta.
	 * @return ErpInvoice[]
	 * @throws \RuntimeException On API error.
	 */
	public function get_by_order_id( int $erp_order_id ): array {
		$data = $this->client->get( self::PATH, [ 'order_id' => $erp_order_id ] );

		$invoices = [];

		// The API returns { count, total_count, invoices: [...] }.
		// Fall back to 'results' or a plain array for forward compatibility.
		if ( isset( $data['invoices'] ) ) {
			$items = $data['invoices'];
		} elseif ( isset( $data['results'] ) ) {
			$items = $data['results'];
		} else {
			$items = $data;
		}

		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				$invoices[] = ErpInvoice::from_array( $item );
			}
		}

		return $invoices;
	}

}
