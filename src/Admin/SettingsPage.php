<?php

namespace WooIdeaERP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin settings page with a tabbed interface.
 * Tabs: Connection | Import Products | (future: Orders, Sync)
 */
class SettingsPage {

	private const MENU_SLUG = 'woocommerce-ideaerp';

	public function register_hooks(): void {
		add_action( 'admin_menu',  [ $this, 'add_menu' ] );
		add_action( 'admin_init',  [ $this, 'register_settings' ] );
		add_filter( 'plugin_action_links_' . WIDEAERP_PLUGIN_BASE, [ $this, 'add_action_links' ] );
	}

	public function add_menu(): void {
		add_options_page(
			__( 'IdeaERP Integration', 'woocommerce-ideaerp' ),
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

		add_settings_section( 'wideaerp_api_section', __( 'IdeaERP API Connection', 'woocommerce-ideaerp' ), null, 'wideaerp_tab_connection' );

		add_settings_field( 'wideaerp_erp_url', __( 'ERP Environment URL', 'woocommerce-ideaerp' ),
			[ $this, 'field_erp_url' ], 'wideaerp_tab_connection', 'wideaerp_api_section' );

		add_settings_field( 'wideaerp_api_token', __( 'API Token', 'woocommerce-ideaerp' ),
			[ $this, 'field_api_token' ], 'wideaerp_tab_connection', 'wideaerp_api_section' );

		add_settings_field( 'wideaerp_shop_id', __( 'Shop ID / Name in IdeaERP', 'woocommerce-ideaerp' ),
			[ $this, 'field_shop_id' ], 'wideaerp_tab_connection', 'wideaerp_api_section' );

		add_settings_field( 'wideaerp_integration_config', __( 'Integration Config ID', 'woocommerce-ideaerp' ),
			[ $this, 'field_integration_config' ], 'wideaerp_tab_connection', 'wideaerp_api_section' );
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
			esc_html__( 'The numeric ID or name of the shop in IdeaERP (sale_order.shop_id).', 'woocommerce-ideaerp' )
		);
	}

	public function field_integration_config(): void {
		printf(
			'<input type="number" id="wideaerp_integration_config" name="wideaerp_integration_config" value="%s" class="small-text" min="0" />
			<p class="description">%s</p>',
			esc_attr( get_option( 'wideaerp_integration_config', '' ) ),
			esc_html__( 'The integration_config integer assigned to this WooCommerce store in IdeaERP. Leave 0 if unknown.', 'woocommerce-ideaerp' )
		);
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
			'connection' => __( 'Connection', 'woocommerce-ideaerp' ),
			'import'     => __( 'Import Products', 'woocommerce-ideaerp' ),
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WooCommerce IdeaERP Integration', 'woocommerce-ideaerp' ); ?></h1>

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
						submit_button( __( 'Save Settings', 'woocommerce-ideaerp' ) );
						?>
					</form>
				<?php elseif ( $active_tab === 'import' ) : ?>
					<?php ( new ProductImportPage() )->render(); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG ) ),
			esc_html__( 'Settings', 'woocommerce-ideaerp' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
}
