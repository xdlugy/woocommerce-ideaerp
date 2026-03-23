<?php

namespace WooIdeaERP\Api\DTO;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable DTO for a single IdeaERP shipment carrier record.
 */
class ErpCarrier {

	public readonly int     $id;
	public readonly string  $name;
	public readonly ?string $logistic_company;
	public readonly bool    $active;
	public readonly bool    $external_integration;

	private function __construct(
		int $id,
		string $name,
		?string $logistic_company,
		bool $active,
		bool $external_integration
	) {
		$this->id                   = $id;
		$this->name                 = $name;
		$this->logistic_company     = $logistic_company;
		$this->active               = $active;
		$this->external_integration = $external_integration;
	}

	/** @param array<string,mixed> $data */
	public static function from_array( array $data ): self {
		$lc = $data['logistic_company'] ?? null;

		return new self(
			(int) ( $data['id'] ?? 0 ),
			(string) ( $data['name'] ?? '' ),
			( is_string( $lc ) && $lc !== '' ) ? $lc : null,
			(bool) ( $data['active'] ?? true ),
			(bool) ( $data['external_integration'] ?? false )
		);
	}
}
