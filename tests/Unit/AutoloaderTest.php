<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit;

use OddsCompare\Autoloader;
use OddsCompare\Tests\TestCase;

final class AutoloaderTest extends TestCase {

	public function test_resolves_plugin_classes_from_src(): void {
		// Composer already autoloads OddsCompare\ in tests; point ours at a scratch dir to prove it works on its own.
		$dir = sys_get_temp_dir() . '/odds-compare-autoload-' . uniqid() . '/';
		mkdir( $dir . 'Scratch', 0777, true );
		file_put_contents( $dir . 'Scratch/Probe.php', '<?php namespace OddsCompare\Scratch; final class Probe {}' );

		Autoloader::register( $dir );

		self::assertTrue( class_exists( 'OddsCompare\Scratch\Probe' ) );
		self::assertFalse( class_exists( 'OddsCompare\Scratch\Missing' ) );
		self::assertFalse( class_exists( 'Elsewhere\Probe' ) );
	}
}
