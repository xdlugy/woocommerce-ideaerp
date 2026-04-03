<?php

namespace WooIdeaERP\Sync;

use WooIdeaERP\Api\DTO\ErpProduct;
use WooIdeaERP\Api\Endpoints\ProductsEndpoint;
use WooIdeaERP\Helpers\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Imports a single ERP product into WooCommerce.
 * Creates the product if it does not exist; updates it if it does.
 */
class ProductImporter {

	private const ERP_ID_META      = '_erp_product_id';
	private const ERP_TMPL_ID_META = '_erp_product_tmpl_id';

	public function __construct( private readonly ProductsEndpoint $products_endpoint ) {}

	/**
	 * Import a single ERP product as a WooCommerce simple product.
	 *
	 * @return array{ id: int, action: string, error?: string }
	 */
	public function import( ErpProduct $product ): array {
		try {
			$wc_id  = $this->find_wc_product_id( $product );
			$wc_id  = $this->import_simple( $product, $wc_id );
			$action = $wc_id ? 'updated' : 'created';

			return [ 'id' => $wc_id, 'action' => $action ];

		} catch ( \Throwable $e ) {
			Logger::error( sprintf(
				'ProductImporter: failed to import ERP product #%d (%s): %s',
				$product->id,
				$product->default_code,
				$e->getMessage()
			) );
			return [ 'id' => 0, 'action' => 'error', 'error' => $e->getMessage() ];
		}
	}

	/**
	 * Import a group of ERP variant records (same product_tmpl_id) as a single
	 * WooCommerce variable product. Each ErpProduct in $variants becomes one
	 * WC_Product_Variation. The attribute options are derived from the
	 * attributes field of each variant.
	 *
	 * @param  ErpProduct[] $variants  All variants sharing the same product_tmpl_id.
	 * @return array{ id: int, action: string, error?: string }
	 */
	public function import_variable_from_variants( array $variants ): array {
		if ( empty( $variants ) ) {
			return [ 'id' => 0, 'action' => 'error', 'error' => 'No variants provided.' ];
		}

		try {
			$representative = $variants[0];
			$tmpl_id        = $representative->product_tmpl_id;

			Logger::debug( sprintf(
				'import_variable_from_variants: START tmpl_id=%d, %d variants: %s',
				$tmpl_id,
				count( $variants ),
				implode( ', ', array_map( fn( $v ) => $v->default_code . '(id=' . $v->id . ')', $variants ) )
			) );

			// Find existing WC variable product by template ID meta.
			$wc_id = $this->find_wc_product_id_by_tmpl( $tmpl_id );

			Logger::debug( sprintf(
				'import_variable_from_variants: existing WC product by tmpl meta = %s',
				$wc_id ? '#' . $wc_id : 'none'
			) );

			/** @var \WC_Product_Variable $wc_product */
			$wc_product = $wc_id
				? wc_get_product( $wc_id )
				: new \WC_Product_Variable();

			if ( ! $wc_product instanceof \WC_Product_Variable ) {
				Logger::debug( sprintf(
					'import_variable_from_variants: WC product #%d is not WC_Product_Variable (type=%s), creating new',
					$wc_id,
					get_class( $wc_product )
				) );
				$wc_product = new \WC_Product_Variable();
			}

			$action = $wc_product->get_id() ? 'updated' : 'created';

			Logger::debug( sprintf(
				'import_variable_from_variants: action=%s, parent WC id=%d',
				$action,
				$wc_product->get_id()
			) );

			// Parent title: strip width/colour tail so the variable product reads
			// e.g. "COMO Ława" not "COMO Ława 75 dąb artisan".
			$parent_name = $this->variable_product_base_name( $representative->name );
			$wc_product->set_name( $parent_name );
			Logger::debug( sprintf(
				'import_variable_from_variants: parent name "%s" (from variant name "%s")',
				$parent_name,
				$representative->name
			) );
			$wc_product->set_status( 'publish' );
			$wc_product->set_catalog_visibility( 'visible' );
			$wc_product->set_weight( (string) $representative->weight );

			// Derive a shared SKU from the longest common prefix of all variant SKUs.
			$skus        = array_map( fn( ErpProduct $v ) => $v->default_code, $variants );
			$parent_sku  = $this->common_sku_prefix( $skus );
			$wc_product->set_sku( $parent_sku );

			Logger::debug( sprintf(
				'import_variable_from_variants: derived parent SKU "%s" from variants: %s',
				$parent_sku,
				implode( ', ', $skus )
			) );

			if ( ! empty( $representative->description ) ) {
				$wc_product->set_description( wp_kses_post( $representative->description ) );
			}
			if ( ! empty( $representative->description_sale ) ) {
				$wc_product->set_short_description( wp_kses_post( $representative->description_sale ) );
			}

			// Collect all unique attribute name→values across all variants
			// so the parent product declares the full set of options.
			$all_attr_options = $this->collect_attribute_options( $variants );

			Logger::debug( sprintf(
				'import_variable_from_variants: collected attribute options: %s',
				wp_json_encode( $all_attr_options )
			) );

			// Ensure every attribute exists as a global WC taxonomy (pa_*)
			// and every value exists as a term under that taxonomy.
			// Returns a map of  attribute_name => taxonomy_slug  (e.g. "kolor" => "pa_kolor").
			$taxonomy_map  = [];
			$wc_attributes = [];

			foreach ( $all_attr_options as $attr_name => $options ) {
				$taxonomy = $this->ensure_global_attribute( $attr_name, $options );

				if ( ! $taxonomy ) {
					Logger::warning( sprintf(
						'import_variable_from_variants: could not register global attribute "%s", skipping.',
						$attr_name
					) );
					continue;
				}

				$taxonomy_map[ $attr_name ] = $taxonomy;

				// Fetch the term IDs for the options so we can assign them.
				$term_ids = [];
				foreach ( $options as $value ) {
					$term = get_term_by( 'name', $value, $taxonomy );
					if ( $term ) {
						$term_ids[] = $term->term_id;
					}
				}

				$attr_id = wc_attribute_taxonomy_id_by_name( $attr_name );

				$attr = new \WC_Product_Attribute();
				$attr->set_id( $attr_id );
				$attr->set_name( $taxonomy );
				$attr->set_options( $term_ids );
				$attr->set_visible( true );
				$attr->set_variation( true );
				$wc_attributes[] = $attr;

				Logger::debug( sprintf(
					'import_variable_from_variants: global attribute "%s" (taxonomy="%s", id=%d) with %d term(s): %s',
					$attr_name,
					$taxonomy,
					$attr_id,
					count( $term_ids ),
					implode( ', ', $options )
				) );
			}

			if ( empty( $wc_attributes ) ) {
				Logger::warning( sprintf(
					'import_variable_from_variants: tmpl_id=%d has NO attributes — variants cannot be created without attributes. Check ERP data.',
					$tmpl_id
				) );
			}

			$wc_product->set_attributes( $wc_attributes );

			$saved_id = $wc_product->save();

			Logger::debug( sprintf(
				'import_variable_from_variants: parent product saved as WC #%d',
				$saved_id
			) );

			// Store template ID so we can find this parent later.
			update_post_meta( $saved_id, self::ERP_TMPL_ID_META, $tmpl_id );

			// Create/update one WC variation per ERP variant record.
			foreach ( $variants as $variant ) {
				Logger::debug( sprintf(
					'import_variable_from_variants: syncing variation for erp_id=%d sku="%s"',
					$variant->id,
					$variant->default_code
				) );
				$this->sync_single_variation( $saved_id, $variant, $all_attr_options, $taxonomy_map );
			}

			// The list endpoint returns images:[] for all products.
			// Fetch the full image list via a dedicated single-template request.
			$images = $this->products_endpoint->get_images_by_tmpl_id( $tmpl_id );
			Logger::debug( sprintf(
				'import_variable_from_variants: fetched %d image(s) for tmpl_id=%d',
				count( $images ),
				$tmpl_id
			) );
			$this->handle_images( $saved_id, $images );

			// Recalculate min/max price from variations.
			\WC_Product_Variable::sync( $saved_id );

			Logger::info( sprintf(
				'ProductImporter: variable product tmpl#%d saved as WC #%d (%d variants)',
				$tmpl_id,
				$saved_id,
				count( $variants )
			) );

			return [ 'id' => $saved_id, 'action' => $action ];

		} catch ( \Throwable $e ) {
			Logger::error( sprintf(
				'ProductImporter: failed to import variable group tmpl#%d: %s',
				$variants[0]->product_tmpl_id,
				$e->getMessage()
			) );
			return [ 'id' => 0, 'action' => 'error', 'error' => $e->getMessage() ];
		}
	}

	// -------------------------------------------------------------------------
	// Simple product
	// -------------------------------------------------------------------------

	private function import_simple( ErpProduct $erp, ?int $wc_id ): int {
		$wc_product = $wc_id
			? wc_get_product( $wc_id )
			: new \WC_Product_Simple();

		if ( ! $wc_product ) {
			$wc_product = new \WC_Product_Simple();
		}

		$this->apply_common_data( $wc_product, $erp );
		$wc_product->set_manage_stock( true );
		$wc_product->set_stock_quantity( $erp->available_qty() );
		$wc_product->set_stock_status( $erp->available_qty() > 0 ? 'instock' : 'outofstock' );

		$saved_id = $wc_product->save();

		update_post_meta( $saved_id, self::ERP_ID_META, $erp->id );

		// The list endpoint returns images:[] — fetch the full data by ID.
		$images = $this->products_endpoint->get_images_by_id( $erp->id );
		Logger::debug( sprintf(
			'import_simple: fetched %d image(s) for erp_id=%d sku="%s"',
			count( $images ),
			$erp->id,
			$erp->default_code
		) );
		$this->handle_images( $saved_id, $images );

		Logger::info( sprintf(
			'ProductImporter: simple product "%s" (SKU: %s) saved as WC #%d',
			$erp->name,
			$erp->default_code,
			$saved_id
		) );

		return $saved_id;
	}

	// -------------------------------------------------------------------------
	// Variation helpers
	// -------------------------------------------------------------------------

	/**
	 * Create or update a single WC variation from one ERP variant record.
	 *
	 * @param array<string, string[]> $all_attr_options  attribute_name => [value, ...]
	 * @param array<string, string>   $taxonomy_map      attribute_name => taxonomy_slug (e.g. "pa_kolor")
	 */
	private function sync_single_variation(
		int $parent_id,
		ErpProduct $variant,
		array $all_attr_options,
		array $taxonomy_map
	): void {
		// Find existing variation by ERP product ID meta or by SKU.
		$variation_id = $this->find_variation_by_erp_id( $variant->id );
		if ( ! $variation_id ) {
			$variation_id = wc_get_product_id_by_sku( $variant->default_code ) ?: null;
		}

		Logger::debug( sprintf(
			'sync_single_variation: erp_id=%d sku="%s" existing_variation_id=%s',
			$variant->id,
			$variant->default_code,
			$variation_id ? '#' . $variation_id : 'none (will create)'
		) );

		$variation = $variation_id
			? new \WC_Product_Variation( $variation_id )
			: new \WC_Product_Variation();

		$variation->set_parent_id( $parent_id );
		$variation->set_sku( $variant->default_code );
		$variation->set_regular_price( (string) $variant->list_price );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( $variant->available_qty() );
		$variation->set_stock_status( $variant->available_qty() > 0 ? 'instock' : 'outofstock' );
		$variation->set_weight( (string) $variant->weight );

		// Build a map of attribute_name => value for this specific variant.
		$variant_attr_map = [];
		foreach ( $variant->attributes as $attr ) {
			$variant_attr_map[ $attr->name ] = $attr->values[0] ?? '';
		}

		// When the ERP returns no attributes, fall back to parsing the name.
		if ( empty( $variant_attr_map ) ) {
			$parsed = $this->parse_attributes_from_name( $variant->name );
			foreach ( $parsed as $attr_name => $values ) {
				$variant_attr_map[ $attr_name ] = $values[0] ?? '';
			}
		}

		Logger::debug( sprintf(
			'sync_single_variation: erp_id=%d raw attribute map from ERP: %s',
			$variant->id,
			wp_json_encode( $variant_attr_map )
		) );

		// For global attributes, WooCommerce expects the variation's attribute
		// array to be keyed by taxonomy slug (e.g. "pa_kolor") and the value
		// to be the term slug (e.g. "czarny"), not the term name.
		$attributes = [];
		foreach ( array_keys( $all_attr_options ) as $attr_name ) {
			$taxonomy    = $taxonomy_map[ $attr_name ] ?? null;
			$raw_value   = $variant_attr_map[ $attr_name ] ?? '';
			$term_slug   = '';

			if ( $taxonomy && $raw_value !== '' ) {
				$term = get_term_by( 'name', $raw_value, $taxonomy );
				if ( $term ) {
					$term_slug = $term->slug;
				} else {
					// Fallback: use sanitized value as slug.
					$term_slug = sanitize_title( $raw_value );
				}
			}

			$key               = $taxonomy ?? sanitize_title( $attr_name );
			$attributes[ $key ] = $term_slug;

			Logger::debug( sprintf(
				'sync_single_variation: erp_id=%d attribute "%s" (taxonomy="%s") => term slug "%s" (raw="%s")%s',
				$variant->id,
				$attr_name,
				$taxonomy ?? 'none',
				$term_slug,
				$raw_value,
				$term_slug === '' ? ' [EMPTY — will match any]' : ''
			) );
		}

		$variation->set_attributes( $attributes );

		// EAN / barcode for this specific variant.
		if ( ! empty( $variant->barcode ) ) {
			$variation->update_meta_data( '_global_unique_id', $variant->barcode );
		}

		$saved_id = $variation->save();

		Logger::debug( sprintf(
			'sync_single_variation: erp_id=%d saved as WC variation #%d under parent #%d (EAN=%s)',
			$variant->id,
			$saved_id,
			$parent_id,
			$variant->barcode ?? 'none'
		) );

		update_post_meta( $saved_id, self::ERP_ID_META, $variant->id );
	}

	/**
	 * Collect all attribute names and their possible values across all variants.
	 *
	 * @param  ErpProduct[] $variants
	 * @return array<string, string[]>  attribute_name => [value, value, ...]
	 */
	private function collect_attribute_options( array $variants ): array {
		$options = [];
		foreach ( $variants as $variant ) {
			$contributed = false;
			foreach ( $variant->attributes as $attr ) {
				foreach ( $attr->values as $value ) {
					$options[ $attr->name ][] = $value;
					$contributed = true;
				}
			}

			// When the ERP returns no attribute values for this variant, fall back
			// to parsing Szerokość and Kolor from the product name.
			if ( ! $contributed ) {
				$parsed = $this->parse_attributes_from_name( $variant->name );
				foreach ( $parsed as $attr_name => $values ) {
					foreach ( $values as $value ) {
						$options[ $attr_name ][] = $value;
					}
				}
			}
		}
		// Deduplicate values per attribute.
		foreach ( $options as $name => $values ) {
			$options[ $name ] = array_values( array_unique( $values ) );
		}
		return $options;
	}

	/**
	 * Shorten an ERP variant name for the WooCommerce variable parent title.
	 * Drops the trailing " <width> <colour...>" chunk, or only the colour tail
	 * when there is no space-delimited width.
	 *
	 * Examples:
	 *  "COMO Ława 75 dąb artisan" → "COMO Ława"
	 *  "FLOW Słupek 136 biały"    → "FLOW Słupek"
	 */
	private function variable_product_base_name( string $name ): string {
		$name = trim( $name );
		if ( $name === '' ) {
			return $name;
		}

		// Last " <digits> <rest…>" where digits are space-delimited (avoids matching inside K154).
		if ( preg_match( '/^(.*)\s(\d+)\s+.+$/us', $name, $m ) ) {
			$base = trim( $m[1] );
			if ( $base !== '' ) {
				return $base;
			}
		}

		// No width segment — drop title-case prefix vs colour tail (same idea as parse fallback).
		if ( preg_match( '/^((?:[A-ZŁŚŻŹĆĄĘÓŃ][^\s]*\s+)+)(.+)$/u', $name, $m ) ) {
			$base = trim( $m[1] );
			$tail = trim( $m[2] );
			if ( $base !== '' && $tail !== '' ) {
				return $base;
			}
		}

		return $name;
	}

	/**
	 * Parse Szerokość and Kolor attributes from a product name when the ERP
	 * returns no attributes for the product.
	 *
	 * Rules:
	 *  - The last standalone integer in the name becomes Szerokość.
	 *  - Everything after that integer (trimmed) becomes Kolor.
	 *  - If no integer is found, the text after the last title-case/all-caps
	 *    word sequence is used as Kolor only.
	 *
	 * Examples:
	 *  "TREND Szafka 60 czarny"          → Szerokość=60,  Kolor=czarny
	 *  "Blat 100 dąb craft złoty"         → Szerokość=100, Kolor=dąb craft złoty
	 *  "FLOW/MODERN Szafka 60 2D czarny"  → Szerokość=60,  Kolor=czarny  (last int)
	 *  "DIAMOND Komoda K154 dąb evoke"    → Kolor=dąb evoke  (K154 not standalone)
	 *
	 * @return array<string, string[]>  attribute_name => [value]
	 */
	private function parse_attributes_from_name( string $name ): array {
		// Match the last standalone integer followed by at least one colour word.
		// \b(\d+)\b ensures we don't match numbers glued to letters (e.g. K154, 2D).
		if ( preg_match( '/\b(\d+)\b\s+(.+)$/u', $name, $m ) ) {
			$width  = trim( $m[1] );
			$colour = trim( $m[2] );
			if ( $colour !== '' ) {
				Logger::debug( sprintf(
					'parse_attributes_from_name: "%s" → Szerokość=%s, Kolor=%s',
					$name,
					$width,
					$colour
				) );
				return [
					'Szerokość' => [ $width ],
					'Kolor'     => [ $colour ],
				];
			}
		}

		// No integer found — extract colour from the tail of the name.
		// Skip leading all-caps / title-case word groups (brand/model tokens)
		// and treat the remaining lowercase-starting text as the colour.
		if ( preg_match( '/(?:[A-ZŁŚŻŹĆĄĘÓŃ][^\s]*\s+)+(.+)$/u', $name, $m ) ) {
			$colour = trim( $m[1] );
			if ( $colour !== '' ) {
				Logger::debug( sprintf(
					'parse_attributes_from_name: "%s" → Kolor=%s (no width)',
					$name,
					$colour
				) );
				return [ 'Kolor' => [ $colour ] ];
			}
		}

		Logger::debug( sprintf(
			'parse_attributes_from_name: "%s" → no attributes extracted',
			$name
		) );
		return [];
	}

	/**
	 * Ensure a WooCommerce global attribute taxonomy exists for the given name,
	 * and that all provided values exist as terms under it.
	 *
	 * Returns the taxonomy slug (e.g. "pa_kolor") on success, null on failure.
	 *
	 * @param  string[] $values  The option values to register as terms.
	 */
	private function ensure_global_attribute( string $attr_name, array $values ): ?string {
		$taxonomy = wc_attribute_taxonomy_name( $attr_name );

		// Create the attribute taxonomy if it doesn't exist yet.
		if ( ! wc_attribute_taxonomy_id_by_name( $attr_name ) ) {
			$result = wc_create_attribute( [
				'name'         => $attr_name,
				'slug'         => wc_sanitize_taxonomy_name( $attr_name ),
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			] );

			if ( is_wp_error( $result ) ) {
				Logger::error( sprintf(
					'ensure_global_attribute: failed to create attribute "%s": %s',
					$attr_name,
					$result->get_error_message()
				) );
				return null;
			}

			Logger::debug( sprintf(
				'ensure_global_attribute: created global attribute "%s" (taxonomy="%s")',
				$attr_name,
				$taxonomy
			) );

			// Re-register taxonomies so the new one is available in this request.
			register_taxonomy( $taxonomy, 'product' );
		}

		// Ensure every value exists as a term.
		foreach ( $values as $value ) {
			if ( $value === '' ) {
				continue;
			}

			if ( ! get_term_by( 'name', $value, $taxonomy ) ) {
				$inserted = wp_insert_term( $value, $taxonomy );

				if ( is_wp_error( $inserted ) ) {
					// Term may already exist under a different slug — not fatal.
					Logger::debug( sprintf(
						'ensure_global_attribute: wp_insert_term "%s" in "%s": %s',
						$value,
						$taxonomy,
						$inserted->get_error_message()
					) );
				} else {
					Logger::debug( sprintf(
						'ensure_global_attribute: created term "%s" in taxonomy "%s"',
						$value,
						$taxonomy
					) );
				}
			}
		}

		return $taxonomy;
	}

	/**
	 * Find a WC variable product by the ERP product_tmpl_id stored as meta.
	 */
	private function find_wc_product_id_by_tmpl( int $tmpl_id ): ?int {
		$posts = get_posts( [
			'post_type'      => 'product',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ [
				'key'   => self::ERP_TMPL_ID_META,
				'value' => $tmpl_id,
			] ],
		] );

		return ! empty( $posts ) ? (int) $posts[0] : null;
	}

	/**
	 * Find a WC variation post ID by the ERP product ID meta.
	 */
	private function find_variation_by_erp_id( int $erp_id ): ?int {
		$posts = get_posts( [
			'post_type'      => 'product_variation',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ [
				'key'   => self::ERP_ID_META,
				'value' => $erp_id,
			] ],
		] );

		return ! empty( $posts ) ? (int) $posts[0] : null;
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	/**
	 * Find the longest common prefix shared by all SKUs in the array,
	 * then strip any trailing whitespace or common separators (space, dash, underscore, dot).
	 *
	 * Examples:
	 *   ["JT-001 S", "JT-001 M", "JT-001 L"]  => "JT-001"
	 *   ["BS-1001 czarny S", "BS-1001 biały M"] => "BS-1001"
	 *   ["SKU220 1kg Czarny", "SKU220 1kg biały"] => "SKU220 1kg"
	 *
	 * @param  string[] $skus
	 */
	private function common_sku_prefix( array $skus ): string {
		if ( empty( $skus ) ) {
			return '';
		}

		if ( count( $skus ) === 1 ) {
			return $skus[0];
		}

		$first  = $skus[0];
		$prefix = '';

		for ( $i = 0; $i < strlen( $first ); $i++ ) {
			$char = $first[ $i ];
			foreach ( $skus as $sku ) {
				if ( ! isset( $sku[ $i ] ) || $sku[ $i ] !== $char ) {
					// Trim trailing separators from whatever we have so far.
					return rtrim( $prefix, " \t-_." );
				}
			}
			$prefix .= $char;
		}

		return rtrim( $prefix, " \t-_." );
	}

	private function apply_common_data( \WC_Product $wc_product, ErpProduct $erp ): void {
		$wc_product->set_name( $erp->name );
		$wc_product->set_sku( $erp->default_code );
		$wc_product->set_regular_price( (string) $erp->list_price );
		$wc_product->set_weight( (string) $erp->weight );
		$wc_product->set_status( 'publish' );
		$wc_product->set_catalog_visibility( 'visible' );

		if ( ! empty( $erp->description ) ) {
			$wc_product->set_description( wp_kses_post( $erp->description ) );
		}

		if ( ! empty( $erp->description_sale ) ) {
			$wc_product->set_short_description( wp_kses_post( $erp->description_sale ) );
		}

		// EAN / barcode stored in the WooCommerce global unique ID field.
		if ( ! empty( $erp->barcode ) ) {
			$wc_product->update_meta_data( '_global_unique_id', $erp->barcode );
		}
	}

	/**
	 * Find an existing WC product ID by ERP product ID meta or by SKU.
	 */
	private function find_wc_product_id( ErpProduct $erp ): ?int {
		// First try by stored ERP ID meta.
		$posts = get_posts( [
			'post_type'      => [ 'product', 'product_variation' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ [
				'key'   => self::ERP_ID_META,
				'value' => $erp->id,
			] ],
		] );

		if ( ! empty( $posts ) ) {
			return (int) $posts[0];
		}

		// Fall back to SKU match.
		$id = wc_get_product_id_by_sku( $erp->default_code );
		return $id ?: null;
	}

	/**
	 * Sideload images and attach them to the product.
	 *
	 * @param string[] $image_urls
	 */
	private function handle_images( int $post_id, array $image_urls ): void {
		if ( empty( $image_urls ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$gallery_ids = [];

		foreach ( $image_urls as $index => $url ) {
			if ( empty( $url ) ) {
				continue;
			}

			// Skip if already attached (check by source URL meta).
			$existing = $this->find_attachment_by_source_url( $url );
			if ( $existing ) {
				if ( $index === 0 ) {
					set_post_thumbnail( $post_id, $existing );
				} else {
					$gallery_ids[] = $existing;
				}
				continue;
			}

			// ERP image URLs like /product/images/8 have no extension.
			// media_sideload_image() requires a recognisable extension to detect
			// the MIME type, so append .webp when the path has no extension.
			$url_path     = wp_parse_url( $url, PHP_URL_PATH ) ?? '';
			$download_url = ( '' === pathinfo( $url_path, PATHINFO_EXTENSION ) )
				? rtrim( $url, '/' ) . '.webp'
				: $url;

			Logger::debug( sprintf(
				'handle_images: sideloading "%s" for post #%d',
				$download_url,
				$post_id
			) );

			$attachment_id = media_sideload_image( $download_url, $post_id, null, 'id' );

			if ( is_wp_error( $attachment_id ) ) {
				Logger::warning( sprintf(
					'ProductImporter: could not sideload image "%s" for post #%d: %s',
					$url,
					$post_id,
					$attachment_id->get_error_message()
				) );
				continue;
			}

			update_post_meta( $attachment_id, '_wideaerp_source_url', $url );

			if ( $index === 0 ) {
				set_post_thumbnail( $post_id, $attachment_id );
			} else {
				$gallery_ids[] = $attachment_id;
			}
		}

		if ( ! empty( $gallery_ids ) ) {
			update_post_meta( $post_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
		}
	}

	private function find_attachment_by_source_url( string $url ): ?int {
		$posts = get_posts( [
			'post_type'      => 'attachment',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ [
				'key'   => '_wideaerp_source_url',
				'value' => $url,
			] ],
		] );

		return ! empty( $posts ) ? (int) $posts[0] : null;
	}

}
