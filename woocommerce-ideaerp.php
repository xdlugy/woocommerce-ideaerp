<?php
/**
 * Plugin Name:       WooCommerce IdeaERP Integration
 * Plugin URI:        https://github.com/rodan/woocommerce-ideaerp
 * Description:       Integrates WooCommerce with the IdeaERP system — syncs orders, products, customers, and inventory between the two platforms.
 * Version:           1.0.0
 * Author:            Rodan
 * Author URI:        https://rodan.pl
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woocommerce-ideaerp
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 * WC tested up to:   9.0
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants
define( 'WIDEAERP_VERSION',     '1.0.0' );
define( 'WIDEAERP_PLUGIN_FILE', __FILE__ );
define( 'WIDEAERP_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WIDEAERP_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'WIDEAERP_PLUGIN_BASE', plugin_basename( __FILE__ ) );

/**
 * Check that WooCommerce is active before doing anything else.
 */
function wideaerp_check_dependencies(): bool {
	return class_exists( 'WooCommerce' );
}

/**
 * Show an admin notice when WooCommerce is missing.
 */
function wideaerp_missing_woocommerce_notice(): void {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: %s: WooCommerce plugin link */
				esc_html__( 'WooCommerce IdeaERP Integration requires %s to be installed and active.', 'woocommerce-ideaerp' ),
				'<a href="' . esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ) . '">WooCommerce</a>'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Main plugin class — singleton.
 */
final class WooCommerce_IdeaERP {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->define_hooks();
	}

	/** Clone and unserialize are blocked for the singleton. */
	public function __clone() {}
	public function __wakeup() {}

	private function define_hooks(): void {
		add_action( 'init',                  [ $this, 'load_textdomain' ] );
		add_action( 'admin_menu',            [ $this, 'register_admin_menu' ] );
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_filter( 'plugin_action_links_' . WIDEAERP_PLUGIN_BASE, [ $this, 'add_action_links' ] );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'woocommerce-ideaerp',
			false,
			dirname( WIDEAERP_PLUGIN_BASE ) . '/languages'
		);
	}

	public function register_admin_menu(): void {
		add_options_page(
			__( 'IdeaERP Integration', 'woocommerce-ideaerp' ),
			__( 'IdeaERP', 'woocommerce-ideaerp' ),
			'manage_options',
			'woocommerce-ideaerp',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings(): void {
		register_setting( 'wideaerp_settings_group', 'wideaerp_erp_url',   [ 'sanitize_callback' => 'esc_url_raw' ] );
		register_setting( 'wideaerp_settings_group', 'wideaerp_api_token', [ 'sanitize_callback' => 'sanitize_text_field' ] );

		add_settings_section(
			'wideaerp_api_section',
			__( 'IdeaERP API Connection', 'woocommerce-ideaerp' ),
			null,
			'woocommerce-ideaerp'
		);

		add_settings_field(
			'wideaerp_erp_url',
			__( 'ERP Environment URL', 'woocommerce-ideaerp' ),
			[ $this, 'render_field_erp_url' ],
			'woocommerce-ideaerp',
			'wideaerp_api_section'
		);

		add_settings_field(
			'wideaerp_api_token',
			__( 'API Token', 'woocommerce-ideaerp' ),
			[ $this, 'render_field_api_token' ],
			'woocommerce-ideaerp',
			'wideaerp_api_section'
		);
	}

	public function render_field_erp_url(): void {
		$value = get_option( 'wideaerp_erp_url', '' );
		printf(
			'<input type="url" id="wideaerp_erp_url" name="wideaerp_erp_url" value="%s" class="regular-text" placeholder="https://erp.example.com" />',
			esc_attr( $value )
		);
	}

	public function render_field_api_token(): void {
		$value = get_option( 'wideaerp_api_token', '' );
		printf(
			'<input type="password" id="wideaerp_api_token" name="wideaerp_api_token" value="%s" class="regular-text" autocomplete="new-password" />',
			esc_attr( $value )
		);
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WooCommerce IdeaERP Integration', 'woocommerce-ideaerp' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wideaerp_settings_group' );
				do_settings_sections( 'woocommerce-ideaerp' );
				submit_button( __( 'Save Settings', 'woocommerce-ideaerp' ) );
				?>
			</form>
		</div>
		<?php
	}

	/** Add a "Settings" link on the Plugins list page. */
	public function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=woocommerce-ideaerp' ) ),
			esc_html__( 'Settings', 'woocommerce-ideaerp' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
}

/**
 * Bootstrap — only after all plugins are loaded so WooCommerce is available.
 */
function wideaerp_init(): void {
	if ( ! wideaerp_check_dependencies() ) {
		add_action( 'admin_notices', 'wideaerp_missing_woocommerce_notice' );
		return;
	}

	WooCommerce_IdeaERP::instance();
}
add_action( 'plugins_loaded', 'wideaerp_init' );

/**
 * Activation hook — create any required DB tables or default options.
 */
function wideaerp_activate(): void {
	if ( ! wideaerp_check_dependencies() ) {
		deactivate_plugins( WIDEAERP_PLUGIN_BASE );
		wp_die(
			esc_html__( 'WooCommerce IdeaERP Integration requires WooCommerce. Please install and activate WooCommerce first.', 'woocommerce-ideaerp' ),
			esc_html__( 'Plugin Activation Error', 'woocommerce-ideaerp' ),
			[ 'back_link' => true ]
		);
	}

	add_option( 'wideaerp_version', WIDEAERP_VERSION );
}
register_activation_hook( __FILE__, 'wideaerp_activate' );

/**
 * Deactivation hook.
 */
function wideaerp_deactivate(): void {
	// Reserved for future cleanup (e.g. clearing scheduled events).
}
register_deactivation_hook( __FILE__, 'wideaerp_deactivate' );
