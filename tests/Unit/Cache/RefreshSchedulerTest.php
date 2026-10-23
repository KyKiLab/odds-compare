<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Cache;

use Brain\Monkey\Functions;
use Mockery;
use OddsCompare\Cache\OddsCache;
use OddsCompare\Cache\OddsRepository;
use OddsCompare\Cache\ProviderState;
use OddsCompare\Cache\RefreshScheduler;
use OddsCompare\Provider\ProviderException;
use OddsCompare\Provider\ProviderInterface;
use OddsCompare\Tests\TestCase;

final class RefreshSchedulerTest extends TestCase {

	public function test_handler_refreshes_through_the_factory_and_swallows_provider_errors(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );

		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'key' )->andReturn( 'odds_api' );
		$provider->shouldReceive( 'events' )->once()->with( 'soccer_epl', array( 'h2h' ) )->andThrow( new ProviderException( 'nope' ) );
		$repository = new OddsRepository( $provider, new OddsCache( 60 ), new ProviderState(), 60 );

		$scheduler = new RefreshScheduler( static fn( string $key ) => 'odds_api' === $key ? $repository : null );

		$scheduler->handle( 'odds_api', 'soccer_epl', array( 'h2h' ) );
		$scheduler->handle( 'unknown', 'soccer_epl', array( 'h2h' ) );
		self::assertTrue( true, 'no exception escaped the cron handler' );
	}

	public function test_register_hooks_the_cron_action_and_clear_unschedules_it(): void {
		Functions\expect( 'add_action' )->once()->with( OddsRepository::CRON_HOOK, Mockery::type( 'array' ), 10, 3 );
		Functions\expect( 'wp_unschedule_hook' )->once()->with( OddsRepository::CRON_HOOK );

		( new RefreshScheduler( static fn() => null ) )->register();
		RefreshScheduler::clear();
	}
}
