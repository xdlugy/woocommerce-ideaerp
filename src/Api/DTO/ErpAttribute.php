<?php

namespace WooIdeaERP\Api\DTO;

defined( 'ABSPATH' ) || exit;

class ErpAttribute {

	public function __construct(
		public readonly string $name,
		/** @var string[] */
		public readonly array  $values,
	) {}

	public static function from_array( array $data ): self {
		// The IdeaERP API returns a single "value" string per variant attribute,
		// not a "values" array. We normalise it into a one-element array so the
		// rest of the codebase can always iterate over $attr->values uniformly.
		$raw_value = $data['value'] ?? null;
		$values    = ( $raw_value !== null && $raw_value !== '' )
			? [ (string) $raw_value ]
			: [];

		return new self(
			name:   (string) ( $data['name'] ?? '' ),
			values: $values,
		);
	}
}
