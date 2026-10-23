<?php
/**
 * Odds provider contract.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Provider;

use OddsCompare\Domain\Event;
use OddsCompare\Domain\Sport;

/**
 * A source of odds. Implementations normalise whatever the source returns into Event and
 * Quote objects with canonical bookmaker keys; the rest of the plugin never sees raw data.
 *
 * Register additional providers through the `odds_compare_providers` filter.
 */
interface ProviderInterface {

	/**
	 * Stable identifier used in settings and cache keys, e.g. "odds_api".
	 */
	public function key(): string;

	/**
	 * Display name for the admin screen.
	 */
	public function label(): string;

	/**
	 * Whether the provider has what it needs (API key etc.) to make requests.
	 */
	public function is_configured(): bool;

	/**
	 * Competitions available for the event picker.
	 *
	 * @throws ProviderException When the source cannot be reached.
	 *
	 * @return Sport[]
	 */
	public function sports(): array;

	/**
	 * Upcoming events for a competition with quotes for the requested markets.
	 *
	 * @param string   $sport_key   Provider's sport key.
	 * @param string[] $market_keys Market keys: h2h, totals, spreads.
	 *
	 * @throws ProviderException When the source is not configured, refuses the request or fails.
	 *
	 * @return Event[]
	 */
	public function events( string $sport_key, array $market_keys ): array;
}
