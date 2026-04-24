<?php

namespace WooIdeaERP\Admin;

use WooIdeaERP\Api\Client;
use WooIdeaERP\Api\Endpoints\CarriersEndpoint;
use WooIdeaERP\Api\Endpoints\PaymentMethodsEndpoint;
use WooIdeaERP\Api\Endpoints\PricelistsEndpoint;
use WooIdeaERP\Sync\StockPriceSyncer;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin settings page with a tabbed interface.
 * Tabs: Connection | Import Products | Orders
 */
class SettingsPage {

	private const MENU_SLUG = 'woocommerce-ideaerp';

	public function register_hooks(): void {
		add_action( 'admin_menu',  [ $this, 'add_menu' ] );
		add_action( 'admin_init',  [ $this, 'register_settings' ] );
		add_filter( 'plugin_action_links_' . WIDEAERP_PLUGIN_BASE, [ $this, 'add_action_links' ] );
		add_action( 'wp_ajax_wideaerp_fetch_erp_payment_methods', [ $this, 'ajax_fetch_erp_payment_methods' ] );
		add_action( 'wp_ajax_wideaerp_save_payment_method_map',   [ $this, 'ajax_save_payment_method_map' ] );
		add_action( 'wp_ajax_wideaerp_fetch_erp_pricelists',      [ $this, 'ajax_fetch_erp_pricelists' ] );
		add_action( 'wp_ajax_wideaerp_save_pricelist_map',        [ $this, 'ajax_save_pricelist_map' ] );
		add_action( 'wp_ajax_wideaerp_fetch_erp_carriers',        [ $this, 'ajax_fetch_erp_carriers' ] );
		add_action( 'wp_ajax_wideaerp_save_carrier_map',          [ $this, 'ajax_save_carrier_map' ] );
	}

	public function add_menu(): void {
		add_options_page(
			__( 'Integracja IdeaERP', 'woocommerce-ideaerp' ),
			__( 'IdeaERP', 'woocommerce-ideaerp' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render' ]
		);
	}

	public function register_settings(): void {
		// --- Connection tab ---
		register_setting( 'wideaerp_connection_group', 'wideaerp_erp_url',   [ 'sanitize_callback' => 'esc_url_raw' ] );
		register_setting( 'wideaerp_connection_group', 'wideaerp_api_token', [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'wideaerp_connection_group', 'wideaerp_shop_id',   [ 'sanitize_callback' => 'sanitize_text_field' ] );
		register_setting( 'wideaerp_connection_group', 'wideaerp_integration_config', [ 'sanitize_callback' => 'absint' ] );

		add_settings_section( 'wideaerp_api_section', __( 'Połączenie API IdeaERP', 'woocommerce-ideaerp' ), null, 'wideaerp_tab_connection' );

		add_settings_field( 'wideaerp_erp_url', __( 'Adres URL środowiska ERP', 'woocommerce-ideaerp' ),
			[ $this, 'field_erp_url' ], 'wideaerp_tab_connection', 'wideaerp_api_section' );

		add_settings_field( 'wideaerp_api_token', __( 'Token API', 'woocommerce-ideaerp' ),
			[ $this, 'field_api_token' ], 'wideaerp_tab_connection', 'wideaerp_api_section' );

		add_settings_field( 'wideaerp_shop_id', __( 'ID / Nazwa sklepu w IdeaERP', 'woocommerce-ideaerp' ),
			[ $this, 'field_shop_id' ], 'wideaerp_tab_connection', 'wideaerp_api_section' );

		add_settings_field( 'wideaerp_integration_config', __( 'ID konfiguracji integracji', 'woocommerce-ideaerp' ),
			[ $this, 'field_integration_config' ], 'wideaerp_tab_connection', 'wideaerp_api_section' );

		// --- Orders tab ---
		register_setting( 'wideaerp_orders_group', 'wideaerp_order_trigger_status', [ 'sanitize_callback' => 'sanitize_key', 'default' => 'processing' ] );

		add_settings_section( 'wideaerp_orders_section', __( 'Ustawienia eksportu zamówień', 'woocommerce-ideaerp' ), [ $this, 'orders_section_description' ], 'wideaerp_tab_orders' );

		add_settings_field( 'wideaerp_order_trigger_status', __( 'Status wyzwalający eksport', 'woocommerce-ideaerp' ),
			[ $this, 'field_order_trigger_status' ], 'wideaerp_tab_orders', 'wideaerp_orders_section' );

		// --- Stock & Price Sync section (Orders tab) ---
		register_setting( 'wideaerp_orders_group', StockPriceSyncer::OPT_STOCK_INTERVAL, [ 'sanitize_callback' => 'absint', 'default' => 60 ] );
		register_setting( 'wideaerp_orders_group', StockPriceSyncer::OPT_PRICE_INTERVAL, [ 'sanitize_callback' => 'absint', 'default' => 60 ] );
		register_setting( 'wideaerp_orders_group', StockPriceSyncer::OPT_BATCH_SIZE,     [ 'sanitize_callback' => 'absint', 'default' => 100 ] );
		register_setting( 'wideaerp_orders_group', StockPriceSyncer::OPT_BATCH_DELAY,    [ 'sanitize_callback' => 'absint', 'default' => 30 ] );

		add_settings_section( 'wideaerp_sync_section', __( 'Synchronizacja stanu i cen', 'woocommerce-ideaerp' ), [ $this, 'sync_section_description' ], 'wideaerp_tab_orders' );

		add_settings_field( StockPriceSyncer::OPT_STOCK_INTERVAL, __( 'Interwał synchronizacji stanu (minuty)', 'woocommerce-ideaerp' ),
			[ $this, 'field_stock_sync_interval' ], 'wideaerp_tab_orders', 'wideaerp_sync_section' );

		add_settings_field( StockPriceSyncer::OPT_PRICE_INTERVAL, __( 'Interwał synchronizacji cen (minuty)', 'woocommerce-ideaerp' ),
			[ $this, 'field_price_sync_interval' ], 'wideaerp_tab_orders', 'wideaerp_sync_section' );

		add_settings_field( StockPriceSyncer::OPT_BATCH_SIZE, __( 'Produkty na partię', 'woocommerce-ideaerp' ),
			[ $this, 'field_sync_batch_size' ], 'wideaerp_tab_orders', 'wideaerp_sync_section' );

		add_settings_field( StockPriceSyncer::OPT_BATCH_DELAY, __( 'Opóźnienie między partiami (sekundy)', 'woocommerce-ideaerp' ),
			[ $this, 'field_sync_batch_delay' ], 'wideaerp_tab_orders', 'wideaerp_sync_section' );
	}

	// -------------------------------------------------------------------------
	// Section descriptions
	// -------------------------------------------------------------------------

	public function orders_section_description(): void {
		echo '<p>' . esc_html__( 'Skonfiguruj, kiedy zamówienia WooCommerce są przesyłane do IdeaERP.', 'woocommerce-ideaerp' ) . '</p>';
	}

	public function sync_section_description(): void {
		echo '<p>' . esc_html__( 'Stan magazynowy i ceny IdeaERP są pobierane raz na interwał i stosowane w WooCommerce w rozłożonych partiach. Zapisywane są tylko produkty, których wartości uległy zmianie.', 'woocommerce-ideaerp' ) . '</p>';
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	public function field_erp_url(): void {
		printf(
			'<input type="url" id="wideaerp_erp_url" name="wideaerp_erp_url" value="%s" class="regular-text" placeholder="https://erp.example.com" />',
			esc_attr( get_option( 'wideaerp_erp_url', '' ) )
		);
	}

	public function field_api_token(): void {
		printf(
			'<input type="password" id="wideaerp_api_token" name="wideaerp_api_token" value="%s" class="regular-text" autocomplete="new-password" />',
			esc_attr( get_option( 'wideaerp_api_token', '' ) )
		);
	}

	public function field_shop_id(): void {
		printf(
			'<input type="text" id="wideaerp_shop_id" name="wideaerp_shop_id" value="%s" class="regular-text" placeholder="1" />
			<p class="description">%s</p>',
			esc_attr( get_option( 'wideaerp_shop_id', '' ) ),
			esc_html__( 'Numeryczne ID lub nazwa sklepu w IdeaERP (sale_order.shop_id).', 'woocommerce-ideaerp' )
		);
	}

	public function field_integration_config(): void {
		printf(
			'<input type="number" id="wideaerp_integration_config" name="wideaerp_integration_config" value="%s" class="small-text" min="0" />
			<p class="description">%s</p>',
			esc_attr( get_option( 'wideaerp_integration_config', '' ) ),
			esc_html__( 'Liczba całkowita integration_config przypisana do tego sklepu WooCommerce w IdeaERP. Zostaw 0, jeśli nieznane.', 'woocommerce-ideaerp' )
		);
	}

	public function field_stock_sync_interval(): void {
		printf(
			'<input type="number" id="%1$s" name="%1$s" value="%2$s" class="small-text" min="1" />
			<p class="description">%3$s</p>',
			esc_attr( StockPriceSyncer::OPT_STOCK_INTERVAL ),
			esc_attr( get_option( StockPriceSyncer::OPT_STOCK_INTERVAL, 60 ) ),
			esc_html__( 'Jak często pobierać stany magazynowe z IdeaERP i aktualizować WooCommerce. Domyślnie: 60.', 'woocommerce-ideaerp' )
		);
	}

	public function field_price_sync_interval(): void {
		printf(
			'<input type="number" id="%1$s" name="%1$s" value="%2$s" class="small-text" min="1" />
			<p class="description">%3$s</p>',
			esc_attr( StockPriceSyncer::OPT_PRICE_INTERVAL ),
			esc_attr( get_option( StockPriceSyncer::OPT_PRICE_INTERVAL, 60 ) ),
			esc_html__( 'Jak często pobierać ceny z IdeaERP i aktualizować WooCommerce. Domyślnie: 60.', 'woocommerce-ideaerp' )
		);
	}

	public function field_sync_batch_size(): void {
		printf(
			'<input type="number" id="%1$s" name="%1$s" value="%2$s" class="small-text" min="1" max="500" />
			<p class="description">%3$s</p>',
			esc_attr( StockPriceSyncer::OPT_BATCH_SIZE ),
			esc_attr( get_option( StockPriceSyncer::OPT_BATCH_SIZE, 100 ) ),
			esc_html__( 'Liczba produktów przetwarzanych na partię. Domyślnie: 100.', 'woocommerce-ideaerp' )
		);
	}

	public function field_sync_batch_delay(): void {
		printf(
			'<input type="number" id="%1$s" name="%1$s" value="%2$s" class="small-text" min="0" />
			<p class="description">%3$s</p>',
			esc_attr( StockPriceSyncer::OPT_BATCH_DELAY ),
			esc_attr( get_option( StockPriceSyncer::OPT_BATCH_DELAY, 30 ) ),
			esc_html__( 'Sekundy oczekiwania między wysyłaniem kolejnych partii. Zwiększ, aby zmniejszyć skoki obciążenia bazy danych. Domyślnie: 30.', 'woocommerce-ideaerp' )
		);
	}

	public function field_order_trigger_status(): void {
		$current  = get_option( 'wideaerp_order_trigger_status', 'processing' );
		$statuses = wc_get_order_statuses();
		echo '<select id="wideaerp_order_trigger_status" name="wideaerp_order_trigger_status">';
		foreach ( $statuses as $slug => $label ) {
			// WooCommerce stores statuses with the "wc-" prefix in wc_get_order_statuses(),
			// but the status_changed hook passes slugs without it — strip the prefix for storage.
			$value = str_replace( 'wc-', '', $slug );
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Gdy zamówienie WooCommerce osiągnie ten status, zostanie przesłane do IdeaERP. Domyślnie: processing.', 'woocommerce-ideaerp' ) . '</p>';
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * Return all active IdeaERP payment methods as JSON for the mapping UI.
	 * Response: { success: true, data: [ { id, name }, ... ] }
	 */
	public function ajax_fetch_erp_payment_methods(): void {
		check_ajax_referer( 'wideaerp_orders_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Niewystarczające uprawnienia.', 'woocommerce-ideaerp' ) ], 403 );
		}

		$url   = get_option( 'wideaerp_erp_url', '' );
		$token = get_option( 'wideaerp_api_token', '' );

		if ( ! $url || ! $token ) {
			wp_send_json_error( [ 'message' => __( 'Adres URL ERP lub token API nie są skonfigurowane.', 'woocommerce-ideaerp' ) ] );
		}

		try {
			$endpoint = new PaymentMethodsEndpoint( new Client( $url, $token ) );
			$methods  = $endpoint->get_all();
			$data     = array_map( fn( $m ) => [ 'id' => $m->id, 'name' => $m->name ], $methods );
			wp_send_json_success( $data );
		} catch ( \RuntimeException $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Persist the WC→ERP payment method mapping submitted from the Orders tab.
	 * Payload: { nonce, map: { wc_method_id: erp_method_id, ... } }
	 */
	public function ajax_save_payment_method_map(): void {
		check_ajax_referer( 'wideaerp_orders_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Niewystarczające uprawnienia.', 'woocommerce-ideaerp' ) ], 403 );
		}

		$raw = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? $_POST['map'] : []; // phpcs:ignore WordPress.Security.NonceVerification
		$map = [];
		foreach ( $raw as $wc_id => $erp_id ) {
			$wc_id  = sanitize_key( $wc_id );
			$erp_id = absint( $erp_id );
			if ( $wc_id ) {
				$map[ $wc_id ] = $erp_id;
			}
		}

		update_option( 'wideaerp_payment_method_map', $map );
		wp_send_json_success( [ 'message' => __( 'Mapowanie metod płatności zapisane.', 'woocommerce-ideaerp' ) ] );
	}

	/**
	 * Return all active IdeaERP pricelists as JSON for the mapping UI.
	 * Response: { success: true, data: [ { id, name, currency }, ... ] }
	 */
	public function ajax_fetch_erp_pricelists(): void {
		check_ajax_referer( 'wideaerp_orders_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Niewystarczające uprawnienia.', 'woocommerce-ideaerp' ) ], 403 );
		}

		$url   = get_option( 'wideaerp_erp_url', '' );
		$token = get_option( 'wideaerp_api_token', '' );

		if ( ! $url || ! $token ) {
			wp_send_json_error( [ 'message' => __( 'Adres URL ERP lub token API nie są skonfigurowane.', 'woocommerce-ideaerp' ) ] );
		}

		try {
			$endpoint = new PricelistsEndpoint( new Client( $url, $token ) );
			$lists    = $endpoint->get_all();
			$data     = array_map( fn( $p ) => [ 'id' => $p->id, 'name' => $p->name, 'currency' => $p->currency ], $lists );
			wp_send_json_success( $data );
		} catch ( \RuntimeException $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Persist the WC currency → ERP pricelist mapping.
	 * Payload: { nonce, map: { currency_code: erp_pricelist_id, ... } }
	 */
	public function ajax_save_pricelist_map(): void {
		check_ajax_referer( 'wideaerp_orders_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Niewystarczające uprawnienia.', 'woocommerce-ideaerp' ) ], 403 );
		}

		$raw = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? $_POST['map'] : []; // phpcs:ignore WordPress.Security.NonceVerification
		$map = [];
		foreach ( $raw as $currency => $erp_id ) {
			$currency = sanitize_text_field( strtoupper( $currency ) );
			$erp_id   = absint( $erp_id );
			if ( $currency ) {
				$map[ $currency ] = $erp_id;
			}
		}

		update_option( 'wideaerp_pricelist_map', $map );
		wp_send_json_success( [ 'message' => __( 'Mapowanie cenników zapisane.', 'woocommerce-ideaerp' ) ] );
	}

	/**
	 * Return all active IdeaERP carriers as JSON for the mapping UI.
	 * Response: { success: true, data: [ { id, name, logistic_company }, ... ] }
	 */
	public function ajax_fetch_erp_carriers(): void {
		check_ajax_referer( 'wideaerp_orders_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Niewystarczające uprawnienia.', 'woocommerce-ideaerp' ) ], 403 );
		}

		$url   = get_option( 'wideaerp_erp_url', '' );
		$token = get_option( 'wideaerp_api_token', '' );

		if ( ! $url || ! $token ) {
			wp_send_json_error( [ 'message' => __( 'Adres URL ERP lub token API nie są skonfigurowane.', 'woocommerce-ideaerp' ) ] );
		}

		try {
			$endpoint = new CarriersEndpoint( new Client( $url, $token ) );
			$carriers = $endpoint->get_all();
			$data     = array_map(
				fn( $c ) => [ 'id' => $c->id, 'name' => $c->name, 'logistic_company' => $c->logistic_company ],
				$carriers
			);
			wp_send_json_success( $data );
		} catch ( \RuntimeException $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * Persist the WC shipping method → ERP carrier mapping.
	 * Payload: { nonce, map: { wc_shipping_method_id: erp_carrier_id, ... } }
	 */
	public function ajax_save_carrier_map(): void {
		check_ajax_referer( 'wideaerp_orders_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Niewystarczające uprawnienia.', 'woocommerce-ideaerp' ) ], 403 );
		}

		$raw = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? $_POST['map'] : []; // phpcs:ignore WordPress.Security.NonceVerification
		$map = [];
		foreach ( $raw as $wc_id => $erp_id ) {
			$wc_id  = sanitize_key( $wc_id );
			$erp_id = absint( $erp_id );
			if ( $wc_id ) {
				$map[ $wc_id ] = $erp_id;
			}
		}

		update_option( 'wideaerp_carrier_map', $map );
		wp_send_json_success( [ 'message' => __( 'Mapowanie przewoźników zapisane.', 'woocommerce-ideaerp' ) ] );
	}

	// -------------------------------------------------------------------------
	// Page renderer
	// -------------------------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification

		$tabs = [
			'connection' => __( 'Połączenie', 'woocommerce-ideaerp' ),
			'import'     => __( 'Importuj produkty', 'woocommerce-ideaerp' ),
			'orders'     => __( 'Zamówienia', 'woocommerce-ideaerp' ),
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Integracja WooCommerce IdeaERP', 'woocommerce-ideaerp' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&tab=' . $slug ) ); ?>"
					   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="tab-content" style="margin-top:20px;">
				<?php if ( $active_tab === 'connection' ) : ?>
					<form method="post" action="options.php">
						<?php
						settings_fields( 'wideaerp_connection_group' );
						do_settings_sections( 'wideaerp_tab_connection' );
					submit_button( __( 'Zapisz ustawienia', 'woocommerce-ideaerp' ) );
					?>
				</form>
			<?php elseif ( $active_tab === 'import' ) : ?>
				<?php ( new ProductImportPage() )->render(); ?>
			<?php elseif ( $active_tab === 'orders' ) : ?>
				<form method="post" action="options.php">
					<?php
					settings_fields( 'wideaerp_orders_group' );
					do_settings_sections( 'wideaerp_tab_orders' );
					submit_button( __( 'Zapisz ustawienia', 'woocommerce-ideaerp' ) );
						?>
					</form>

					<?php $this->render_payment_method_mapping(); ?>
					<?php $this->render_pricelist_mapping(); ?>
					<?php $this->render_carrier_mapping(); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the payment method mapping table on the Orders tab.
	 * WC payment methods are listed as rows; each row has a <select> populated
	 * via AJAX with IdeaERP payment methods. The selected ERP method ID is
	 * stored in wideaerp_payment_method_map and used as payment_term on export.
	 */
	private function render_payment_method_mapping(): void {
		$saved_map  = (array) get_option( 'wideaerp_payment_method_map', [] );
		$wc_methods = WC()->payment_gateways()->payment_gateways();
		$nonce      = wp_create_nonce( 'wideaerp_orders_nonce' );
		$ajax_url   = admin_url( 'admin-ajax.php' );
		?>
		<hr style="margin:30px 0;" />
		<h2><?php esc_html_e( 'Mapowanie metod płatności', 'woocommerce-ideaerp' ); ?></h2>
		<p><?php esc_html_e( 'Przypisz każdą metodę płatności WooCommerce do metody płatności IdeaERP. Przypisana metoda IdeaERP zostanie wysłana jako payment_term podczas eksportu zamówienia.', 'woocommerce-ideaerp' ); ?></p>

		<div id="wideaerp-pm-map-wrap">
			<table class="widefat striped" id="wideaerp-pm-map-table" style="max-width:700px;">
				<thead>
					<tr>
					<th><?php esc_html_e( 'Metoda płatności WooCommerce', 'woocommerce-ideaerp' ); ?></th>
					<th><?php esc_html_e( 'Metoda płatności IdeaERP', 'woocommerce-ideaerp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $wc_methods as $gateway_id => $gateway ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $gateway->get_title() ); ?></strong>
								<br /><code><?php echo esc_html( $gateway_id ); ?></code>
							</td>
							<td>
								<select name="wideaerp_pm_map[<?php echo esc_attr( $gateway_id ); ?>]"
								        class="wideaerp-erp-pm-select"
								        data-wc-id="<?php echo esc_attr( $gateway_id ); ?>"
								        data-saved="<?php echo esc_attr( $saved_map[ $gateway_id ] ?? '' ); ?>"
								        style="min-width:220px;">
								<option value=""><?php esc_html_e( '— ładowanie… —', 'woocommerce-ideaerp' ); ?></option>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p style="margin-top:12px;">
			<button type="button" id="wideaerp-pm-map-save" class="button button-primary">
				<?php esc_html_e( 'Zapisz mapowanie', 'woocommerce-ideaerp' ); ?>
				</button>
				<span id="wideaerp-pm-map-status" style="margin-left:12px;"></span>
			</p>
		</div>

		<script>
		(function($){
			var ajaxUrl  = <?php echo wp_json_encode( $ajax_url ); ?>;
			var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
			var $selects = $('.wideaerp-erp-pm-select');

			// Fetch ERP payment methods once and populate all selects.
			$.post( ajaxUrl, { action: 'wideaerp_fetch_erp_payment_methods', nonce: nonce }, function( resp ) {
				if ( ! resp.success ) {
					$selects.html('<option value="">' + ( resp.data && resp.data.message ? resp.data.message : <?php echo wp_json_encode( esc_js( __( 'Błąd ładowania metod', 'woocommerce-ideaerp' ) ) ); ?> ) + '</option>');
					return;
				}
				var methods = resp.data;
				$selects.each(function(){
					var $sel   = $(this);
					var saved  = $sel.data('saved');
				var html   = '<option value=""><?php echo esc_js( __( '— nie przypisano —', 'woocommerce-ideaerp' ) ); ?></option>';
				$.each( methods, function( i, m ){
						var sel = ( String(saved) === String(m.id) ) ? ' selected' : '';
						html += '<option value="' + m.id + '"' + sel + '>' + $('<span>').text(m.name).html() + '</option>';
					});
					$sel.html( html );
				});
			});

			// Save mapping via AJAX.
			$('#wideaerp-pm-map-save').on('click', function(){
				var map = {};
				$selects.each(function(){
					var wcId  = $(this).data('wc-id');
					var erpId = $(this).val();
					if ( wcId ) { map[ wcId ] = erpId; }
				});

				var $btn    = $(this).prop('disabled', true);
				var $status = $('#wideaerp-pm-map-status').text( <?php echo wp_json_encode( esc_js( __( 'Zapisywanie…', 'woocommerce-ideaerp' ) ) ); ?> );

				$.post( ajaxUrl, { action: 'wideaerp_save_payment_method_map', nonce: nonce, map: map }, function( resp ){
					$btn.prop('disabled', false);
					if ( resp.success ) {
						$status.css('color','green').text( resp.data.message );
					} else {
					$status.css('color','red').text( resp.data && resp.data.message ? resp.data.message : <?php echo wp_json_encode( esc_js( __( 'Zapis nie powiódł się.', 'woocommerce-ideaerp' ) ) ); ?> );
				}
				setTimeout(function(){ $status.text(''); }, 4000);
			});
		});
	}(jQuery));
	</script>
	<?php
	}

	/**
	 * Renders the pricelist mapping table on the Orders tab.
	 * Each WooCommerce currency active in the store gets a <select> populated
	 * via AJAX with IdeaERP pricelists. The selected pricelist ID is stored in
	 * wideaerp_pricelist_map and sent as pricelist on order export.
	 */
	private function render_pricelist_mapping(): void {
		$saved_map  = (array) get_option( 'wideaerp_pricelist_map', [] );
		$nonce      = wp_create_nonce( 'wideaerp_orders_nonce' );
		$ajax_url   = admin_url( 'admin-ajax.php' );

		// Collect currencies: always include the store default; add WPML/WooCommerce
		// Multilingual active currencies when available.
		$currencies = [ get_woocommerce_currency() => get_woocommerce_currency() ];
		if ( function_exists( 'wcml_get_woocommerce_currency_option' ) ) {
			$wcml_currencies = apply_filters( 'wcml_active_currencies', [] );
			foreach ( (array) $wcml_currencies as $code ) {
				$currencies[ $code ] = $code;
			}
		}
		$all_currencies = get_woocommerce_currencies();
		?>
		<hr style="margin:30px 0;" />
		<h2><?php esc_html_e( 'Mapowanie cenników', 'woocommerce-ideaerp' ); ?></h2>
		<p><?php esc_html_e( 'Przypisz każdą walutę WooCommerce do cennika IdeaERP. Przypisany cennik zostanie wysłany podczas eksportu zamówienia w tej walucie.', 'woocommerce-ideaerp' ); ?></p>

		<div id="wideaerp-pl-map-wrap">
			<table class="widefat striped" id="wideaerp-pl-map-table" style="max-width:700px;">
				<thead>
					<tr>
					<th><?php esc_html_e( 'Waluta WooCommerce', 'woocommerce-ideaerp' ); ?></th>
					<th><?php esc_html_e( 'Cennik IdeaERP', 'woocommerce-ideaerp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $currencies as $code => $_ ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $all_currencies[ $code ] ?? $code ); ?></strong>
								<br /><code><?php echo esc_html( $code ); ?></code>
							</td>
							<td>
								<select class="wideaerp-erp-pl-select"
								        data-currency="<?php echo esc_attr( $code ); ?>"
								        data-saved="<?php echo esc_attr( $saved_map[ $code ] ?? '' ); ?>"
								        style="min-width:220px;">
								<option value=""><?php esc_html_e( '— ładowanie… —', 'woocommerce-ideaerp' ); ?></option>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p style="margin-top:12px;">
			<button type="button" id="wideaerp-pl-map-save" class="button button-primary">
				<?php esc_html_e( 'Zapisz mapowanie', 'woocommerce-ideaerp' ); ?>
				</button>
				<span id="wideaerp-pl-map-status" style="margin-left:12px;"></span>
			</p>
		</div>

		<script>
		(function($){
			var ajaxUrl  = <?php echo wp_json_encode( $ajax_url ); ?>;
			var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
			var $selects = $('.wideaerp-erp-pl-select');

			// Fetch ERP pricelists once and populate all selects.
			$.post( ajaxUrl, { action: 'wideaerp_fetch_erp_pricelists', nonce: nonce }, function( resp ) {
				if ( ! resp.success ) {
					$selects.html('<option value="">' + ( resp.data && resp.data.message ? resp.data.message : <?php echo wp_json_encode( esc_js( __( 'Błąd ładowania cenników', 'woocommerce-ideaerp' ) ) ); ?> ) + '</option>');
					return;
				}
				var lists = resp.data;
				$selects.each(function(){
					var $sel  = $(this);
					var saved = $sel.data('saved');
				var html  = '<option value=""><?php echo esc_js( __( '— nie przypisano —', 'woocommerce-ideaerp' ) ); ?></option>';
				$.each( lists, function( i, p ){
						var label = p.name + ( p.currency ? ' (' + p.currency + ')' : '' );
						var sel   = ( String(saved) === String(p.id) ) ? ' selected' : '';
						html += '<option value="' + p.id + '"' + sel + '>' + $('<span>').text(label).html() + '</option>';
					});
					$sel.html( html );
				});
			});

			// Save mapping via AJAX.
			$('#wideaerp-pl-map-save').on('click', function(){
				var map = {};
				$selects.each(function(){
					var currency = $(this).data('currency');
					var erpId    = $(this).val();
					if ( currency ) { map[ currency ] = erpId; }
				});

				var $btn    = $(this).prop('disabled', true);
				var $status = $('#wideaerp-pl-map-status').text( <?php echo wp_json_encode( esc_js( __( 'Zapisywanie…', 'woocommerce-ideaerp' ) ) ); ?> );

				$.post( ajaxUrl, { action: 'wideaerp_save_pricelist_map', nonce: nonce, map: map }, function( resp ){
					$btn.prop('disabled', false);
					if ( resp.success ) {
						$status.css('color','green').text( resp.data.message );
					} else {
					$status.css('color','red').text( resp.data && resp.data.message ? resp.data.message : <?php echo wp_json_encode( esc_js( __( 'Zapis nie powiódł się.', 'woocommerce-ideaerp' ) ) ); ?> );
				}
				setTimeout(function(){ $status.text(''); }, 4000);
			});
		});
	}(jQuery));
	</script>
	<?php
	}

	/**
	 * Renders the carrier mapping table on the Orders tab.
	 * Each registered WooCommerce shipping method gets a <select> populated
	 * via AJAX with IdeaERP carriers. The selected carrier ID is stored in
	 * wideaerp_carrier_map and sent as carrier on order export.
	 */
	private function render_carrier_mapping(): void {
		$saved_map    = (array) get_option( 'wideaerp_carrier_map', [] );
		$wc_methods   = WC()->shipping()->get_shipping_methods();
		$nonce        = wp_create_nonce( 'wideaerp_orders_nonce' );
		$ajax_url     = admin_url( 'admin-ajax.php' );
		?>
		<hr style="margin:30px 0;" />
		<h2><?php esc_html_e( 'Mapowanie przewoźników', 'woocommerce-ideaerp' ); ?></h2>
		<p><?php esc_html_e( 'Przypisz każdą metodę dostawy WooCommerce do przewoźnika IdeaERP. Przypisany przewoźnik zostanie wysłany podczas eksportu zamówienia z tą metodą dostawy.', 'woocommerce-ideaerp' ); ?></p>

		<div id="wideaerp-cr-map-wrap">
			<table class="widefat striped" id="wideaerp-cr-map-table" style="max-width:700px;">
				<thead>
					<tr>
					<th><?php esc_html_e( 'Metoda dostawy WooCommerce', 'woocommerce-ideaerp' ); ?></th>
					<th><?php esc_html_e( 'Przewoźnik IdeaERP', 'woocommerce-ideaerp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $wc_methods as $method_id => $method ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $method->get_method_title() ); ?></strong>
								<br /><code><?php echo esc_html( $method_id ); ?></code>
							</td>
							<td>
								<select class="wideaerp-erp-cr-select"
								        data-wc-id="<?php echo esc_attr( $method_id ); ?>"
								        data-saved="<?php echo esc_attr( $saved_map[ $method_id ] ?? '' ); ?>"
								        style="min-width:220px;">
								<option value=""><?php esc_html_e( '— ładowanie… —', 'woocommerce-ideaerp' ); ?></option>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p style="margin-top:12px;">
			<button type="button" id="wideaerp-cr-map-save" class="button button-primary">
				<?php esc_html_e( 'Zapisz mapowanie', 'woocommerce-ideaerp' ); ?>
				</button>
				<span id="wideaerp-cr-map-status" style="margin-left:12px;"></span>
			</p>
		</div>

		<script>
		(function($){
			var ajaxUrl  = <?php echo wp_json_encode( $ajax_url ); ?>;
			var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
			var $selects = $('.wideaerp-erp-cr-select');

			// Fetch ERP carriers once and populate all selects.
			$.post( ajaxUrl, { action: 'wideaerp_fetch_erp_carriers', nonce: nonce }, function( resp ) {
				if ( ! resp.success ) {
					$selects.html('<option value="">' + ( resp.data && resp.data.message ? resp.data.message : <?php echo wp_json_encode( esc_js( __( 'Błąd ładowania przewoźników', 'woocommerce-ideaerp' ) ) ); ?> ) + '</option>');
					return;
				}
				var carriers = resp.data;
				$selects.each(function(){
					var $sel  = $(this);
					var saved = $sel.data('saved');
				var html  = '<option value=""><?php echo esc_js( __( '— nie przypisano —', 'woocommerce-ideaerp' ) ); ?></option>';
				$.each( carriers, function( i, c ){
						var label = c.name + ( c.logistic_company ? ' (' + c.logistic_company + ')' : '' );
						var sel   = ( String(saved) === String(c.id) ) ? ' selected' : '';
						html += '<option value="' + c.id + '"' + sel + '>' + $('<span>').text(label).html() + '</option>';
					});
					$sel.html( html );
				});
			});

			// Save mapping via AJAX.
			$('#wideaerp-cr-map-save').on('click', function(){
				var map = {};
				$selects.each(function(){
					var wcId  = $(this).data('wc-id');
					var erpId = $(this).val();
					if ( wcId ) { map[ wcId ] = erpId; }
				});

				var $btn    = $(this).prop('disabled', true);
				var $status = $('#wideaerp-cr-map-status').text( <?php echo wp_json_encode( esc_js( __( 'Zapisywanie…', 'woocommerce-ideaerp' ) ) ); ?> );

				$.post( ajaxUrl, { action: 'wideaerp_save_carrier_map', nonce: nonce, map: map }, function( resp ){
					$btn.prop('disabled', false);
					if ( resp.success ) {
						$status.css('color','green').text( resp.data.message );
					} else {
					$status.css('color','red').text( resp.data && resp.data.message ? resp.data.message : <?php echo wp_json_encode( esc_js( __( 'Zapis nie powiódł się.', 'woocommerce-ideaerp' ) ) ); ?> );
				}
				setTimeout(function(){ $status.text(''); }, 4000);
			});
		});
	}(jQuery));
	</script>
	<?php
	}

	public function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG ) ),
			esc_html__( 'Ustawienia', 'woocommerce-ideaerp' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
}
