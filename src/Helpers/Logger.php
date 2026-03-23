<?php

namespace WooIdeaERP\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Dual-output logger: writes to WooCommerce logs AND to WordPress debug.log.
 * debug.log output requires WP_DEBUG and WP_DEBUG_LOG to be true in wp-config.php.
 */
class Logger {

	private const SOURCE = 'woocommerce-ideaerp';

	private static ?\WC_Logger $logger = null;

	private static function instance(): \WC_Logger {
		if ( null === self::$logger ) {
			self::$logger = wc_get_logger();
		}
		return self::$logger;
	}

	public static function info( string $message ): void {
		self::instance()->info( $message, [ 'source' => self::SOURCE ] );
		self::write_debug( 'INFO', $message );
	}

	public static function error( string $message ): void {
		self::instance()->error( $message, [ 'source' => self::SOURCE ] );
		self::write_debug( 'ERROR', $message );
	}

	public static function warning( string $message ): void {
		self::instance()->warning( $message, [ 'source' => self::SOURCE ] );
		self::write_debug( 'WARNING', $message );
	}

	public static function debug( string $message ): void {
		self::instance()->debug( $message, [ 'source' => self::SOURCE ] );
		self::write_debug( 'DEBUG', $message );
	}

	/**
	 * Write directly to WordPress debug.log via error_log().
	 * Active whenever WP_DEBUG_LOG is true, independent of WC log settings.
	 */
	private static function write_debug( string $level, string $message ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[WooIdeaERP][%s] %s', $level, $message ) );
		}
	}
}
