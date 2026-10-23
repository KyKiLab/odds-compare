<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Cache;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use Mockery;
use OddsCompare\Cache\CacheEntry;
use OddsCompare\Cache\OddsCache;
use OddsCompare\Cache\OddsRepository;
use OddsCompare\Cache\ProviderState;
use OddsCompare\Cache\Result;
use OddsCompare\Domain\Event;
use OddsCompare\Provider\ProviderException;
use OddsCompare\Provider\ProviderInterface;
use OddsCompare\Tests\TestCase;

final class OddsRepositoryTest extends TestCase {

	/** @var array<string, mixed> */
	private array $transients = array();

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, array<mixed>> */
	private array $scheduled = array();

	protected function setUp(): void {
		parent::setUp();
		$transients = &$this->transients;
		$options    = &$this->options;
		$scheduled  = &$this->scheduled;

		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) use ( &$transients ) {
				return $transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ) use ( &$transients ): bool {
				$transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( string $key ) use ( &$transients ): bool {
				unset( $transients[ $key ] );
				return true;
			}
		);
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
		Functions\when( 'delete_option' )->alias(
			static function ( string $key ) use ( &$options ): bool {
				unset( $options[ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->alias(
			static function ( string $hook, array $args ) use ( &$scheduled ) {
				foreach ( $scheduled as $event ) {
					if ( array( $hook, $args ) === $event ) {
						return time();
					}
				}
				return false;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( int $when, string $hook, array $args ) use ( &$scheduled ): bool {
				$scheduled[] = array( $hook, $args );
				return true;
			}
		);
	}

	private function event(): Event {
		return new Event( 'e1', 'soccer_epl', 'EPL', 'A', 'B', new DateTimeImmutable( '2030-01-01T12:00:00Z' ) );
	}

	/**
	 * @return ProviderInterface&Mockery\MockInterface
	 */
	private function provider( ?int $calls = 0 ) {
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'key' )->andReturn( 'test' );
		if ( null !== $calls ) {
			$provider->shouldReceive( 'events' )->times( $calls )->andReturn( array( $this->event() ) );
		}

		return $provider;
	}

	private function repository( ProviderInterface $provider, int $fresh_ttl = 7200 ): OddsRepository {
		return new OddsRepository( $provider, new OddsCache( 86400 ), new ProviderState(), $fresh_ttl );
	}

	private function seed( int $age ): void {
		$this->transients[ OddsCache::key( 'test', 'soccer_epl', array( 'h2h' ) ) ] = ( new CacheEntry( array( $this->event() ), time() - $age ) )->to_array();
	}

	public function test_fresh_cache_is_served_without_touching_the_provider(): void {
		$this->seed( 60 );

		$result = $this->repository( $this->provider( 0 ) )->events( 'soccer_epl', array( 'h2h' ) );

		self::assertSame( Result::FRESH, $result->status );
		self::assertCount( 1, $result->events );
		self::assertSame( array(), $this->scheduled );
	}

	public function test_stale_cache_is_served_and_one_refresh_is_queued(): void {
		$this->seed( 7200 + 1 );
		$repository = $this->repository( $this->provider( 0 ) );

		$first  = $repository->events( 'soccer_epl', array( 'h2h' ) );
		$second = $repository->events( 'soccer_epl', array( 'h2h' ) );

		self::assertSame( Result::STALE, $first->status );
		self::assertTrue( $first->is_stale() );
		self::assertSame( 'e1', $second->find( 'e1' )->id );
		self::assertCount( 1, $this->scheduled, 'second request must not queue a duplicate' );
		self::assertSame( array( OddsRepository::CRON_HOOK, array( 'test', 'soccer_epl', array( 'h2h' ) ) ), $this->scheduled[0] );
	}

	public function test_cold_cache_fetches_synchronously_under_a_lock(): void {
		Actions\expectDone( 'odds_compare_odds_refreshed' )->once();

		$result = $this->repository( $this->provider( 1 ) )->events( 'soccer_epl', array( 'h2h' ) );

		self::assertSame( Result::FRESH, $result->status );
		self::assertArrayHasKey( OddsCache::key( 'test', 'soccer_epl', array( 'h2h' ) ), $this->transients );
		self::assertArrayNotHasKey( 'odds_compare_lock_' . OddsCache::key( 'test', 'soccer_epl', array( 'h2h' ) ), $this->transients, 'lock released' );
		self::assertSame(
			array(
				OddsCache::key( 'test', 'soccer_epl', array( 'h2h' ) ) => array(
					'provider' => 'test',
					'sport'    => 'soccer_epl',
					'markets'  => array( 'h2h' ),
				),
			),
			$this->options[ OddsCache::INDEX_OPTION ]
		);
	}

	public function test_cold_cache_with_lock_held_returns_loading(): void {
		$this->transients[ 'odds_compare_lock_' . OddsCache::key( 'test', 'soccer_epl', array( 'h2h' ) ) ] = time();

		$result = $this->repository( $this->provider( 0 ) )->events( 'soccer_epl', array( 'h2h' ) );

		self::assertSame( Result::LOADING, $result->status );
		self::assertFalse( $result->has_events() );
	}

	public function test_provider_failure_on_cold_cache_is_reported_and_recorded(): void {
		Actions\expectDone( 'odds_compare_provider_failed' )->once();
		$provider = $this->provider( null );
		$provider->shouldReceive( 'events' )->once()->andThrow( new ProviderException( 'boom', true ) );

		$result = $this->repository( $provider )->events( 'soccer_epl', array( 'h2h' ) );

		self::assertSame( Result::EMPTY, $result->status );
		self::assertSame( 'boom', $result->error );
		self::assertSame( 1, $this->options[ ProviderState::OPTION ]['test']['failures'] );
	}

	public function test_breaker_opens_after_three_failures_and_blocks_calls(): void {
		$provider = $this->provider( null );
		$provider->shouldReceive( 'events' )->times( 3 )->andThrow( new ProviderException( 'down', true ) );
		$repository = $this->repository( $provider );

		for ( $i = 0; $i < 3; $i++ ) {
			$repository->events( 'soccer_epl', array( 'h2h' ) );
		}
		$blocked = $repository->events( 'soccer_epl', array( 'h2h' ) );

		self::assertSame( Result::EMPTY, $blocked->status );
		self::assertSame( 'down', $blocked->error );
		self::assertTrue( ( new ProviderState() )->is_open( 'test' ) );

		// Stale data keeps being served while open, without queuing refreshes.
		$this->seed( 99999 );
		$stale = $repository->events( 'soccer_epl', array( 'h2h' ) );
		self::assertSame( Result::STALE, $stale->status );
		self::assertSame( array(), $this->scheduled );
	}

	public function test_success_closes_the_breaker(): void {
		$state = new ProviderState();
		$state->record_failure( 'test', 'x' );
		$state->record_failure( 'test', 'x' );
		$state->record_failure( 'test', 'x' );
		self::assertTrue( $state->is_open( 'test' ) );

		$this->repository( $this->provider( 0 ) ); // no call: breaker is open.
		$state->record_success( 'test' );

		self::assertFalse( $state->is_open( 'test' ) );
		self::assertSame( 0, $state->get( 'test' )['failures'] );
	}

	public function test_purge_removes_only_indexed_keys(): void {
		Actions\expectDone( 'odds_compare_cache_purged' )->once();
		$this->repository( $this->provider( 1 ) )->events( 'soccer_epl', array( 'h2h' ) );
		$this->transients['someone_elses'] = 'keep me';

		$removed = ( new OddsCache( 86400 ) )->purge();

		self::assertSame( 1, $removed );
		self::assertSame( array( 'someone_elses' => 'keep me' ), $this->transients );
		self::assertArrayNotHasKey( OddsCache::INDEX_OPTION, $this->options );
	}

	public function test_corrupt_transient_is_treated_as_a_miss(): void {
		$this->transients[ OddsCache::key( 'test', 'soccer_epl', array( 'h2h' ) ) ] = 'garbage';

		$result = $this->repository( $this->provider( 1 ) )->events( 'soccer_epl', array( 'h2h' ) );

		self::assertSame( Result::FRESH, $result->status );
	}

	public function test_cache_key_ignores_market_order(): void {
		self::assertSame(
			OddsCache::key( 'p', 's', array( 'totals', 'h2h' ) ),
			OddsCache::key( 'p', 's', array( 'h2h', 'totals' ) )
		);
		self::assertNotSame( OddsCache::key( 'p', 's', array( 'h2h' ) ), OddsCache::key( 'q', 's', array( 'h2h' ) ) );
	}
}
