<?php

namespace WooIdeaERP\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Injects per-variation gallery data into WooCommerce's variation JSON
 * and swaps the product gallery on the frontend when a variation is selected.
 */
class VariationGallery {

	public function register(): void {
		add_filter( 'woocommerce_available_variation', [ $this, 'add_gallery_data' ], 10, 3 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	/**
	 * Append variation_gallery_images to the variation data array that WC
	 * serialises as JSON and passes to the frontend JS.
	 *
	 * @param array                  $data      Variation data array.
	 * @param \WC_Product_Variable   $product   Parent product.
	 * @param \WC_Product_Variation  $variation Variation being processed.
	 * @return array
	 */
	public function add_gallery_data( array $data, \WC_Product_Variable $product, \WC_Product_Variation $variation ): array {
		$ids_string = get_post_meta( $variation->get_id(), '_variation_gallery_ids', true );
		if ( ! $ids_string ) {
			return $data;
		}

		$ids    = array_filter( array_map( 'intval', explode( ',', $ids_string ) ) );
		$images = [];

		foreach ( $ids as $id ) {
			$full  = wp_get_attachment_image_src( $id, 'woocommerce_single' );
			$thumb = wp_get_attachment_image_src( $id, 'woocommerce_thumbnail' );

			if ( ! $full ) {
				continue;
			}

			$images[] = [
				'id'    => $id,
				'src'   => $full[0],
				'thumb' => $thumb ? $thumb[0] : $full[0],
				'alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			];
		}

		$data['variation_gallery_images'] = $images;

		return $data;
	}

	public function enqueue_scripts(): void {
		if ( ! is_product() ) {
			return;
		}

		wp_enqueue_script(
			'wideaerp-variation-gallery',
			WIDEAERP_PLUGIN_URL . 'assets/js/variation-gallery.js',
			[ 'jquery', 'wc-add-to-cart-variation' ],
			WIDEAERP_VERSION,
			true
		);
	}
}
