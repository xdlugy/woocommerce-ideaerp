/* global jQuery */
( function ( $ ) {
	'use strict';

	$( function () {
		var $form = $( 'form.variations_form' );
		if ( ! $form.length ) {
			return;
		}

		var $gallery     = $( '.woocommerce-product-gallery' );
		var originalHtml = $gallery.html();

		$form.on( 'found_variation', function ( e, variation ) {
			var images = variation.variation_gallery_images;
			if ( ! images || ! images.length ) {
				return;
			}

			var html = '<ul class="woocommerce-product-gallery__wrapper">';
			images.forEach( function ( img ) {
				html += '<li class="woocommerce-product-gallery__image">'
					+ '<a href="' + img.src + '" data-src="' + img.src + '">'
					+ '<img src="' + img.src + '" alt="' + img.alt + '">'
					+ '</a>'
					+ '</li>';
			} );
			html += '</ul>';

			$gallery.html( html );

			if ( typeof $.fn.wc_product_gallery === 'function' ) {
				$gallery.wc_product_gallery();
			}
		} );

		$form.on( 'reset_data', function () {
			$gallery.html( originalHtml );

			if ( typeof $.fn.wc_product_gallery === 'function' ) {
				$gallery.wc_product_gallery();
			}
		} );
	} );
} )( jQuery );
