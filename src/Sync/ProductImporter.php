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
	public function import_variable_from_variants( array $variants, ?int $default_variant_erp_id = null ): array {
		if ( empty( $variants ) ) {
			return [ 'id' => 0, 'action' => 'error', 'error' => 'No variants provided.' ];
		}

		try {
			$representative = $variants[0];
		$tmpl_id        = $representative->product_tmpl_id;

		// One request returns every variant of this template fully hydrated
		// (images, barcode, description_sale_html). Without this, sync_single_variation
		// and resolve_* would fire one ERP call per variant.
		$hydrated = $this->products_endpoint->get_by_tmpl_id( $tmpl_id );
		$hydrated_by_id = [];
		foreach ( $hydrated as $h ) {
			$hydrated_by_id[ $h->id ] = $h;
		}

		// Find existing WC variable product by template ID meta.
		$wc_id = $this->find_wc_product_id_by_tmpl( $tmpl_id );

			/** @var \WC_Product_Variable $wc_product */
			$wc_product = $wc_id
				? wc_get_product( $wc_id )
				: new \WC_Product_Variable();

		if ( ! $wc_product instanceof \WC_Product_Variable ) {
			$wc_product = new \WC_Product_Variable();
		}

		$action = $wc_product->get_id() ? 'updated' : 'created';

		// Parent title: strip width/colour tail so the variable product reads
		// e.g. "COMO Ława" not "COMO Ława 75 dąb artisan".
		$parent_name = $this->variable_product_base_name( $representative->name );
		$collection  = $this->extract_collection_name( $representative->name );
	$wc_product->set_name( $parent_name );
		$wc_product->set_status( 'draft' );
			$wc_product->set_catalog_visibility( 'visible' );
			$wc_product->set_weight( (string) $representative->weight );

			$this->apply_description_fields( $wc_product, $representative );

			// Collect all unique attribute name→values across all variants
			// so the parent product declares the full set of options.
		$all_attr_options = $this->collect_attribute_options( $variants );

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
		}

		if ( empty( $wc_attributes ) ) {
			Logger::warning( sprintf(
				'import_variable_from_variants: tmpl_id=%d has NO attributes — variants cannot be created without attributes. Check ERP data.',
				$tmpl_id
			) );
		}

		if ( $collection !== '' ) {
			$kolekcja_taxonomy = $this->ensure_global_attribute( 'Kolekcja', [ $collection ] );
			if ( $kolekcja_taxonomy ) {
				$kolekcja_term = get_term_by( 'name', $collection, $kolekcja_taxonomy );
				$kolekcja_attr = new \WC_Product_Attribute();
				$kolekcja_attr->set_id( wc_attribute_taxonomy_id_by_name( 'Kolekcja' ) );
				$kolekcja_attr->set_name( $kolekcja_taxonomy );
				$kolekcja_attr->set_options( $kolekcja_term ? [ $kolekcja_term->term_id ] : [] );
				$kolekcja_attr->set_visible( true );
				$kolekcja_attr->set_variation( false );
				$wc_attributes[] = $kolekcja_attr;
			}
		}

		$wc_product->set_attributes( $wc_attributes );

		$saved_id = $wc_product->save();

		// Parent SKU: not in the ERP API — always assign a WooCommerce-generated parent SKU when empty or legacy-prefixed.
			// Variations use ERP default_code; the variable parent never clears its SKU on re-import.
			$this->maybe_apply_wc_default_parent_sku( $wc_product, $saved_id );

			// Store template ID so we can find this parent later.
			update_post_meta( $saved_id, self::ERP_TMPL_ID_META, $tmpl_id );

			// Create/update one WC variation per ERP variant record.
		foreach ( $variants as $variant ) {
			$hydrated_variant = $hydrated_by_id[ $variant->id ] ?? $variant;
			$this->sync_single_variation( $saved_id, $hydrated_variant, $all_attr_options, $taxonomy_map );
		}

			// Reuse the same per-template hydration — walk variants for the first
			// non-empty images array rather than firing a second API call.
		$images = [];
		foreach ( $hydrated as $h ) {
			if ( ! empty( $h->images ) ) {
				$images = $h->images;
				break;
			}
		}
		$this->handle_images( $saved_id, $images );

			// Set WooCommerce default variation if the caller specified one.
			if ( $default_variant_erp_id !== null ) {
				$this->apply_default_attributes( $saved_id, $default_variant_erp_id );
			}

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
		$this->assign_sku_after_save( $wc_product, $saved_id, $erp->default_code );

		update_post_meta( $saved_id, self::ERP_ID_META, $erp->id );
		$this->persist_global_unique_id_meta( $saved_id, $this->resolve_barcode( $erp ) );

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
		if ( ! $variation_id && trim( (string) $variant->default_code ) !== '' ) {
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
		$variation->set_regular_price( (string) $variant->list_price );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( $variant->available_qty() );
		$variation->set_stock_status( $variant->available_qty() > 0 ? 'instock' : 'outofstock' );
		$variation->set_weight( (string) $variant->weight );

		$this->apply_description_fields( $variation, $variant );

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

		$barcode = $this->resolve_barcode( $variant );
		$this->apply_global_unique_id( $variation, $barcode );

		$saved_id = $variation->save();

		$this->assign_sku_after_save( $variation, $saved_id, $variant->default_code );

		Logger::debug( sprintf(
			'sync_single_variation: erp_id=%d saved as WC variation #%d under parent #%d (EAN=%s)',
			$variant->id,
			$saved_id,
			$parent_id,
			$barcode !== '' ? $barcode : 'none'
		) );

		update_post_meta( $saved_id, self::ERP_ID_META, $variant->id );
		$this->persist_global_unique_id_meta( $saved_id, $barcode );

		// When $variant carries images (it does if it came from the hydrated template
		// fetch), use them directly; otherwise fall back to a per-id request.
		$var_images = ! empty( $variant->images )
			? $variant->images
			: $this->products_endpoint->get_images_by_id( $variant->id );
		Logger::debug( sprintf(
			'sync_single_variation: %d image(s) for variation erp_id=%d',
			count( $var_images ),
			$variant->id
		) );
		$this->handle_variation_images( $saved_id, $var_images );
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
			// to parsing Rozmiar and Kolor from the product name.
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
	 * Parse Rozmiar and Kolor attributes from a product name when the ERP
	 * returns no attributes for the product.
	 *
	 * Rules:
	 *  - The last standalone integer in the name becomes Rozmiar.
	 *  - Everything after that integer (trimmed) becomes Kolor.
	 *  - If no integer is found, the text after the last title-case/all-caps
	 *    word sequence is used as Kolor only.
	 *
	 * Examples:
	 *  "TREND Szafka 60 czarny"          → Rozmiar=60,  Kolor=czarny
	 *  "Blat 100 dąb craft złoty"         → Rozmiar=100, Kolor=dąb craft złoty
	 *  "FLOW/MODERN Szafka 60 2D czarny"  → Rozmiar=60,  Kolor=czarny  (last int)
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
					'parse_attributes_from_name: "%s" → Rozmiar=%s, Kolor=%s',
					$name,
					$width,
					$colour
				) );
				return [
					'Rozmiar' => [ $width ],
					'Kolor'   => [ $colour ],
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
	 * Return the first whitespace-delimited word of a product name (the collection/brand token).
	 * Returns an empty string when the name is blank.
	 */
	private function extract_collection_name( string $name ): string {
		$name = trim( $name );
		if ( $name === '' ) {
			return '';
		}
		$parts = preg_split( '/\s+/u', $name, 2 );
		return $parts[0] ?? '';
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
			'post_type'              => 'product',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => [ [
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
			'post_type'              => 'product_variation',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => [ [
				'key'   => self::ERP_ID_META,
				'value' => $erp_id,
			] ],
		] );

		return ! empty( $posts ) ? (int) $posts[0] : null;
	}

	/**
	 * Read the saved variation's attributes and write them as the parent's
	 * WooCommerce default variation (pre-selects attribute dropdowns on the
	 * product page).
	 */
	private function apply_default_attributes( int $parent_id, int $default_erp_id ): void {
		$variation_id = $this->find_variation_by_erp_id( $default_erp_id );

		if ( ! $variation_id ) {
			Logger::warning( sprintf(
				'apply_default_attributes: no variation found for erp_id=%d — default not set',
				$default_erp_id
			) );
			return;
		}

		$variation = wc_get_product( $variation_id );
		if ( ! $variation instanceof \WC_Product_Variation ) {
			return;
		}

		// get_attributes() on a variation returns taxonomy_slug => term_slug,
		// which is exactly the format set_default_attributes() expects.
		$default_attrs = $variation->get_attributes( 'edit' );

		$parent = wc_get_product( $parent_id );
		if ( ! $parent instanceof \WC_Product_Variable ) {
			return;
		}

		$parent->set_default_attributes( $default_attrs );
		$parent->save();

		Logger::debug( sprintf(
			'apply_default_attributes: WC #%d default set from variation #%d (erp_id=%d): %s',
			$parent_id,
			$variation_id,
			$default_erp_id,
			wp_json_encode( $default_attrs )
		) );
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	/**
	 * After the product exists in the database: set SKU from ERP default_code when set,
	 * otherwise use WooCommerce-style numeric id + wc_product_generate_unique_sku().
	 */
	private function assign_sku_after_save( \WC_Product $product, int $product_id, string $default_code ): void {
		$code = trim( (string) $default_code );
		if ( $code !== '' ) {
			$sku = function_exists( 'wc_product_generate_unique_sku' )
				? wc_product_generate_unique_sku( $product_id, $code, 0 )
				: $code;
		} else {
			$base = (string) $product_id;
			$sku  = function_exists( 'wc_product_generate_unique_sku' )
				? wc_product_generate_unique_sku( $product_id, $base, 0 )
				: $base;
		}

		if ( (string) $product->get_sku( 'edit' ) === $sku ) {
			return;
		}

		$product->set_sku( $sku );
		$product->save();
	}

	/**
	 * Set variable parent SKU using WooCommerce ID + uniqueness helper (same idea as programmatic "SKU = product ID").
	 * Skips when a non-empty SKU is already set (unless it is the legacy ideaerp-tmpl- prefix).
	 *
	 * @param \WC_Product_Variable $product  Parent after first save (must have positive ID).
	 */
	private function maybe_apply_wc_default_parent_sku( \WC_Product_Variable $product, int $saved_id ): void {
		$current = trim( (string) $product->get_sku( 'edit' ) );
		if ( $current !== '' && 0 !== strpos( $current, 'ideaerp-tmpl-' ) ) {
			return;
		}

		$base = (string) $saved_id;
		$sku  = function_exists( 'wc_product_generate_unique_sku' )
			? wc_product_generate_unique_sku( $saved_id, $base, 0 )
			: $base;

		if ( $product->get_sku( 'edit' ) === $sku ) {
			return;
		}

		$product->set_sku( $sku );
		$product->save();
	}

	private function apply_common_data( \WC_Product $wc_product, ErpProduct $erp ): void {
		$wc_product->set_name( $erp->name );
		$wc_product->set_regular_price( (string) $erp->list_price );
		$wc_product->set_weight( (string) $erp->weight );
		$wc_product->set_status( 'draft' );
		$wc_product->set_catalog_visibility( 'visible' );

		$this->apply_description_fields( $wc_product, $erp );

		$this->apply_global_unique_id( $wc_product, $this->resolve_barcode( $erp ) );
	}

	/**
	 * Long description: prefer ERP description_sale_html (with API refetch when
	 * the list payload omits it), else plain description. Short description from
	 * description_sale. Works for simple, variable parent, and variations.
	 */
	private function apply_description_fields( \WC_Product $product, ErpProduct $erp ): void {
		$html = $this->resolve_description_sale_html( $erp );
		if ( $html !== '' ) {
			$product->set_description( wp_kses_post( $html ) );
		} elseif ( ! empty( $erp->description ) ) {
			$product->set_description( wp_kses_post( $erp->description ) );
		}

		if ( ! empty( $erp->description_sale ) ) {
			$product->set_short_description( wp_kses_post( $erp->description_sale ) );
		}
	}

	private function resolve_description_sale_html( ErpProduct $erp ): string {
		$html = trim( (string) ( $erp->description_sale_html ?? '' ) );
		if ( $html !== '' ) {
			return $html;
		}

		// Avoid a redundant fetch when the hydrated template payload already
		// filled description/description_sale — signals the field is genuinely empty.
		if ( $erp->description !== null || $erp->description_sale !== null ) {
			return '';
		}

		$fresh = $this->products_endpoint->get_by_id( $erp->id );
		if ( $fresh ) {
			$html = trim( (string) ( $fresh->description_sale_html ?? '' ) );
		}

		return $html;
	}

	/**
	 * Barcode from the hydrated DTO, or from a single-product API fetch when the
	 * list endpoint leaves it empty (same pattern as images).
	 */
	private function resolve_barcode( ErpProduct $erp ): string {
		$barcode = trim( (string) ( $erp->barcode ?? '' ) );
		if ( $barcode !== '' ) {
			Logger::debug( sprintf( 'resolve_barcode: erp_id=%d from DTO="%s"', $erp->id, $barcode ) );
			return $barcode;
		}

		$barcode = $this->products_endpoint->fetch_barcode_by_id( $erp->id );
		Logger::debug( sprintf(
			'resolve_barcode: erp_id=%d after GET by id="%s"',
			$erp->id,
			$barcode !== '' ? $barcode : '(empty)'
		) );

		return $barcode;
	}

	/**
	 * Persist ERP barcode as WooCommerce GTIN / global unique ID.
	 *
	 * Uses post meta only: {@see \WC_Product::set_global_unique_id()} applies strict
	 * GTIN validation and can clear values that still need to be stored.
	 */
	private function apply_global_unique_id( \WC_Product $product, string $barcode ): void {
		$barcode = trim( $barcode );
		if ( $barcode === '' ) {
			return;
		}

		$product->update_meta_data( '_global_unique_id', $barcode );
	}

	/**
	 * Ensure _global_unique_id is written to the database (after saveWC may clear invalid GTIN from props).
	 */
	private function persist_global_unique_id_meta( int $post_id, string $barcode ): void {
		$barcode = trim( $barcode );
		if ( $barcode === '' ) {
			return;
		}

		$product = wc_get_product( $post_id );
		if ( $product instanceof \WC_Product ) {
			$product->update_meta_data( '_global_unique_id', $barcode );
			$product->save_meta_data();
		}

		// Direct post meta for compatibility (data stores, caches, older WC).
		update_post_meta( $post_id, '_global_unique_id', $barcode );

		Logger::debug( sprintf(
			'persist_global_unique_id_meta: post #%d _global_unique_id="%s"',
			$post_id,
			$barcode
		) );
	}

	/**
	 * Find an existing WC product ID by ERP product ID meta or by SKU.
	 */
	private function find_wc_product_id( ErpProduct $erp ): ?int {
		// First try by stored ERP ID meta.
		$posts = get_posts( [
			'post_type'              => [ 'product', 'product_variation' ],
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => [ [
				'key'   => self::ERP_ID_META,
				'value' => $erp->id,
			] ],
		] );

		if ( ! empty( $posts ) ) {
			return (int) $posts[0];
		}

		// Fall back to SKU match when ERP provides a code.
		if ( trim( (string) $erp->default_code ) === '' ) {
			return null;
		}
		$id = wc_get_product_id_by_sku( $erp->default_code );
		return $id ?: null;
	}

	/**
	 * Deduplicate image URLs and sort by the last URL path segment (image "name"),
	 * numerically when it is all digits, otherwise alphabetically.
	 *
	 * @param string[] $image_urls
	 * @return string[]
	 */
	private function normalize_image_urls( array $image_urls ): array {
		$urls = array_values( array_filter( array_map( 'trim', array_map( 'strval', $image_urls ) ) ) );
		$urls = array_values( array_unique( $urls ) );

		usort(
			$urls,
			function ( string $a, string $b ): int {
				$path_a = wp_parse_url( $a, PHP_URL_PATH );
				$path_b = wp_parse_url( $b, PHP_URL_PATH );
				$base_a = is_string( $path_a ) ? basename( rtrim( $path_a, '/' ) ) : $a;
				$base_b = is_string( $path_b ) ? basename( rtrim( $path_b, '/' ) ) : $b;

				$num_a = ctype_digit( $base_a ) ? (int) $base_a : null;
				$num_b = ctype_digit( $base_b ) ? (int) $base_b : null;

				if ( $num_a !== null && $num_b !== null ) {
					$cmp = $num_a <=> $num_b;
					if ( $cmp !== 0 ) {
						return $cmp;
					}
				} elseif ( $num_a !== null ) {
					return -1;
				} elseif ( $num_b !== null ) {
					return 1;
				} else {
					$cmp = strcmp( $base_a, $base_b );
					if ( $cmp !== 0 ) {
						return $cmp;
					}
				}

				return strcmp( $a, $b );
			}
		);

		return $urls;
	}

	/**
	 * Return an existing attachment ID for this ERP image URL, or sideload it
	 * as a child of $post_id (product or variation).
	 */
	private function get_or_sideload_attachment( int $post_id, string $url ): ?int {
		if ( $url === '' ) {
			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$existing = $this->find_attachment_by_source_url( $url );
		if ( $existing ) {
			return $existing;
		}

		$url_path     = wp_parse_url( $url, PHP_URL_PATH ) ?? '';
		$download_url = ( '' === pathinfo( $url_path, PATHINFO_EXTENSION ) )
			? rtrim( $url, '/' ) . '.webp'
			: $url;

		Logger::debug( sprintf(
			'get_or_sideload_attachment: sideloading "%s" for post #%d',
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
			return null;
		}

		update_post_meta( $attachment_id, '_wideaerp_source_url', $url );
		return (int) $attachment_id;
	}

	/**
	 * Sideload all variation images, set the first as the variation featured image,
	 * and store all IDs in _variation_gallery_ids for frontend gallery swapping.
	 *
	 * @param string[] $image_urls
	 */
	private function handle_variation_images( int $variation_id, array $image_urls ): void {
		$image_urls = $this->normalize_image_urls( $image_urls );
		if ( empty( $image_urls ) ) {
			return;
		}

		$all_ids = [];
		foreach ( $image_urls as $url ) {
			$id = $this->get_or_sideload_attachment( $variation_id, $url );
			if ( $id ) {
				$all_ids[] = $id;
			}
		}

		if ( empty( $all_ids ) ) {
			return;
		}

		$variation = wc_get_product( $variation_id );
		if ( ! $variation instanceof \WC_Product_Variation ) {
			return;
		}

		$variation->set_image_id( $all_ids[0] );
		$variation->save();

		update_post_meta( $variation_id, '_variation_gallery_ids', implode( ',', $all_ids ) );

		Logger::debug( sprintf(
			'handle_variation_images: variation #%d — %d image(s) stored (featured: #%d)',
			$variation_id,
			count( $all_ids ),
			$all_ids[0]
		) );
	}

	/**
	 * Sideload images and attach them to the product.
	 *
	 * @param string[] $image_urls
	 */
	private function handle_images( int $post_id, array $image_urls ): void {
		$image_urls = $this->normalize_image_urls( $image_urls );
		if ( empty( $image_urls ) ) {
			return;
		}

		$gallery_ids = [];

		foreach ( $image_urls as $index => $url ) {
			$attachment_id = $this->get_or_sideload_attachment( $post_id, $url );
			if ( ! $attachment_id ) {
				continue;
			}

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
			'post_type'              => 'attachment',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => [ [
				'key'   => '_wideaerp_source_url',
				'value' => $url,
			] ],
		] );

		return ! empty( $posts ) ? (int) $posts[0] : null;
	}

}
