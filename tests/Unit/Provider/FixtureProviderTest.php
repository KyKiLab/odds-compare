<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Provider;

use Brain\Monkey\Functions;
use OddsCompare\Provider\FixtureProvider;
use OddsCompare\Provider\OddsApiMapper;
use OddsCompare\Provider\ProviderException;
use OddsCompare\Tests\TestCase;

final class FixtureProviderTest extends TestCase {

	private function provider(): FixtureProvider {
		return new FixtureProvider( new OddsApiMapper(), ODDS_COMPARE_DIR . 'data/fixtures' );
	}

	public function test_serves_bundled_events_without_network(): void {
		Functions\expect( 'wp_remote_get' )->never();

		$events = $this->provider()->events( 'soccer_epl', array( 'h2h' ) );

		self::assertCount( 8, $events );
		self::assertSame( array( 'h2h' ), $events[0]->markets() );
		self::assertGreaterThan( new \DateTimeImmutable(), $events[0]->commence_time, 'sample fixtures are shifted into the future' );
		self::assertSame( '19:00', $events[0]->commence_time->format( 'H:i' ), 'kick-off time of day is kept' );
	}

	public function test_lists_bundled_sports(): void {
		$keys = array_map( static fn( $s ) => $s->key, $this->provider()->sports() );

		self::assertSame( array( 'soccer_epl' ), $keys );
		self::assertTrue( $this->provider()->is_configured() );
	}

	public function test_unknown_sport_is_a_permanent_error(): void {
		try {
			$this->provider()->events( '../../wp-config', array() );
			self::fail( 'expected ProviderException' );
		} catch ( ProviderException $e ) {
			self::assertFalse( $e->is_retryable() );
		}
	}
}
