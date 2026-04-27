/* global jQuery */
( function ( $ ) {
	'use strict';

	$( function () {
		var $form = $( 'form.variations_form' );
		if ( ! $form.length ) {
			return;
		}

		var $gallery     = $( '.woocommerce-product-gallery' );
		if ( ! $gallery.length ) {
			return;
		}

		var originalHtml = $gallery.html();

		$form.on( 'found_variation', function ( e, variation ) {
			var images = variation.custom_variation_gallery;
			if ( ! images || ! images.length ) {
				return;
			}

			var html = '<ul class="woocommerce-product-gallery__wrapper">';
			images.forEach( function ( img ) {
				var full  = img.full  || img.image || '';
				var src   = img.image || img.full  || '';
				var alt   = img.alt   || img.title || '';
				html += '<li class="woocommerce-product-gallery__image">'
					+ '<a href="' + full + '" data-src="' + full + '">'
					+ '<img src="' + src + '" alt="' + alt + '">'
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
