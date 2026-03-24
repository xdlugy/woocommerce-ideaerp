<?php

namespace WooIdeaERP\Api\DTO;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable DTO representing an invoice returned by the IdeaERP API.
 *
 * Maps the AccountMove / invoice resource from GET /v2/invoices.
 */
class ErpInvoice {

	public readonly int     $id;
	public readonly string  $name;
	public readonly string  $state;
	public readonly string  $move_type;
	public readonly ?string $invoice_date;
	public readonly ?string $invoice_date_due;
	public readonly float   $amount_total;
	public readonly float   $amount_residual;
	public readonly string  $currency;
	public readonly ?int    $order_id;

	private function __construct(
		int $id,
		string $name,
		string $state,
		string $move_type,
		?string $invoice_date,
		?string $invoice_date_due,
		float $amount_total,
		float $amount_residual,
		string $currency,
		?int $order_id
	) {
		$this->id               = $id;
		$this->name             = $name;
		$this->state            = $state;
		$this->move_type        = $move_type;
		$this->invoice_date     = $invoice_date;
		$this->invoice_date_due = $invoice_date_due;
		$this->amount_total     = $amount_total;
		$this->amount_residual  = $amount_residual;
		$this->currency         = $currency;
		$this->order_id         = $order_id;
	}

	/** @param array<string,mixed> $data */
	public static function from_array( array $data ): self {
		$order_id = null;
		if ( ! empty( $data['order_id'] ) ) {
			$order_id = is_array( $data['order_id'] )
				? (int) ( $data['order_id']['id'] ?? 0 )
				: (int) $data['order_id'];
		}

		// The API uses 'type' for the move type field (e.g. 'out_invoice').
		$move_type = (string) ( $data['type'] ?? $data['move_type'] ?? '' );

		// Currency is returned as an object { id, name } under the key 'currency'.
		$currency = '';
		if ( isset( $data['currency']['name'] ) ) {
			$currency = (string) $data['currency']['name'];
		} elseif ( isset( $data['currency_id']['name'] ) ) {
			$currency = (string) $data['currency_id']['name'];
		} elseif ( isset( $data['currency'] ) && is_string( $data['currency'] ) ) {
			$currency = $data['currency'];
		}

		return new self(
			(int) ( $data['id'] ?? 0 ),
			(string) ( $data['name'] ?? '' ),
			(string) ( $data['state'] ?? '' ),
			$move_type,
			isset( $data['invoice_date'] ) && $data['invoice_date'] ? (string) $data['invoice_date'] : null,
			isset( $data['invoice_date_due'] ) && $data['invoice_date_due'] ? (string) $data['invoice_date_due'] : null,
			(float) ( $data['amount_total'] ?? 0 ),
			(float) ( $data['amount_residual'] ?? 0 ),
			$currency,
			$order_id
		);
	}

	/**
	 * Returns the URL to download the PDF for this invoice.
	 * The endpoint requires Bearer authentication, so this URL is meant
	 * to be proxied through the plugin's AJAX handler.
	 */
	public function pdf_path(): string {
		return sprintf( 'v2/invoices/%d/get_pdf', $this->id );
	}

	/**
	 * Whether this invoice is a customer invoice (not a credit note).
	 */
	public function is_invoice(): bool {
		return in_array( $this->move_type, [ 'out_invoice', 'in_invoice' ], true );
	}
}
