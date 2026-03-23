<?php

namespace WooIdeaERP\Api\DTO;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable DTO for a single IdeaERP payment method record.
 */
class ErpPaymentMethod {

	public readonly int    $id;
	public readonly string $name;
	public readonly bool   $is_cod;
	public readonly bool   $active;

	private function __construct( int $id, string $name, bool $is_cod, bool $active ) {
		$this->id     = $id;
		$this->name   = $name;
		$this->is_cod = $is_cod;
		$this->active = $active;
	}

	/** @param array<string,mixed> $data */
	public static function from_array( array $data ): self {
		return new self(
			(int) ( $data['id'] ?? 0 ),
			(string) ( $data['name'] ?? '' ),
			(bool) ( $data['is_cod'] ?? false ),
			(bool) ( $data['active'] ?? true )
		);
	}
}
