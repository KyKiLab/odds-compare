<?php
/**
 * Cached provider result.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Cache;

use OddsCompare\Domain\Event;
use Throwable;

/**
 * Events for one provider/sport/markets combination plus when they were fetched. Stored as
 * plain arrays so a class rename in a future release cannot poison the cache.
 */
final class CacheEntry {

	/**
	 * Builds an entry.
	 *
	 * @param Event[] $events     Events as returned by the provider.
	 * @param int     $fetched_at Unix timestamp of the fetch.
	 */
	public function __construct(
		public readonly array $events,
		public readonly int $fetched_at,
	) {}

	/**
	 * Seconds since the fetch.
	 *
	 * @param int|null $now Reference time, defaults to now.
	 */
	public function age( ?int $now = null ): int {
		return max( 0, ( $now ?? time() ) - $this->fetched_at );
	}

	/**
	 * Younger than the freshness window.
	 *
	 * @param int      $fresh_ttl Freshness window in seconds.
	 * @param int|null $now       Reference time.
	 */
	public function is_fresh( int $fresh_ttl, ?int $now = null ): bool {
		return $this->age( $now ) < $fresh_ttl;
	}

	/**
	 * Plain-array form for the transient.
	 *
	 * @return array{events: array<int, array<string, mixed>>, fetched_at: int}
	 */
	public function to_array(): array {
		return array(
			'events'     => array_map( static fn( Event $event ) => $event->to_array(), $this->events ),
			'fetched_at' => $this->fetched_at,
		);
	}

	/**
	 * Inverse of to_array(); null when the stored value is not something we wrote.
	 *
	 * @param mixed $data Value from the transient.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! isset( $data['events'], $data['fetched_at'] ) || ! is_array( $data['events'] ) ) {
			return null;
		}

		try {
			$events = array_map( array( Event::class, 'from_array' ), $data['events'] );
		} catch ( Throwable ) {
			return null;
		}

		return new self( array_values( $events ), (int) $data['fetched_at'] );
	}
}
