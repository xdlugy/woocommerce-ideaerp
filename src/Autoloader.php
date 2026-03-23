<?php

namespace WooIdeaERP;

defined( 'ABSPATH' ) || exit;

/**
 * PSR-4 autoloader for the WooIdeaERP namespace.
 * Maps WooIdeaERP\Foo\Bar → src/Foo/Bar.php
 */
class Autoloader {

	private const NAMESPACE_PREFIX = 'WooIdeaERP\\';
	private string $base_dir;

	public function __construct( string $base_dir ) {
		$this->base_dir = rtrim( $base_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
	}

	public function register(): void {
		spl_autoload_register( [ $this, 'load' ] );
	}

	public function load( string $class ): void {
		if ( strncmp( $class, self::NAMESPACE_PREFIX, strlen( self::NAMESPACE_PREFIX ) ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( self::NAMESPACE_PREFIX ) );
		$file     = $this->base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
}
