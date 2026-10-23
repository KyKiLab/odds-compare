<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Bookmakers;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use OddsCompare\Bookmakers\Catalogue;
use OddsCompare\Tests\TestCase;

final class CatalogueTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		$options = &$this->options;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) use ( &$options ) {
				return $options[ $key ] ?? $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$options ): bool {
				$options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $s ) => trim( strip_tags( $s ) ) );
	}

	public function test_built_in_covers_the_uk_feed_and_maps_oddschecker_codes(): void {
		$catalogue = new Catalogue();
		$all       = $catalogue->all();

		foreach ( array( 'williamhill', 'paddypower', 'skybet', 'betfair_ex_uk', 'coral', 'ladbrokes_uk', 'betway', 'unibet_uk', 'betfred_uk', 'bet365' ) as $key ) {
			self::assertArrayHasKey( $key, $all, $key );
			self::assertNotNull( $all[ $key ]->url, "$key has a homepage" );
		}
		self::assertGreaterThanOrEqual( 25, count( $all ) );
		self::assertSame( 'William Hill', $catalogue->title( 'williamhill' ) );
		self::assertSame( 'williamhill', $catalogue->canonical_key( 'oddschecker', 'wh' ) );
		self::assertSame( 'bet365', $catalogue->canonical_key( 'oddschecker', 'B3' ) );
		self::assertSame( 'oddschecker:xyz', $catalogue->canonical_key( 'oddschecker', 'XYZ' ) );
		self::assertTrue( $catalogue->is_built_in( 'coral' ) );
		self::assertFalse( $catalogue->is_built_in( 'oddschecker:xyz' ) );
	}

	public function test_remember_seen_only_stores_unknown_keys_once(): void {
		$catalogue = new Catalogue();

		$catalogue->remember_seen(
			array(
				'coral'           => 'Coral',
				'oddschecker:xyz' => ' <b>XYZ Bets</b> ',
			)
		);
		$catalogue->remember_seen( array( 'oddschecker:xyz' => 'renamed' ) );

		self::assertSame( array( 'oddschecker:xyz' => 'XYZ Bets' ), $this->options[ Catalogue::SEEN_OPTION ] );
		self::assertSame( 'XYZ Bets', $catalogue->get( 'oddschecker:xyz' )->title );
		self::assertNull( $catalogue->get( 'oddschecker:xyz' )->url );
		self::assertArrayHasKey( 'oddschecker:xyz', $catalogue->all_with_seen() );
		self::assertArrayNotHasKey( 'oddschecker:xyz', $catalogue->all() );
	}

	public function test_filter_can_add_and_alias(): void {
		Filters\expectApplied( 'odds_compare_bookmakers' )->once()->andReturnUsing(
			static function ( array $entries ): array {
				$entries['mybookie'] = array( 'My Bookie', 'https://my.example/', array( 'oddschecker' => 'MYB' ) );
				return $entries;
			}
		);
		$catalogue = new Catalogue();

		self::assertSame( 'mybookie', $catalogue->canonical_key( 'oddschecker', 'MYB' ) );
		self::assertSame( 'My Bookie', $catalogue->title( 'mybookie' ) );
	}
}
