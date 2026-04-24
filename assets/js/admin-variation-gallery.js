/* global wp, wideaerpVarGallery, jQuery */
( function ( $, config ) {
	'use strict';

	/**
	 * Sync the hidden input value from the data-id attributes of all thumbnail
	 * items inside the given gallery container.
	 *
	 * @param {jQuery} $container The [data-variation-gallery] span element.
	 */
	function rebuildInput( $container ) {
		var ids = [];
		$container.find( '.wideaerp-variation-gallery__item' ).each( function () {
			var id = parseInt( $( this ).data( 'id' ), 10 );
			if ( id ) {
				ids.push( id );
			}
		} );
		$container.closest( '.wideaerp-variation-gallery-row' )
			.find( 'input[type="hidden"]' )
			.val( ids.join( ',' ) );
	}

	/**
	 * Build a single thumbnail item element.
	 *
	 * @param {number} id  Attachment ID.
	 * @param {string} url Thumbnail URL.
	 * @returns {jQuery}
	 */
	function buildThumb( id, url ) {
		return $( '<span>' )
			.addClass( 'wideaerp-variation-gallery__item' )
			.attr( 'data-id', id )
			.append(
				$( '<img>' )
					.attr( { src: url, alt: '', width: 60, height: 60 } )
			)
			.append(
				$( '<button>' )
					.attr( { type: 'button', 'aria-label': config.removeLabel } )
					.addClass( 'wideaerp-variation-gallery__remove' )
					.text( '\u00d7' )
			);
	}

	// Use event delegation on the variations wrapper so dynamically loaded rows
	// (WooCommerce loads them via AJAX when clicking "Load variations") also work.
	$( document ).on( 'click', '.wideaerp-variation-gallery__add', function () {
		var $btn       = $( this );
		var $container = $( '#' + $btn.data( 'target' ) );

		var frame = wp.media( {
			title:    config.addTitle,
			button:   { text: config.addButton },
			multiple: true,
			library:  { type: 'image' },
		} );

		frame.on( 'select', function () {
			var selection = frame.state().get( 'selection' );

			// Collect IDs already present to avoid duplicates.
			var existingIds = {};
			$container.find( '.wideaerp-variation-gallery__item' ).each( function () {
				existingIds[ $( this ).data( 'id' ) ] = true;
			} );

			selection.each( function ( attachment ) {
				var id = attachment.get( 'id' );
				if ( existingIds[ id ] ) {
					return;
				}
				existingIds[ id ] = true;

				var sizes = attachment.get( 'sizes' );
				var url   = sizes && sizes.thumbnail
					? sizes.thumbnail.url
					: attachment.get( 'url' );

				$container.append( buildThumb( id, url ) );
			} );

			rebuildInput( $container );
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.wideaerp-variation-gallery__remove', function () {
		var $item      = $( this ).closest( '.wideaerp-variation-gallery__item' );
		var $container = $item.closest( '[data-variation-gallery]' );
		$item.remove();
		rebuildInput( $container );
	} );

}( jQuery, wideaerpVarGallery ) );
