<?php

namespace WooIdeaERP\Admin;

/**
 * Adds a per-variation gallery input on the WooCommerce product edit screen,
 * backed by the existing _variation_gallery_ids post meta.
 */
class VariationGalleryAdmin {

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'render_gallery_field' ], 10, 3 );
		add_action( 'woocommerce_save_product_variation', [ $this, 'save_gallery_field' ], 10, 2 );
	}

	public function enqueue_scripts( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_script(
			'wideaerp-admin-variation-gallery',
			WIDEAERP_PLUGIN_URL . 'assets/js/admin-variation-gallery.js',
			[ 'jquery', 'media-editor' ],
			WIDEAERP_VERSION,
			true
		);

		wp_localize_script(
			'wideaerp-admin-variation-gallery',
			'wideaerpVarGallery',
			[
				'addTitle'    => __( 'Select variation gallery images', 'woocommerce-ideaerp' ),
				'addButton'   => __( 'Set gallery', 'woocommerce-ideaerp' ),
				'addLabel'    => __( 'Add images', 'woocommerce-ideaerp' ),
				'removeLabel' => __( 'Remove image', 'woocommerce-ideaerp' ),
			]
		);
	}

	/**
	 * Render the gallery field inside each variation row.
	 *
	 * @param int      $loop           Zero-based variation index.
	 * @param array    $variation_data Variation data array passed by WooCommerce.
	 * @param \WP_Post $variation      The variation post object.
	 */
	public function render_gallery_field( int $loop, array $variation_data, \WP_Post $variation ): void {
		$ids_string  = get_post_meta( $variation->ID, '_variation_gallery_ids', true );
		$ids         = $ids_string ? array_filter( array_map( 'absint', explode( ',', $ids_string ) ) ) : [];
		$field_name  = 'variation_gallery_ids[' . $loop . ']';
		$container_id = 'variation-gallery-' . $loop;
		?>
		<p class="form-row form-row-full wideaerp-variation-gallery-row">
			<label><?php esc_html_e( 'Galeria wariantu', 'woocommerce-ideaerp' ); ?></label>

			<span
				class="wideaerp-variation-gallery"
				id="<?php echo esc_attr( $container_id ); ?>"
				data-variation-gallery
			>
			<?php
			foreach ( $ids as $attachment_id ) {
				// Try thumbnail first, fall back through medium → full.
				$thumb = wp_get_attachment_image_src( $attachment_id, 'thumbnail' )
					?: wp_get_attachment_image_src( $attachment_id, 'medium' )
					?: wp_get_attachment_image_src( $attachment_id, 'full' );

				if ( ! $thumb ) {
					continue;
				}
				?>
				<span class="wideaerp-variation-gallery__item" data-id="<?php echo esc_attr( $attachment_id ); ?>">
					<img
						src="<?php echo esc_url( $thumb[0] ); ?>"
						alt=""
						width="60"
						height="60"
					/>
					<button
						type="button"
						class="wideaerp-variation-gallery__remove"
						aria-label="<?php esc_attr_e( 'Remove image', 'woocommerce-ideaerp' ); ?>"
					>&times;</button>
				</span>
				<?php
			}
			?>
			</span>

			<input
				type="hidden"
				name="<?php echo esc_attr( $field_name ); ?>"
				value="<?php echo esc_attr( implode( ',', $ids ) ); ?>"
			/>

			<button
				type="button"
				class="button wideaerp-variation-gallery__add"
				data-target="<?php echo esc_attr( $container_id ); ?>"
			><?php esc_html_e( 'Dodaj obrazy', 'woocommerce-ideaerp' ); ?></button>
		</p>
		<style>
			.wideaerp-variation-gallery-row { border-top: 1px solid #eee; padding-top: 8px; }
			.wideaerp-variation-gallery { display: inline-flex; flex-wrap: wrap; gap: 6px; margin: 4px 0 8px; }
			.wideaerp-variation-gallery__item { position: relative; display: inline-block; }
			.wideaerp-variation-gallery__item img { display: block; width: 60px; height: 60px; object-fit: cover; border: 1px solid #ddd; border-radius: 3px; }
			.wideaerp-variation-gallery__remove { position: absolute; top: -6px; right: -6px; background: #cc0000; color: #fff; border: none; border-radius: 50%; width: 18px; height: 18px; font-size: 12px; line-height: 1; cursor: pointer; padding: 0; display: flex; align-items: center; justify-content: center; }
			.wideaerp-variation-gallery__add { margin-top: 4px; }
		</style>
		<?php
	}

	/**
	 * Save the gallery IDs posted for a specific variation loop index.
	 *
	 * This hook fires inside WooCommerce's own nonce-verified product save
	 * context, so no additional nonce check is required.
	 *
	 * @param int $variation_id WooCommerce variation post ID.
	 * @param int $loop         Zero-based variation index matching the rendered field.
	 */
	public function save_gallery_field( int $variation_id, int $loop ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = $_POST['variation_gallery_ids'][ $loop ] ?? '';

		if ( '' === $raw ) {
			delete_post_meta( $variation_id, '_variation_gallery_ids' );
			return;
		}

		$ids = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $raw ) ) ) ) );

		if ( empty( $ids ) ) {
			delete_post_meta( $variation_id, '_variation_gallery_ids' );
		} else {
			update_post_meta( $variation_id, '_variation_gallery_ids', implode( ',', $ids ) );
		}
	}
}
