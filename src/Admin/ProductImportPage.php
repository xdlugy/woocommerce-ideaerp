<?php

namespace WooIdeaERP\Admin;

use WooIdeaERP\Api\Client;
use WooIdeaERP\Api\Endpoints\ProductsEndpoint;
use WooIdeaERP\Api\DTO\ErpProduct;
use WooIdeaERP\Sync\ProductImporter;
use WooIdeaERP\Helpers\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the "Import Products" tab and handles its AJAX actions.
 */
class ProductImportPage {

	public function register_hooks(): void {
		add_action( 'wp_ajax_wideaerp_fetch_products',  [ $this, 'ajax_fetch_products' ] );
		add_action( 'wp_ajax_wideaerp_import_products', [ $this, 'ajax_import_products' ] );
	}

	// -------------------------------------------------------------------------
	// Tab HTML
	// -------------------------------------------------------------------------

	public function render(): void {
		$erp_url = get_option( 'wideaerp_erp_url', '' );
		$token   = get_option( 'wideaerp_api_token', '' );

		if ( empty( $erp_url ) || empty( $token ) ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'Please configure the ERP Environment URL and API Token on the Connection tab first.', 'woocommerce-ideaerp' )
				. '</p></div>';
			return;
		}
		?>
		<div id="wideaerp-import-wrap">
			<p><?php esc_html_e( 'Load the product list from IdeaERP, select the products you want to import, then click "Import Selected". Use "Group Variants" to manually merge simple products into a variable product before importing.', 'woocommerce-ideaerp' ); ?></p>

			<div style="margin-bottom:12px;">
				<button type="button" id="wideaerp-load-products" class="button button-secondary">
					<?php esc_html_e( 'Load Products from ERP', 'woocommerce-ideaerp' ); ?>
				</button>
				<button type="button" id="wideaerp-group-variants" class="button button-secondary" disabled style="margin-left:8px;">
					<?php esc_html_e( 'Group Variants', 'woocommerce-ideaerp' ); ?>
				</button>
				<button type="button" id="wideaerp-import-selected" class="button button-primary" disabled style="margin-left:8px;">
					<?php esc_html_e( 'Import Selected', 'woocommerce-ideaerp' ); ?>
				</button>
				<span id="wideaerp-spinner" class="spinner" style="float:none;margin-top:0;vertical-align:middle;display:none;"></span>
			</div>

			<div id="wideaerp-import-notice" style="display:none;"></div>

			<div id="wideaerp-products-table-wrap" style="display:none;">
				<p>
					<label>
						<input type="checkbox" id="wideaerp-select-all" />
						<?php esc_html_e( 'Select all', 'woocommerce-ideaerp' ); ?>
					</label>
					<span id="wideaerp-selected-count" style="margin-left:12px;color:#666;"></span>
				</p>
				<table class="wp-list-table widefat fixed striped" id="wideaerp-products-table">
					<thead>
						<tr>
							<th style="width:32px;"></th>
							<th><?php esc_html_e( 'SKU', 'woocommerce-ideaerp' ); ?></th>
							<th><?php esc_html_e( 'Name', 'woocommerce-ideaerp' ); ?></th>
							<th><?php esc_html_e( 'Price', 'woocommerce-ideaerp' ); ?></th>
							<th><?php esc_html_e( 'Stock', 'woocommerce-ideaerp' ); ?></th>
							<th><?php esc_html_e( 'Type', 'woocommerce-ideaerp' ); ?></th>
							<th><?php esc_html_e( 'In WooCommerce', 'woocommerce-ideaerp' ); ?></th>
						</tr>
					</thead>
					<tbody id="wideaerp-products-tbody">
					</tbody>
				</table>
			</div>

			<div id="wideaerp-import-results" style="margin-top:16px;display:none;">
				<h3><?php esc_html_e( 'Import Results', 'woocommerce-ideaerp' ); ?></h3>
				<ul id="wideaerp-results-list"></ul>
			</div>
		</div>

		<!-- Group Variants Modal -->
		<div id="wideaerp-group-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;z-index:100000;background:rgba(0,0,0,.5);">
			<div style="background:#fff;margin:40px auto;max-width:900px;max-height:calc(100vh - 80px);overflow-y:auto;border-radius:4px;box-shadow:0 4px 24px rgba(0,0,0,.3);">
				<div style="padding:16px 20px;border-bottom:1px solid #ddd;display:flex;align-items:center;justify-content:space-between;">
					<h2 style="margin:0;font-size:16px;"><?php esc_html_e( 'Group Variants', 'woocommerce-ideaerp' ); ?></h2>
					<button type="button" id="wideaerp-modal-close" class="button" style="margin:0;">&times; <?php esc_html_e( 'Close', 'woocommerce-ideaerp' ); ?></button>
				</div>
				<div style="padding:16px 20px;">
					<p style="color:#555;margin-top:0;">
						<?php esc_html_e( 'Drag simple products into a group to import them as a single variable product. Only checked simple products are shown here.', 'woocommerce-ideaerp' ); ?>
					</p>

					<!-- Ungrouped zone -->
					<div style="margin-bottom:20px;">
						<h3 style="margin:0 0 8px;"><?php esc_html_e( 'Ungrouped (import as-is)', 'woocommerce-ideaerp' ); ?></h3>
						<div id="wideaerp-ungrouped-zone"
							style="min-height:60px;border:2px dashed #ccc;border-radius:4px;padding:8px;"
							data-zone="ungrouped">
						</div>
					</div>

					<!-- Custom groups -->
					<div id="wideaerp-custom-groups"></div>

					<button type="button" id="wideaerp-add-group" class="button button-secondary">
						+ <?php esc_html_e( 'New Group', 'woocommerce-ideaerp' ); ?>
					</button>
				</div>
				<div style="padding:12px 20px;border-top:1px solid #ddd;text-align:right;">
					<button type="button" id="wideaerp-modal-apply" class="button button-primary">
						<?php esc_html_e( 'Apply Grouping', 'woocommerce-ideaerp' ); ?>
					</button>
				</div>
			</div>
		</div>

		<style>
		.wideaerp-draggable-item {
			display:flex;
			align-items:center;
			gap:8px;
			padding:6px 10px;
			margin-bottom:4px;
			background:#f9f9f9;
			border:1px solid #ddd;
			border-radius:3px;
			cursor:grab;
			user-select:none;
		}
		.wideaerp-draggable-item.dragging { opacity:.4; }
		.wideaerp-drop-zone.drag-over { border-color:#0073aa; background:#f0f7fb; }
		.wideaerp-group-box {
			margin-bottom:16px;
			border:1px solid #ccc;
			border-radius:4px;
			overflow:hidden;
		}
		.wideaerp-group-header {
			display:flex;
			align-items:center;
			gap:8px;
			padding:8px 12px;
			background:#f1f1f1;
			border-bottom:1px solid #ccc;
		}
		.wideaerp-group-header input[type=text] {
			flex:1;
			font-weight:600;
		}
		.wideaerp-group-zone {
			min-height:50px;
			padding:8px;
		}
		</style>

		<script>
		(function($){
			const nonce   = <?php echo wp_json_encode( wp_create_nonce( 'wideaerp_nonce' ) ); ?>;
			const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			let allProducts = [];
			let groupCounter = 0;
			let draggedItem  = null;

			// ----------------------------------------------------------------
			// Notices & helpers
			// ----------------------------------------------------------------

			function showNotice(message, type) {
				$('#wideaerp-import-notice')
					.attr('class', 'notice notice-' + type + ' inline')
					.html('<p>' + message + '</p>')
					.show();
			}

			function checkedSimpleCount() {
				// Count checked rows that are simple products — use the original key
				// stored in data-original-key, not the current value which may have
				// been changed to a custom_group: key by a previous grouping.
				return $('input.wideaerp-product-cb:checked').filter(function() {
					const orig = $(this).data('original-key') || $(this).val();
					return orig.startsWith('id:');
				}).length;
			}

			function updateSelectedCount() {
				const total = $('input.wideaerp-product-cb:checked').length;
				$('#wideaerp-selected-count').text(total + ' <?php echo esc_js( __( 'selected', 'woocommerce-ideaerp' ) ); ?>');
				$('#wideaerp-import-selected').prop('disabled', total === 0);
				$('#wideaerp-group-variants').prop('disabled', checkedSimpleCount() < 2);
			}

			// ----------------------------------------------------------------
			// Load products
			// ----------------------------------------------------------------

			$('#wideaerp-load-products').on('click', function() {
				const $btn = $(this);
				$btn.prop('disabled', true);
				$('#wideaerp-spinner').show();
				$('#wideaerp-import-notice').hide();
				$('#wideaerp-products-table-wrap').hide();

				$.post(ajaxUrl, {
					action: 'wideaerp_fetch_products',
					_ajax_nonce: nonce
				}, function(response) {
					$('#wideaerp-spinner').hide();
					$btn.prop('disabled', false);

					if (!response.success) {
						showNotice(response.data.message || '<?php echo esc_js( __( 'Unknown error.', 'woocommerce-ideaerp' ) ); ?>', 'error');
						return;
					}

					allProducts = response.data.products;
					renderTable(allProducts);
					$('#wideaerp-products-table-wrap').show();
					showNotice(
						response.data.products.length + ' <?php echo esc_js( __( 'products loaded.', 'woocommerce-ideaerp' ) ); ?>',
						'success'
					);
				}).fail(function() {
					$('#wideaerp-spinner').hide();
					$btn.prop('disabled', false);
					showNotice('<?php echo esc_js( __( 'Request failed. Check your connection settings.', 'woocommerce-ideaerp' ) ); ?>', 'error');
				});
			});

			// ----------------------------------------------------------------
			// Render table
			// ----------------------------------------------------------------

			function renderTable(products) {
				const $tbody = $('#wideaerp-products-tbody').empty();
				$.each(products, function(i, p) {
					const inWC = p.wc_id ? '&#10003; <a href="' + p.wc_edit_url + '" target="_blank">#' + p.wc_id + '</a>' : '&mdash;';
					let typeLabel;
					if (p.is_variable) {
						typeLabel = '<?php echo esc_js( __( 'Variable', 'woocommerce-ideaerp' ) ); ?>'
							+ ' <span style="color:#888;font-size:11px;">(' + p.variant_count + ' <?php echo esc_js( __( 'variants', 'woocommerce-ideaerp' ) ); ?>)</span>';
					} else {
						typeLabel = '<?php echo esc_js( __( 'Simple', 'woocommerce-ideaerp' ) ); ?>';
					}
					$tbody.append(
						'<tr data-erp-id="' + p.erp_id + '" data-is-variable="' + (p.is_variable ? '1' : '0') + '">' +
						'<td><input type="checkbox" class="wideaerp-product-cb" value="' + $('<span>').text(p.import_key).html() + '" data-original-key="' + $('<span>').text(p.import_key).html() + '" /></td>' +
						'<td>' + $('<span>').text(p.sku).html() + '</td>' +
						'<td>' + $('<span>').text(p.name).html() + '</td>' +
						'<td>' + p.price + '</td>' +
						'<td>' + p.stock + '</td>' +
						'<td class="wideaerp-type-cell">' + typeLabel + '</td>' +
						'<td>' + inWC + '</td>' +
						'</tr>'
					);
				});
				$('input.wideaerp-product-cb').on('change', updateSelectedCount);
				updateSelectedCount();
			}

			// Select all
			$('#wideaerp-select-all').on('change', function() {
				$('input.wideaerp-product-cb').prop('checked', $(this).is(':checked'));
				updateSelectedCount();
			});

			// ----------------------------------------------------------------
			// Import selected
			// ----------------------------------------------------------------

			$('#wideaerp-import-selected').on('click', function() {
				const keys = $('input.wideaerp-product-cb:checked').map(function(){ return $(this).val(); }).get();
				if (!keys.length) return;

				$(this).prop('disabled', true);
				$('#wideaerp-load-products').prop('disabled', true);
				$('#wideaerp-group-variants').prop('disabled', true);
				$('#wideaerp-spinner').show();
				$('#wideaerp-import-results').hide();
				$('#wideaerp-import-notice').hide();

				$.post(ajaxUrl, {
					action: 'wideaerp_import_products',
					_ajax_nonce: nonce,
					import_keys: keys
				}, function(response) {
					$('#wideaerp-spinner').hide();
					$('#wideaerp-import-selected').prop('disabled', false);
					$('#wideaerp-load-products').prop('disabled', false);

					if (!response.success) {
						showNotice(response.data.message || '<?php echo esc_js( __( 'Import failed.', 'woocommerce-ideaerp' ) ); ?>', 'error');
						return;
					}

					const results = response.data.results;
					const $list   = $('#wideaerp-results-list').empty();
					let ok = 0, errors = 0;

					$.each(results, function(i, r) {
						const icon = r.error ? '&#10007;' : '&#10003;';
						const cls  = r.error ? 'color:red' : 'color:green';
						$list.append('<li style="' + cls + '">' + icon + ' ' + $('<span>').text(r.sku).html() + ': ' + $('<span>').text(r.error || r.action).html() + '</li>');
						r.error ? errors++ : ok++;
					});

					$('#wideaerp-import-results').show();
					showNotice(ok + ' <?php echo esc_js( __( 'imported', 'woocommerce-ideaerp' ) ); ?>, ' + errors + ' <?php echo esc_js( __( 'errors', 'woocommerce-ideaerp' ) ); ?>.', errors ? 'warning' : 'success');

					$('#wideaerp-load-products').trigger('click');
				}).fail(function() {
					$('#wideaerp-spinner').hide();
					$('#wideaerp-import-selected').prop('disabled', false);
					$('#wideaerp-load-products').prop('disabled', false);
					showNotice('<?php echo esc_js( __( 'Request failed.', 'woocommerce-ideaerp' ) ); ?>', 'error');
				});
			});

			// ----------------------------------------------------------------
			// Drag-and-drop helpers
			// ----------------------------------------------------------------

			function makeDraggable($item) {
				$item[0].draggable = true;
				$item[0].addEventListener('dragstart', function(e) {
					draggedItem = $item[0];
					setTimeout(function(){ $item.addClass('dragging'); }, 0);
					e.dataTransfer.effectAllowed = 'move';
				});
				$item[0].addEventListener('dragend', function() {
					$item.removeClass('dragging');
					draggedItem = null;
				});
			}

			function makeDropZone($zone) {
				// Guard against attaching duplicate listeners on repeated modal opens.
				if ($zone.data('drop-zone-init')) return;
				$zone.data('drop-zone-init', true).addClass('wideaerp-drop-zone');
				$zone[0].addEventListener('dragover', function(e) {
					e.preventDefault();
					e.dataTransfer.dropEffect = 'move';
					$zone.addClass('drag-over');
				});
				$zone[0].addEventListener('dragleave', function() {
					$zone.removeClass('drag-over');
				});
				$zone[0].addEventListener('drop', function(e) {
					e.preventDefault();
					$zone.removeClass('drag-over');
					if (draggedItem) {
						$zone.append(draggedItem);
					}
				});
			}

			// ----------------------------------------------------------------
			// Modal: build draggable items from checked simple products
			// ----------------------------------------------------------------

			function buildModalItem(erp_id, sku, name) {
				const $item = $('<div class="wideaerp-draggable-item" data-erp-id="' + erp_id + '"></div>');
				$item.append('<span style="cursor:grab;color:#aaa;font-size:16px;">&#9776;</span>');
				$item.append('<strong>' + $('<span>').text(sku).html() + '</strong>');
				$item.append('<span style="color:#555;">' + $('<span>').text(name).html() + '</span>');
				const $remove = $('<button type="button" class="button button-small" style="margin-left:auto;" title="<?php echo esc_js( __( 'Remove from group', 'woocommerce-ideaerp' ) ); ?>">&times;</button>');
				$remove.on('click', function() {
					$('#wideaerp-ungrouped-zone').append($item);
				});
				$item.append($remove);
				makeDraggable($item);
				return $item;
			}

			function addGroup(name) {
				groupCounter++;
				const gid = 'wideaerp-group-' + groupCounter;
				const $box = $('<div class="wideaerp-group-box" data-group-id="' + groupCounter + '"></div>');
				const $header = $('<div class="wideaerp-group-header"></div>');
				const $nameInput = $('<input type="text" class="regular-text" value="' + $('<span>').text(name || ('<?php echo esc_js( __( 'Group', 'woocommerce-ideaerp' ) ); ?> ' + groupCounter)).html() + '" placeholder="<?php echo esc_js( __( 'Group name (optional)', 'woocommerce-ideaerp' ) ); ?>" />');
				const $del = $('<button type="button" class="button button-small"><?php echo esc_js( __( 'Remove Group', 'woocommerce-ideaerp' ) ); ?></button>');
				$del.on('click', function() {
					// Move items back to ungrouped before removing
					$box.find('.wideaerp-draggable-item').each(function() {
						$('#wideaerp-ungrouped-zone').append(this);
					});
					$box.remove();
				});
				$header.append($nameInput).append($del);
				const $zone = $('<div class="wideaerp-group-zone" id="' + gid + '"></div>');
				makeDropZone($zone);
				$box.append($header).append($zone);
				$('#wideaerp-custom-groups').append($box);
				return $zone;
			}

			$('#wideaerp-add-group').on('click', function() {
				addGroup('');
			});

			// ----------------------------------------------------------------
			// Open modal
			// ----------------------------------------------------------------

			$('#wideaerp-group-variants').on('click', function() {
				// Reset modal state
				$('#wideaerp-ungrouped-zone').empty();
				$('#wideaerp-custom-groups').empty();
				groupCounter = 0;

				// Populate ungrouped zone with checked simple products.
				// Use data-original-key to identify simple products even if their
				// checkbox value was previously changed to a custom_group: key.
				$('input.wideaerp-product-cb:checked').each(function() {
					const orig = $(this).data('original-key') || $(this).val();
					if (!orig.startsWith('id:')) return; // skip variable groups

					const $row  = $(this).closest('tr');
					const erpId = $row.data('erp-id');
					const sku   = $row.find('td:eq(1)').text().trim();
					const name  = $row.find('td:eq(2)').text().trim();

					$('#wideaerp-ungrouped-zone').append(buildModalItem(erpId, sku, name));
				});

				// Attach drop zone only once per open (zone is emptied above).
				makeDropZone($('#wideaerp-ungrouped-zone'));
				$('#wideaerp-group-modal').show();
			});

			// Close modal
			$('#wideaerp-modal-close').on('click', function() {
				$('#wideaerp-group-modal').hide();
			});

			// Close on backdrop click
			$('#wideaerp-group-modal').on('click', function(e) {
				if (e.target === this) {
					$(this).hide();
				}
			});

			// ----------------------------------------------------------------
			// Apply grouping: update checkbox values in the main table
			// ----------------------------------------------------------------

			$('#wideaerp-modal-apply').on('click', function() {
				// First reset all simple-product checkboxes to their original keys
				$('input.wideaerp-product-cb').each(function() {
					const orig = $(this).data('original-key');
					if (orig && orig.startsWith('id:')) {
						$(this).val(orig);
					}
				});

				// For each custom group that has 2+ items, build a custom_group: key
				$('#wideaerp-custom-groups .wideaerp-group-box').each(function() {
					const ids = [];
					$(this).find('.wideaerp-draggable-item').each(function() {
						ids.push($(this).data('erp-id'));
					});
					if (ids.length < 2) return; // not enough to form a group

					const groupKey = 'custom_group:' + ids.join(',');

					// Update the checkbox of the first product in the group to carry
					// the combined key; uncheck the rest so they don't import twice.
					ids.forEach(function(erpId, idx) {
						const $cb = $('tr[data-erp-id="' + erpId + '"] input.wideaerp-product-cb');
						if (idx === 0) {
							$cb.val(groupKey).prop('checked', true);
							// Update the type label in the table to reflect the new grouping
							$cb.closest('tr').find('.wideaerp-type-cell').html(
								'<em style="color:#0073aa;"><?php echo esc_js( __( 'Custom Group', 'woocommerce-ideaerp' ) ); ?> (' + ids.length + ')</em>'
							);
						} else {
							// Restore original key and uncheck — this row is covered by the group key above.
							const origKey = $cb.data('original-key') || $cb.val();
							$cb.val(origKey).prop('checked', false);
						}
					});
				});

				updateSelectedCount();
				$('#wideaerp-group-modal').hide();
			});

		})(jQuery);
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// AJAX: fetch product list
	// -------------------------------------------------------------------------

	public function ajax_fetch_products(): void {
		check_ajax_referer( 'wideaerp_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'woocommerce-ideaerp' ) ] );
		}

		try {
			$endpoint = $this->make_products_endpoint();
			$products = $endpoint->get_all();

			Logger::debug( sprintf(
				'ajax_fetch_products: received %d raw product records from ERP',
				count( $products )
			) );

			// Log each raw product's id and tmpl_id to reveal the grouping.
			foreach ( $products as $p ) {
				Logger::debug( sprintf(
					'  ERP product id=%d tmpl_id=%d sku="%s" name="%s" attributes=%s',
					$p->id,
					$p->product_tmpl_id,
					$p->default_code,
					$p->name,
					wp_json_encode( array_map(
						fn( $a ) => [ 'name' => $a->name, 'values' => $a->values ],
						$p->attributes
					) )
				) );
			}

			// Group by product_tmpl_id. Products that share a tmpl_id are
			// variants of the same parent and must be imported together as
			// a single WooCommerce variable product.
			$groups = $this->group_by_template( $products );

			Logger::debug( sprintf(
				'ajax_fetch_products: grouped into %d template groups',
				count( $groups )
			) );
			foreach ( $groups as $tmpl_id => $variants ) {
				Logger::debug( sprintf(
					'  tmpl_id=%d => %d variant(s): %s',
					$tmpl_id,
					count( $variants ),
					implode( ', ', array_map( fn( $v ) => $v->default_code, $variants ) )
				) );
			}

			$rows = [];
			foreach ( $groups as $tmpl_id => $variants ) {
				$representative = $variants[0];
				$is_variable    = count( $variants ) > 1;

				// For variable groups: look up the parent WC product by the
				// ERP template ID stored as meta. For simple products: look
				// up by SKU or individual ERP product ID meta.
				if ( $is_variable ) {
					$wc_id = $this->find_wc_id_by_erp_tmpl_meta( $tmpl_id );
				} else {
					$wc_id = wc_get_product_id_by_sku( $representative->default_code );
					if ( ! $wc_id ) {
						$wc_id = $this->find_wc_id_by_erp_meta( $representative->id );
					}
				}

				// Aggregate stock and collect variant SKUs for display.
				$total_stock = array_sum( array_map(
					fn( ErpProduct $v ) => $v->available_qty(),
					$variants
				) );

				$variant_skus = array_map( fn( ErpProduct $v ) => $v->default_code, $variants );

				// Price range label for variable products.
				if ( $is_variable ) {
					$prices = array_map( fn( ErpProduct $v ) => $v->list_price, $variants );
					$min    = min( $prices );
					$max    = max( $prices );
					$price_label = $min === $max
						? number_format( $min, 2 )
						: number_format( $min, 2 ) . ' – ' . number_format( $max, 2 );
				} else {
					$price_label = number_format( $representative->list_price, 2 );
				}

				$rows[] = [
					// For variable products we pass the tmpl_id as the import key;
					// for simple products we pass the individual erp_id.
					// The JS checkbox value encodes both: "tmpl:{tmpl_id}" or "id:{erp_id}".
					'import_key'   => $is_variable ? 'tmpl:' . $tmpl_id : 'id:' . $representative->id,
					'tmpl_id'      => $tmpl_id,
					'erp_id'       => $representative->id,
					'sku'          => $is_variable
						? implode( ', ', $variant_skus )
						: $representative->default_code,
					'name'         => $representative->name,
					'price'        => $price_label,
					'stock'        => number_format( $total_stock, 0 ),
					'is_variable'  => $is_variable,
					'variant_count'=> count( $variants ),
					'wc_id'        => $wc_id ?: null,
					'wc_edit_url'  => $wc_id ? get_edit_post_link( $wc_id, 'raw' ) : null,
				];
			}

			wp_send_json_success( [ 'products' => $rows ] );

		} catch ( \Throwable $e ) {
			Logger::error( 'ajax_fetch_products: ' . $e->getMessage() );
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	// -------------------------------------------------------------------------
	// AJAX: import selected products
	// -------------------------------------------------------------------------

	public function ajax_import_products(): void {
		check_ajax_referer( 'wideaerp_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'woocommerce-ideaerp' ) ] );
		}

		// Keys are either "tmpl:{id}" (variable group) or "id:{erp_id}" (simple).
		$raw_keys = isset( $_POST['import_keys'] ) ? (array) $_POST['import_keys'] : []; // phpcs:ignore WordPress.Security.NonceVerification
		$raw_keys = array_filter( array_map( 'sanitize_text_field', $raw_keys ) );

		if ( empty( $raw_keys ) ) {
			wp_send_json_error( [ 'message' => __( 'No products selected.', 'woocommerce-ideaerp' ) ] );
		}

		try {
			Logger::debug( sprintf(
				'ajax_import_products: received %d import keys: %s',
				count( $raw_keys ),
				implode( ', ', $raw_keys )
			) );

		$endpoint = $this->make_products_endpoint();
		$importer = new ProductImporter( $endpoint );
			$results  = [];

			// Fetch all products once and group them — avoids N+1 API calls
			// when importing multiple variable groups in one request.
			$all_products = $endpoint->get_all();
			$by_tmpl      = $this->group_by_template( $all_products );
			$by_id        = [];
			foreach ( $all_products as $p ) {
				$by_id[ $p->id ] = $p;
			}

			Logger::debug( sprintf(
				'ajax_import_products: fetched %d products, %d template groups available',
				count( $all_products ),
				count( $by_tmpl )
			) );

			foreach ( $raw_keys as $key ) {
				if ( str_starts_with( $key, 'tmpl:' ) ) {
					$tmpl_id  = (int) substr( $key, 5 );
					$variants = $by_tmpl[ $tmpl_id ] ?? [];

					Logger::debug( sprintf(
						'ajax_import_products: processing key "%s" => tmpl_id=%d, found %d variant(s)',
						$key,
						$tmpl_id,
						count( $variants )
					) );

					if ( empty( $variants ) ) {
						$results[] = [
							'sku'    => 'tmpl#' . $tmpl_id,
							'action' => '',
							'error'  => __( 'Product template not found in ERP.', 'woocommerce-ideaerp' ),
						];
						continue;
					}

					$result    = $importer->import_variable_from_variants( $variants );
					$results[] = [
						'sku'    => implode( ', ', array_map( fn( ErpProduct $v ) => $v->default_code, $variants ) ),
						'action' => $result['action'],
						'error'  => $result['error'] ?? null,
					];

				} elseif ( str_starts_with( $key, 'id:' ) ) {
					$erp_id  = (int) substr( $key, 3 );
					$product = $by_id[ $erp_id ] ?? null;

					Logger::debug( sprintf(
						'ajax_import_products: processing key "%s" => erp_id=%d, found=%s',
						$key,
						$erp_id,
						$product ? 'yes (sku=' . $product->default_code . ')' : 'no'
					) );

					if ( ! $product ) {
						$results[] = [
							'sku'    => '#' . $erp_id,
							'action' => '',
							'error'  => __( 'Product not found in ERP.', 'woocommerce-ideaerp' ),
						];
						continue;
					}

					$result    = $importer->import( $product );
					$results[] = [
						'sku'    => $product->default_code,
						'action' => $result['action'],
						'error'  => $result['error'] ?? null,
					];

				} elseif ( str_starts_with( $key, 'custom_group:' ) ) {
					// Manually grouped variants from the "Group Variants" modal.
					// Key format: custom_group:erp_id1,erp_id2,...
					$erp_ids  = array_map( 'intval', explode( ',', substr( $key, 13 ) ) );
					$variants = array_values( array_filter(
						array_map( fn( int $id ) => $by_id[ $id ] ?? null, $erp_ids )
					) );

					Logger::debug( sprintf(
						'ajax_import_products: processing key "%s" => %d erp_id(s), found %d variant(s)',
						$key,
						count( $erp_ids ),
						count( $variants )
					) );

					if ( count( $variants ) < 2 ) {
						$results[] = [
							'sku'    => implode( ',', $erp_ids ),
							'action' => '',
							'error'  => __( 'Custom group requires at least 2 products.', 'woocommerce-ideaerp' ),
						];
						continue;
					}

					$result    = $importer->import_variable_from_variants( $variants );
					$results[] = [
						'sku'    => implode( ', ', array_map( fn( ErpProduct $v ) => $v->default_code, $variants ) ),
						'action' => $result['action'],
						'error'  => $result['error'] ?? null,
					];
				}
			}

			wp_send_json_success( [ 'results' => $results ] );

		} catch ( \Throwable $e ) {
			Logger::error( 'ajax_import_products: ' . $e->getMessage() );
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_products_endpoint(): ProductsEndpoint {
		$client = new Client(
			get_option( 'wideaerp_erp_url', '' ),
			get_option( 'wideaerp_api_token', '' )
		);
		return new ProductsEndpoint( $client );
	}

	private function find_wc_id_by_erp_meta( int $erp_id ): ?int {
		$posts = get_posts( [
			'post_type'      => 'product',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ [
				'key'   => '_erp_product_id',
				'value' => $erp_id,
			] ],
		] );

		return ! empty( $posts ) ? (int) $posts[0] : null;
	}

	/**
	 * Find a WC variable product by the ERP template ID stored as post meta.
	 */
	private function find_wc_id_by_erp_tmpl_meta( int $tmpl_id ): ?int {
		$posts = get_posts( [
			'post_type'      => 'product',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [ [
				'key'   => '_erp_product_tmpl_id',
				'value' => $tmpl_id,
			] ],
		] );

		return ! empty( $posts ) ? (int) $posts[0] : null;
	}

	/**
	 * Group a flat list of ERP products by their product_tmpl_id.
	 * Products with a unique tmpl_id (no siblings) are simple products.
	 * Products sharing a tmpl_id are variants of the same parent.
	 *
	 * @param  ErpProduct[] $products
	 * @return array<int, ErpProduct[]>  keyed by product_tmpl_id
	 */
	private function group_by_template( array $products ): array {
		$groups = [];
		foreach ( $products as $product ) {
			$groups[ $product->product_tmpl_id ][] = $product;
		}
		return $groups;
	}
}
