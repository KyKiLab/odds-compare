<?php
/**
 * Transient-backed cache for provider results.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Cache;

/**
 * Stores CacheEntry objects in transients and keeps an index of its keys.
 *
 * Transients because they are the WordPress cache API that works everywhere: in wp_options
 * on a bare install, in Redis/Memcached when a persistent object cache is present. The index
 * exists because there is no portable way to list transients by prefix; a LIKE query on
 * wp_options finds nothing once an object cache is in play.
 */
final class OddsCache {

	public const INDEX_OPTION = 'odds_compare_cache_index';

	private const PREFIX      = 'odds_compare_ev_';
	private const LOCK_PREFIX = 'odds_compare_lock_';
	private const LOCK_GROUP  = 'odds_compare';

	/**
	 * Builds a cache.
	 *
	 * @param int $stale_ttl How long an entry stays available at all, in seconds.
	 */
	public function __construct( private readonly int $stale_ttl ) {}

	/**
	 * Deterministic key for a provider/sport/markets combination.
	 *
	 * @param string   $provider_key Provider key.
	 * @param string   $sport_key    Sport key.
	 * @param string[] $market_keys  Market keys, any order.
	 */
	public static function key( string $provider_key, string $sport_key, array $market_keys ): string {
		sort( $market_keys );

		return self::PREFIX . substr( md5( $provider_key . '|' . $sport_key . '|' . implode( ',', $market_keys ) ), 0, 20 );
	}

	/**
	 * Entry for a key, or null when missing or unreadable.
	 *
	 * @param string $key Cache key.
	 */
	public function get( string $key ): ?CacheEntry {
		return CacheEntry::from_array( get_transient( $key ) );
	}

	/**
	 * Stores an entry for the stale TTL and records the key with what it holds.
	 *
	 * @param string               $key   Cache key.
	 * @param CacheEntry           $entry Entry.
	 * @param array<string, mixed> $meta  Provider/sport/markets the entry was fetched for.
	 */
	public function set( string $key, CacheEntry $entry, array $meta = array() ): void {
		set_transient( $key, $entry->to_array(), $this->stale_ttl );
		$this->index_add( $key, $meta );
	}

	/**
	 * Records a key in the index option.
	 *
	 * @param string               $key  Transient key.
	 * @param array<string, mixed> $meta What the key holds; empty for memoised values.
	 */
	private function index_add( string $key, array $meta = array() ): void {
		$index = $this->entries();
		if ( ! array_key_exists( $key, $index ) || $index[ $key ] !== $meta ) {
			$index[ $key ] = $meta;
			update_option( self::INDEX_OPTION, $index, false );
		}
	}

	/**
	 * Memoises an arbitrary array (e.g. the sports list) under the same index, so purge()
	 * clears it too. The producer runs only on a miss; its exceptions propagate.
	 *
	 * @param string   $key      Transient key, must start with the plugin prefix.
	 * @param int      $ttl      Lifetime in seconds.
	 * @param callable $producer Returns the array to cache.
	 *
	 * @return array<mixed>
	 */
	public function remember( string $key, int $ttl, callable $producer ): array {
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$value = (array) $producer();
		set_transient( $key, $value, $ttl );
		$this->index_add( $key );

		return $value;
	}

	/**
	 * Every key this cache has written.
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return array_keys( $this->entries() );
	}

	/**
	 * Index: key => meta (provider, sport, markets) or an empty array for memoised values.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function entries(): array {
		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			return array();
		}

		$entries = array();
		foreach ( $index as $key => $meta ) {
			if ( is_int( $key ) && is_string( $meta ) ) {
				// Index written by 1.0.0-dev as a plain list of keys.
				$entries[ $meta ] = array();
			} elseif ( is_string( $key ) ) {
				$entries[ $key ] = is_array( $meta ) ? $meta : array();
			}
		}

		return $entries;
	}

	/**
	 * Drops every entry this cache wrote. Other plugins' transients are not touched.
	 *
	 * @return int Number of entries removed.
	 */
	public function purge(): int {
		$keys = $this->keys();
		foreach ( $keys as $key ) {
			delete_transient( $key );
		}
		delete_option( self::INDEX_OPTION );

		/**
		 * Fires after the odds cache has been emptied.
		 *
		 * @param string[] $keys Keys that were removed.
		 */
		do_action( 'odds_compare_cache_purged', $keys );

		return count( $keys );
	}

	/**
	 * Takes a short lock so only one process fetches a given key.
	 *
	 * With a persistent object cache wp_cache_add() is atomic, which is what a lock wants.
	 * Without one it only lives for the request, so a transient stands in; its get/set race
	 * is tolerable because the worst case is two fetches instead of one.
	 *
	 * @param string $key Cache key being fetched.
	 * @param int    $ttl Seconds the lock lives if never released.
	 */
	public function lock( string $key, int $ttl = 60 ): bool {
		$lock = self::LOCK_PREFIX . $key;

		if ( wp_using_ext_object_cache() ) {
			return (bool) wp_cache_add( $lock, time(), self::LOCK_GROUP, $ttl );
		}

		if ( false !== get_transient( $lock ) ) {
			return false;
		}
		set_transient( $lock, time(), $ttl );

		return true;
	}

	/**
	 * Releases a lock taken with lock().
	 *
	 * @param string $key Cache key.
	 */
	public function unlock( string $key ): void {
		$lock = self::LOCK_PREFIX . $key;

		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $lock, self::LOCK_GROUP );
			return;
		}
		delete_transient( $lock );
	}
}
