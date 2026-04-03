<?php

namespace WooIdeaERP\Api\DTO;

defined( 'ABSPATH' ) || exit;

class ErpProduct {

	public function __construct(
		public readonly int     $id,
		public readonly int     $product_tmpl_id,
		public readonly string  $default_code,
		public readonly string  $name,
		public readonly float   $list_price,
		public readonly int     $tax_rate,
		public readonly float   $weight,
		public readonly bool    $is_bundle,
		public readonly ?string $description,
		public readonly ?string $description_sale,
		public readonly ?string $description_sale_html,
		public readonly int     $category_id,
		/** @var ErpAttribute[] */
		public readonly array   $attributes,
		/** @var ErpStock[] */
		public readonly array   $stock,
		/** @var string[] */
		public readonly array   $images,
		/** @var string[] */
		public readonly array   $tags,
		public readonly ?string $barcode,
	) {}

	public static function from_array( array $data ): self {
		$attributes = array_map(
			fn( array $a ) => ErpAttribute::from_array( $a ),
			$data['attributes'] ?? []
		);

		$stock = array_map(
			fn( array $s ) => ErpStock::from_array( $s ),
			$data['stock'] ?? []
		);

		return new self(
			id:               (int) $data['id'],
			product_tmpl_id:  (int) ( $data['product_tmpl_id'] ?? 0 ),
			default_code:     (string) ( $data['default_code'] ?? '' ),
			name:             (string) ( $data['name'] ?? '' ),
			list_price:       (float) ( $data['list_price'] ?? 0.0 ),
			tax_rate:         (int) ( $data['tax_rate'] ?? 0 ),
			weight:           (float) ( $data['weight'] ?? 0.0 ),
			is_bundle:        (bool) ( $data['is_bundle'] ?? false ),
			description:           $data['description'] ?? null,
			description_sale:      $data['description_sale'] ?? null,
			description_sale_html: $data['description_sale_html'] ?? null,
			category_id:           (int) ( $data['category_id'] ?? 0 ),
			attributes:       $attributes,
			stock:            $stock,
			images:           $data['images'] ?? [],
			tags:             $data['ideaerp_tags'] ?? [],
			barcode:          ( $b = self::extract_barcode_from_api_row( $data ) ) !== '' ? $b : null,
		);
	}

	/**
	 * Barcode from the IdeaERP product object (OpenAPI field `barcode`).
	 *
	 * @param array<string, mixed> $data
	 */
	public static function extract_barcode_from_api_row( array $data ): string {
		if ( ! array_key_exists( 'barcode', $data ) ) {
			return '';
		}

		return self::normalize_barcode_scalar( $data['barcode'] );
	}

	private static function normalize_barcode_scalar( mixed $v ): string {
		if ( $v === null ) {
			return '';
		}
		if ( is_bool( $v ) ) {
			return '';
		}
		if ( is_int( $v ) ) {
			return (string) $v;
		}
		if ( is_float( $v ) ) {
			$s = (string) $v;

			return preg_match( '/^(\d+)\.0+$/', $s, $m ) ? $m[1] : trim( $s );
		}
		if ( is_string( $v ) ) {
			return trim( $v );
		}

		return '';
	}

	/**
	 * Returns the total available quantity across all stock locations.
	 */
	public function available_qty(): float {
		$total = 0.0;
		foreach ( $this->stock as $s ) {
			$total += max( 0.0, $s->quantity - $s->reserved_quantity );
		}
		return $total;
	}

	/**
	 * Returns true when this product record carries at least one attribute value.
	 * Used only for single-record display; the actual variable/simple decision
	 * in the import flow is made by grouping on product_tmpl_id.
	 */
	public function has_attributes(): bool {
		foreach ( $this->attributes as $attr ) {
			if ( ! empty( $attr->values ) ) {
				return true;
			}
		}
		return false;
	}
}
