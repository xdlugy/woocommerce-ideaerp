<?php

namespace WooIdeaERP\Api\DTO;

defined( 'ABSPATH' ) || exit;

class ErpStock {

	public function __construct(
		public readonly float  $quantity,
		public readonly float  $reserved_quantity,
		public readonly string $name,
		public readonly string $complete_name,
	) {}

	public static function from_array( array $data ): self {
		return new self(
			quantity:          (float) ( $data['quantity'] ?? 0.0 ),
			reserved_quantity: (float) ( $data['reserved_quantity'] ?? 0.0 ),
			name:              (string) ( $data['name'] ?? '' ),
			complete_name:     (string) ( $data['complete_name'] ?? '' ),
		);
	}
}
