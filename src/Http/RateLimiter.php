<?php
/**
 * Request rate limiter.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Http;

/**
 * Sliding-window limiter on transients, one bucket per provider.
 *
 * This is a politeness limit for scraping, not a security control: get/set on a transient
 * is not atomic, so two concurrent cron runs can overshoot by a request. That is fine here.
 */
final class RateLimiter {

	/**
	 * Builds a limiter.
	 *
	 * @param array<string, int> $limits Bucket => max requests per window. Buckets not listed are unlimited.
	 * @param int                $window Window length in seconds.
	 */
	public function __construct(
		private readonly array $limits,
		private readonly int $window = 60,
	) {}

	/**
	 * Records a request if the bucket has room.
	 *
	 * @param string $bucket Bucket name, usually the provider key.
	 */
	public function allow( string $bucket ): bool {
		$limit = $this->limits[ $bucket ] ?? null;
		if ( null === $limit ) {
			return true;
		}

		$key  = $this->key( $bucket );
		$hits = $this->recent_hits( $key );

		if ( count( $hits ) >= $limit ) {
			return false;
		}

		$hits[] = time();
		set_transient( $key, $hits, $this->window );

		return true;
	}

	/**
	 * Seconds until the oldest request leaves the window.
	 *
	 * @param string $bucket Bucket name.
	 */
	public function retry_after( string $bucket ): int {
		$hits = $this->recent_hits( $this->key( $bucket ) );
		if ( ! $hits ) {
			return 0;
		}

		return max( 0, min( $hits ) + $this->window - time() );
	}

	/**
	 * Timestamps still inside the window.
	 *
	 * @param string $key Transient key.
	 *
	 * @return int[]
	 */
	private function recent_hits( string $key ): array {
		$since = time() - $this->window;
		$hits  = array_filter( (array) get_transient( $key ), static fn( $hit ) => (int) $hit > $since );

		return array_values( $hits );
	}

	/**
	 * Transient key for a bucket.
	 *
	 * @param string $bucket Bucket name.
	 */
	private function key( string $bucket ): string {
		return 'odds_compare_rl_' . sanitize_key( $bucket );
	}
}
