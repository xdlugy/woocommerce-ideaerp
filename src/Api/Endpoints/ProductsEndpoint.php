<?php

namespace WooIdeaERP\Api\Endpoints;

use WooIdeaERP\Api\Client;
use WooIdeaERP\Api\DTO\ErpProduct;

defined( 'ABSPATH' ) || exit;

class ProductsEndpoint {

	private const PATH       = 'v2/products';
	private const PAGE_LIMIT = 100;

	public function __construct( private readonly Client $client ) {}

	/**
	 * Fetch a single page of products.
	 *
	 * @return array{ products: ErpProduct[], total_count: int }
	 */
	public function get_page( int $offset = 0, int $limit = self::PAGE_LIMIT ): array {
		$data = $this->client->get( self::PATH, [
			'limit'  => $limit,
			'offset' => $offset,
		] );

		$products = array_map(
			fn( array $p ) => ErpProduct::from_array( $p ),
			$data['products'] ?? []
		);

		return [
			'products'    => $products,
			'total_count' => (int) ( $data['total_count'] ?? 0 ),
		];
	}

	/**
	 * Fetch all products from the ERP, paginating automatically.
	 *
	 * @return ErpProduct[]
	 */
	public function get_all(): array {
		$all    = [];
		$offset = 0;

		do {
			$page    = $this->get_page( $offset );
			$all     = array_merge( $all, $page['products'] );
			$offset += self::PAGE_LIMIT;
		} while ( count( $all ) < $page['total_count'] );

		return $all;
	}

	/**
	 * Fetch a single product by its ERP ID.
	 */
	public function get_by_id( int $id ): ?ErpProduct {
		$data = $this->client->get( self::PATH, [ 'id' => $id ] );

		$products = $data['products'] ?? [];
		if ( empty( $products ) ) {
			return null;
		}

		return ErpProduct::from_array( $products[0] );
	}

	/**
	 * Fetch a single product by SKU (default_code).
	 */
	public function get_by_sku( string $sku ): ?ErpProduct {
		$data = $this->client->get( self::PATH, [ 'default_code' => $sku ] );

		$products = $data['products'] ?? [];
		if ( empty( $products ) ) {
			return null;
		}

		return ErpProduct::from_array( $products[0] );
	}

	/**
	 * Fetch image URLs for a product by its ERP product ID.
	 * The list endpoint always returns images:[], so we must fetch individually.
	 *
	 * @return string[]
	 */
	public function get_images_by_id( int $id ): array {
		$data     = $this->client->get( self::PATH, [ 'id' => $id ] );
		$products = $data['products'] ?? [];

		if ( empty( $products ) ) {
			return [];
		}

		return array_filter( (array) ( $products[0]['images'] ?? [] ) );
	}

	/**
	 * Fetch image URLs for a product template (variable product) by its tmpl_id.
	 * Uses the first variant returned — all variants share the same template images.
	 *
	 * @return string[]
	 */
	public function get_images_by_tmpl_id( int $tmpl_id ): array {
		$data     = $this->client->get( self::PATH, [ 'product_tmpl_id' => $tmpl_id ] );
		$products = $data['products'] ?? [];

		if ( empty( $products ) ) {
			return [];
		}

		// Walk through variants until we find one with images.
		foreach ( $products as $product ) {
			$images = array_filter( (array) ( $product['images'] ?? [] ) );
			if ( ! empty( $images ) ) {
				return $images;
			}
		}

		return [];
	}
}
