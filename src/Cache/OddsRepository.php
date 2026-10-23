<?php
/**
 * Odds repository.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Cache;

use OddsCompare\Provider\ProviderException;
use OddsCompare\Provider\ProviderInterface;
use OddsCompare\Support\Logger;

/**
 * The one entry point for "give me events": hides the provider behind the cache.
 *
 * Stale-while-revalidate: fresh data is served from cache; stale data is served immediately
 * and a background refresh is queued; only a cold cache fetches synchronously, under a lock
 * so concurrent requests do not stampede the provider. An open circuit breaker skips the
 * provider entirely. The front end therefore never waits on the network once anything has
 * been cached, and an idle site spends no API credits at all.
 */
final class OddsRepository {

	public const CRON_HOOK = 'odds_compare_refresh';

	/**
	 * Builds a repository.
	 *
	 * @param ProviderInterface $provider  Active provider.
	 * @param OddsCache         $cache     Cache.
	 * @param ProviderState     $state     Breaker/health state.
	 * @param int               $fresh_ttl Freshness window in seconds.
	 * @param Logger|null       $logger    Logger.
	 */
	public function __construct(
		private readonly ProviderInterface $provider,
		private readonly OddsCache $cache,
		private readonly ProviderState $state,
		private readonly int $fresh_ttl,
		private readonly ?Logger $logger = null,
	) {}

	/**
	 * Events for a sport, from cache when possible.
	 *
	 * @param string   $sport_key   Sport key.
	 * @param string[] $market_keys Market keys.
	 */
	public function events( string $sport_key, array $market_keys ): Result {
		$key   = OddsCache::key( $this->provider->key(), $sport_key, $market_keys );
		$entry = $this->cache->get( $key );

		if ( $entry && $entry->is_fresh( $this->fresh_ttl ) ) {
			return Result::fresh( $entry );
		}

		$breaker_open = $this->state->is_open( $this->provider->key() );

		if ( $entry ) {
			if ( ! $breaker_open ) {
				$this->schedule_refresh( $sport_key, $market_keys );
			}

			return Result::stale( $entry );
		}

		if ( $breaker_open ) {
			return Result::empty( $this->state->get( $this->provider->key() )['last_error'] ?? null );
		}

		if ( ! $this->cache->lock( $key ) ) {
			return Result::loading();
		}

		try {
			return Result::fresh( $this->refresh( $sport_key, $market_keys ) );
		} catch ( ProviderException $e ) {
			return Result::empty( $e->getMessage() );
		} finally {
			$this->cache->unlock( $key );
		}
	}

	/**
	 * Fetches from the provider now and stores the result. Used by the cron handler, by
	 * "Refresh now" in the admin screen, and for a cold cache.
	 *
	 * @param string   $sport_key   Sport key.
	 * @param string[] $market_keys Market keys.
	 *
	 * @throws ProviderException When the provider fails; the previous entry, if any, is kept.
	 */
	public function refresh( string $sport_key, array $market_keys ): CacheEntry {
		$provider_key = $this->provider->key();

		try {
			$events = $this->provider->events( $sport_key, $market_keys );
		} catch ( ProviderException $e ) {
			$this->state->record_failure( $provider_key, $e->getMessage() );
			$this->logger?->error(
				'Provider failed',
				array(
					'provider' => $provider_key,
					'sport'    => $sport_key,
					'error'    => $e->getMessage(),
				)
			);

			/**
			 * Fires when a provider fails to return odds.
			 *
			 * @param string            $provider_key Provider key.
			 * @param string            $sport_key    Sport key.
			 * @param ProviderException $exception    The failure.
			 */
			do_action( 'odds_compare_provider_failed', $provider_key, $sport_key, $e );

			throw $e;
		}//end try

		$entry = new CacheEntry( $events, time() );
		$this->cache->set(
			OddsCache::key( $provider_key, $sport_key, $market_keys ),
			$entry,
			array(
				'provider' => $provider_key,
				'sport'    => $sport_key,
				'markets'  => array_values( $market_keys ),
			)
		);
		$this->state->record_success( $provider_key );

		/**
		 * Fires after fresh odds have been stored.
		 *
		 * @param string   $provider_key Provider key.
		 * @param string   $sport_key    Sport key.
		 * @param string[] $market_keys  Market keys.
		 * @param Event[]  $events       The events.
		 */
		do_action( 'odds_compare_odds_refreshed', $provider_key, $sport_key, $market_keys, $events );

		return $entry;
	}

	/**
	 * Queues one background refresh. WP-Cron already ignores an identical event scheduled
	 * within ten minutes, and the explicit check keeps even that lookup cheap.
	 *
	 * @param string   $sport_key   Sport key.
	 * @param string[] $market_keys Market keys.
	 */
	private function schedule_refresh( string $sport_key, array $market_keys ): void {
		$args = array( $this->provider->key(), $sport_key, array_values( $market_keys ) );

		if ( wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			return;
		}

		wp_schedule_single_event( time(), self::CRON_HOOK, $args );
	}
}
