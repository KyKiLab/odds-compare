<?php
/**
 * Base test case with Brain Monkey wired in.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	protected static function fixture( string $relative ): string {
		return file_get_contents( ODDS_COMPARE_FIXTURES . $relative );
	}
}
