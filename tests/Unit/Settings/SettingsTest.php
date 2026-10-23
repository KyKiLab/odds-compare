<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Settings;

use Brain\Monkey\Functions;
use OddsCompare\Domain\OddsFormat;
use OddsCompare\Settings\Settings;
use OddsCompare\Tests\TestCase;

final class SettingsTest extends TestCase {

	public function test_defaults_fill_missing_keys(): void {
		Functions\expect( 'get_option' )->once()->with( Settings::OPTION )->andReturn(
			array(
				'provider' => 'odds_api',
				'markets'  => array( 'totals' ),
			)
		);
		$settings = new Settings();

		self::assertSame( 'odds_api', $settings->provider_key() );
		self::assertSame( array( 'totals' ), $settings->markets() );
		self::assertSame( 7200, $settings->fresh_ttl() );
		self::assertSame( OddsFormat::Decimal, $settings->default_format() );
		self::assertSame( 'uk', $settings->region() );
		// Second call must not hit get_option again.
		self::assertSame( 20, $settings->quota_reserve() );
	}

	public function test_bookmaker_defaults_depend_on_being_built_in(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'bookmakers' => array(
					'coral'           => array(
						'enabled' => false,
						'url'     => 'https://aff.example/c',
					),
					'oddschecker:akb' => array(
						'enabled' => true,
						'url'     => '',
					),
				),
			)
		);
		$settings = new Settings();

		self::assertFalse( $settings->is_bookmaker_enabled( 'coral', true ) );
		self::assertTrue( $settings->is_bookmaker_enabled( 'williamhill', true ) );
		self::assertFalse( $settings->is_bookmaker_enabled( 'oddschecker:xyz', false ) );
		self::assertTrue( $settings->is_bookmaker_enabled( 'oddschecker:akb', false ) );
		self::assertSame( 'https://aff.example/c', $settings->bookmaker_url( 'coral' ) );
		self::assertNull( $settings->bookmaker_url( 'williamhill' ) );
	}

	public function test_api_key_prefers_the_constant(): void {
		Functions\when( 'get_option' )->justReturn( array( 'odds_api_key' => 'from-db' ) );
		$settings = new Settings();

		if ( ! defined( 'ODDS_COMPARE_ODDS_API_KEY' ) ) {
			define( 'ODDS_COMPARE_ODDS_API_KEY', 'from-config' );
		}

		self::assertTrue( $settings->api_key_from_constant() );
		self::assertSame( 'from-config', $settings->api_key() );
	}
}
