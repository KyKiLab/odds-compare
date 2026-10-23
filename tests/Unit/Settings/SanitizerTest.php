<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Settings;

use Brain\Monkey\Functions;
use OddsCompare\Settings\Sanitizer;
use OddsCompare\Settings\Settings;
use OddsCompare\Tests\TestCase;

final class SanitizerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_key' )->alias( static fn( string $k ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $s ) => trim( strip_tags( $s ) ) );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_http_validate_url' )->alias( static fn( string $u ) => preg_match( '~^https?://[a-z0-9.-]+\.[a-z]{2,}(/|$)~i', $u ) ? $u : false );
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	private function sanitizer( array $current = array(), bool $constant = false ): Sanitizer {
		return new Sanitizer( array( 'fixture', 'odds_api' ), array_replace( Settings::defaults(), $current ), $constant );
	}

	public function test_junk_is_dropped_and_numbers_clamped(): void {
		$clean = $this->sanitizer()->sanitize(
			array(
				'provider'        => 'odds_api',
				'evil'            => 'x',
				'fresh_ttl'       => -5,
				'stale_ttl'       => 999999999,
				'quota_reserve'   => 9000,
				'markets'         => array( 'h2h', '<script>', 'spreads' ),
				'default_format'  => 'weird',
				'odds_api_region' => 'mars',
			)
		);

		self::assertArrayNotHasKey( 'evil', $clean );
		self::assertSame( 'odds_api', $clean['provider'] );
		self::assertSame( 60, $clean['fresh_ttl'] );
		self::assertSame( Sanitizer::MAX_STALE_TTL, $clean['stale_ttl'] );
		self::assertSame( 500, $clean['quota_reserve'] );
		self::assertSame( array( 'h2h', 'spreads' ), $clean['markets'] );
		self::assertSame( 'decimal', $clean['default_format'] );
		self::assertSame( 'uk', $clean['odds_api_region'] );
		self::assertSame( array(), $this->sanitizer()->errors() );
	}

	public function test_unknown_provider_and_empty_markets_keep_sane_values(): void {
		$clean = $this->sanitizer( array( 'provider' => 'odds_api' ) )->sanitize(
			array(
				'provider' => 'nope',
				'markets'  => array( 'bogus' ),
			)
		);

		self::assertSame( 'odds_api', $clean['provider'] );
		self::assertSame( array( 'h2h' ), $clean['markets'] );
	}

	public function test_stale_ttl_never_below_fresh_ttl(): void {
		$clean = $this->sanitizer()->sanitize(
			array(
				'fresh_ttl' => 3600,
				'stale_ttl' => 600,
			)
		);

		self::assertSame( 3600, $clean['stale_ttl'] );
	}

	public function test_api_key_is_ignored_when_fixed_by_constant(): void {
		$clean = $this->sanitizer( array( 'odds_api_key' => 'stored' ), true )->sanitize( array( 'odds_api_key' => 'typed' ) );
		self::assertSame( 'stored', $clean['odds_api_key'] );

		$clean = $this->sanitizer( array( 'odds_api_key' => 'stored' ), false )->sanitize( array( 'odds_api_key' => ' <b>typed</b> ' ) );
		self::assertSame( 'typed', $clean['odds_api_key'] );
	}

	public function test_invalid_bookmaker_link_is_rejected_and_previous_kept(): void {
		$sanitizer = $this->sanitizer(
			array(
				'bookmakers' => array(
					'coral' => array(
						'enabled' => true,
						'url'     => 'https://old.example/coral',
					),
				),
			)
		);

		$clean = $sanitizer->sanitize(
			array(
				'bookmakers' => array(
					'coral'           => array(
						'enabled' => '1',
						'url'     => 'javascript:alert(1)',
					),
					'skybet'          => array(
						'enabled' => '0',
						'url'     => 'ftp://x.example/',
					),
					'williamhill'     => array( 'url' => 'https://aff.example/wh?id=1' ),
					'oddschecker:akb' => array( 'enabled' => '1' ),
					'Bad Key!'        => array( 'enabled' => '1' ),
					'scalar'          => 'not-an-array',
				),
			)
		);

		self::assertSame( 'https://old.example/coral', $clean['bookmakers']['coral']['url'] );
		self::assertTrue( $clean['bookmakers']['coral']['enabled'] );
		self::assertSame( '', $clean['bookmakers']['skybet']['url'] );
		self::assertFalse( $clean['bookmakers']['skybet']['enabled'] );
		self::assertSame( 'https://aff.example/wh?id=1', $clean['bookmakers']['williamhill']['url'] );
		self::assertFalse( $clean['bookmakers']['williamhill']['enabled'] );
		self::assertTrue( $clean['bookmakers']['oddschecker:akb']['enabled'] );
		self::assertArrayNotHasKey( 'bad key!', $clean['bookmakers'] );
		self::assertArrayNotHasKey( 'scalar', $clean['bookmakers'] );
		self::assertCount( 2, $sanitizer->errors() );
	}

	public function test_non_array_input_returns_current_settings(): void {
		self::assertSame( Settings::defaults(), $this->sanitizer()->sanitize( 'garbage' ) );
	}
}
