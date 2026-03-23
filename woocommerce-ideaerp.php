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

// Plugin constants.
define( 'WIDEAERP_VERSION',     '1.0.0' );
define( 'WIDEAERP_PLUGIN_FILE', __FILE__ );
define( 'WIDEAERP_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WIDEAERP_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'WIDEAERP_PLUGIN_BASE', plugin_basename( __FILE__ ) );

// Register PSR-4 autoloader.
require_once WIDEAERP_PLUGIN_DIR . 'src/Autoloader.php';
( new \WooIdeaERP\Autoloader( WIDEAERP_PLUGIN_DIR . 'src' ) )->register();

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
 * Main plugin bootstrap — singleton.
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
		$this->boot();
	}

	public function __clone() {}
	public function __wakeup() {}

	private function boot(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );

		// Admin pages & settings.
		$settings_page = new \WooIdeaERP\Admin\SettingsPage();
		$settings_page->register_hooks();

		// AJAX handlers for the product import tab.
		$import_page = new \WooIdeaERP\Admin\ProductImportPage();
		$import_page->register_hooks();

		// Step 2: export WooCommerce orders to IdeaERP.
		$order_exporter = new \WooIdeaERP\Sync\OrderExporter();
		$order_exporter->register_hooks();
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'woocommerce-ideaerp',
			false,
			dirname( WIDEAERP_PLUGIN_BASE ) . '/languages'
		);
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
 * Activation hook.
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
