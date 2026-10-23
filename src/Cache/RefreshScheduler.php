<?php
/**
 * WP-Cron glue for background refreshes.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Cache;

use Closure;
use OddsCompare\Provider\ProviderException;

/**
 * Runs the refresh queued by OddsRepository. The repository is built lazily through a
 * factory so the handler always sees current settings, even if they changed since the
 * event was queued.
 */
final class RefreshScheduler {

	/**
	 * Builds the scheduler.
	 *
	 * @param Closure $repository_factory fn( string $provider_key ): ?OddsRepository.
	 */
	public function __construct( private readonly Closure $repository_factory ) {}

	/**
	 * Hooks the cron handler.
	 */
	public function register(): void {
		add_action( OddsRepository::CRON_HOOK, array( $this, 'handle' ), 10, 3 );
	}

	/**
	 * Cron callback.
	 *
	 * @param string   $provider_key Provider the refresh was queued for.
	 * @param string   $sport_key    Sport key.
	 * @param string[] $market_keys  Market keys.
	 */
	public function handle( string $provider_key, string $sport_key, array $market_keys ): void {
		$repository = ( $this->repository_factory )( $provider_key );
		if ( ! $repository ) {
			return;
		}

		try {
			$repository->refresh( $sport_key, $market_keys );
		} catch ( ProviderException ) {
			// Already recorded and logged by the repository; stale data stays in place.
			return;
		}
	}

	/**
	 * Drops every queued refresh. Called on deactivation.
	 */
	public static function clear(): void {
		wp_unschedule_hook( OddsRepository::CRON_HOOK );
	}
}
