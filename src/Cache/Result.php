<?php
/**
 * Repository lookup result.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Cache;

use OddsCompare\Domain\Event;

/**
 * What the block gets back: events plus how much to trust them.
 */
final class Result {

	public const FRESH   = 'fresh';
	public const STALE   = 'stale';
	public const LOADING = 'loading';
	public const EMPTY   = 'empty';

	/**
	 * Builds a result.
	 *
	 * @param string      $status     One of the status constants.
	 * @param Event[]     $events     Events, possibly empty.
	 * @param int|null    $fetched_at When the events were fetched, if known.
	 * @param string|null $error      Why there is no data, for the editor/admin.
	 */
	private function __construct(
		public readonly string $status,
		public readonly array $events = array(),
		public readonly ?int $fetched_at = null,
		public readonly ?string $error = null,
	) {}

	/**
	 * Data within the freshness window.
	 *
	 * @param CacheEntry $entry Cache entry.
	 */
	public static function fresh( CacheEntry $entry ): self {
		return new self( self::FRESH, $entry->events, $entry->fetched_at );
	}

	/**
	 * Data past the freshness window; a refresh has been queued.
	 *
	 * @param CacheEntry $entry Cache entry.
	 */
	public static function stale( CacheEntry $entry ): self {
		return new self( self::STALE, $entry->events, $entry->fetched_at );
	}

	/**
	 * Another process is fetching right now.
	 */
	public static function loading(): self {
		return new self( self::LOADING );
	}

	/**
	 * Nothing cached and nothing fetched.
	 *
	 * @param string|null $error Reason, if any.
	 */
	public static function empty( ?string $error = null ): self {
		return new self( self::EMPTY, array(), null, $error );
	}

	/**
	 * Whether the data is older than the freshness window.
	 */
	public function is_stale(): bool {
		return self::STALE === $this->status;
	}

	/**
	 * Whether there is anything to show.
	 */
	public function has_events(): bool {
		return array() !== $this->events;
	}

	/**
	 * Event by provider id, or null.
	 *
	 * @param string $id Event id.
	 */
	public function find( string $id ): ?Event {
		foreach ( $this->events as $event ) {
			if ( $event->id === $id ) {
				return $event;
			}
		}

		return null;
	}
}
