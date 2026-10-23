<?php
/**
 * Bookmaker identity.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Domain;

/**
 * A bookmaker as the plugin knows it: a canonical key shared by every provider, a display
 * title and the link visitors are sent to. Enabled/disabled state and affiliate overrides
 * live in settings, not here.
 */
final class Bookmaker {

	/**
	 * Builds a bookmaker.
	 *
	 * @param string      $key   Canonical key, e.g. "williamhill". Lower-case, [a-z0-9_:-].
	 * @param string      $title Display name.
	 * @param string|null $url   Link target, or null when none is known.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $title,
		public readonly ?string $url = null,
	) {}

	/**
	 * Copy with a different link, used to apply the affiliate URL from settings.
	 *
	 * @param string|null $url New link target.
	 */
	public function with_url( ?string $url ): self {
		return new self( $this->key, $this->title, $url );
	}

	/**
	 * Normalises a provider-specific code into a canonical key when no alias is known:
	 * "Betfair Sportsbook" => "betfair_sportsbook", "WH" => "wh".
	 *
	 * @param string $raw Code or title from a provider.
	 */
	public static function key_from( string $raw ): string {
		$key = strtolower( trim( $raw ) );
		$key = preg_replace( '/[^a-z0-9]+/', '_', $key );

		return trim( (string) $key, '_' );
	}
}
