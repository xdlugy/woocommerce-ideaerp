<?php

namespace WooIdeaERP\Api\DTO;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable DTO representing a sale order returned by the IdeaERP API.
 */
class ErpOrder {

	public readonly int    $id;
	public readonly string $name;
	public readonly string $status;
	public readonly ?string $integration_id;
	public readonly ?string $integration_type;
	public readonly ?int    $integration_config;

	private function __construct(
		int $id,
		string $name,
		string $status,
		?string $integration_id,
		?string $integration_type,
		?int $integration_config
	) {
		$this->id                 = $id;
		$this->name               = $name;
		$this->status             = $status;
		$this->integration_id     = $integration_id;
		$this->integration_type   = $integration_type;
		$this->integration_config = $integration_config;
	}

	/** @param array<string,mixed> $data */
	public static function from_array( array $data ): self {
		return new self(
			(int) ( $data['id'] ?? 0 ),
			(string) ( $data['name'] ?? '' ),
			(string) ( $data['status'] ?? '' ),
			isset( $data['integration_id'] ) ? (string) $data['integration_id'] : null,
			isset( $data['integration_type'] ) ? (string) $data['integration_type'] : null,
			isset( $data['integration_config'] ) ? (int) $data['integration_config'] : null
		);
	}
}
