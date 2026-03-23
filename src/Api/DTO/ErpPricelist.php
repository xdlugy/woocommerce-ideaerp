<?php

namespace WooIdeaERP\Api\DTO;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable DTO for a single IdeaERP pricelist record.
 */
class ErpPricelist {

	public readonly int    $id;
	public readonly string $name;
	public readonly string $currency;
	public readonly int    $currency_id;
	public readonly bool   $active;

	private function __construct( int $id, string $name, string $currency, int $currency_id, bool $active ) {
		$this->id          = $id;
		$this->name        = $name;
		$this->currency    = $currency;
		$this->currency_id = $currency_id;
		$this->active      = $active;
	}

	/** @param array<string,mixed> $data */
	public static function from_array( array $data ): self {
		return new self(
			(int) ( $data['id'] ?? 0 ),
			(string) ( $data['name'] ?? '' ),
			(string) ( $data['currency'] ?? '' ),
			(int) ( $data['currency_id'] ?? 0 ),
			(bool) ( $data['active'] ?? true )
		);
	}
}
