<?php
/**
 * Minimal PSR-4 autoloader for the plugin's own classes.
 *
 * @package OddsCompare
 */

namespace OddsCompare;

/**
 * Maps OddsCompare\Foo\Bar to src/Foo/Bar.php.
 *
 * Composer generates the same mapping for the test suite (see composer.json), but the plugin
 * must not depend on vendor/ being present on a production site.
 */
final class Autoloader {

	/**
	 * Registers the loader for the OddsCompare namespace.
	 *
	 * @param string $base_dir Absolute path to the src/ directory, with trailing slash.
	 */
	public static function register( string $base_dir ): void {
		$prefix = __NAMESPACE__ . '\\';

		spl_autoload_register(
			static function ( string $class_name ) use ( $prefix, $base_dir ): void {
				if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
					return;
				}

				$relative = substr( $class_name, strlen( $prefix ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_file( $file ) ) {
					require $file;
				}
			}
		);
	}
}
