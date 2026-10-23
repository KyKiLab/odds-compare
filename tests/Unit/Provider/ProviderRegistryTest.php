<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Provider;

use Brain\Monkey\Actions;
use OddsCompare\Provider\FixtureProvider;
use OddsCompare\Provider\OddsApiMapper;
use OddsCompare\Provider\ProviderRegistry;
use OddsCompare\Support\Logger;
use OddsCompare\Tests\TestCase;

final class ProviderRegistryTest extends TestCase {

	public function test_resolve_falls_back_to_fixture_and_warns(): void {
		Actions\expectDone( 'odds_compare_log' )->once()->with( 'warning', \Mockery::type( 'string' ), array( 'provider' => 'gone' ), 'odds-compare' );

		$fixture  = new FixtureProvider( new OddsApiMapper(), ODDS_COMPARE_DIR . 'data/fixtures' );
		$registry = ( new ProviderRegistry( new Logger() ) )->register( $fixture );

		self::assertTrue( $registry->has( 'fixture' ) );
		self::assertNull( $registry->get( 'gone' ) );
		self::assertSame( $fixture, $registry->resolve( 'gone' ) );
		self::assertSame( $fixture, $registry->resolve( 'fixture' ) );
	}

	public function test_resolve_without_fallback_is_a_logic_error(): void {
		$this->expectException( \LogicException::class );
		( new ProviderRegistry() )->resolve( 'anything' );
	}
}
