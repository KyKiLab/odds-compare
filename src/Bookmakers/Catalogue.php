<?php
/**
 * Bookmaker catalogue.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Bookmakers;

use OddsCompare\Domain\Bookmaker;

/**
 * Who the bookmakers are, independent of which provider delivered the odds.
 *
 * The same bookmaker is "williamhill" to The Odds API and "WH" to Oddschecker. Canonical keys
 * follow The Odds API naming; each entry can carry per-provider aliases so the admin's
 * enable/disable and affiliate settings survive a change of provider. Bookmakers that turn
 * up in provider data without a catalogue entry are remembered as "provider:code" and
 * listed in the admin screen, switched off, with the title the provider gave.
 */
final class Catalogue {

	public const SEEN_OPTION = 'odds_compare_seen_bookmakers';

	/**
	 * Canonical key => [ title, homepage, aliases ]. Generated from real feed data on 2026-10-22.
	 *
	 * @var array<string, array{0: string, 1: string|null, 2?: array<string, string>}>
	 */
	private const BUILT_IN = array(
		'ak_bets'        => array( 'AK Bets', 'https://akbets.com/', array( 'oddschecker' => 'AKB' ) ),
		'bet365'         => array( 'bet365', 'https://www.bet365.com/', array( 'oddschecker' => 'B3' ) ),
		'betano_uk'      => array( 'Betano', 'https://www.betano.co.uk/' ),
		'betfair_ex_uk'  => array( 'Betfair Exchange', 'https://www.betfair.com/exchange/', array( 'oddschecker' => 'BF' ) ),
		'betfair_sb_uk'  => array( 'Betfair Sportsbook', 'https://www.betfair.com/sport/', array( 'oddschecker' => 'FB' ) ),
		'betfred_uk'     => array( 'Betfred', 'https://www.betfred.com/', array( 'oddschecker' => 'FR' ) ),
		'betgoodwin'     => array( 'BetGoodwin', 'https://www.betgoodwin.co.uk/', array( 'oddschecker' => 'G5' ) ),
		'betmgm_uk'      => array( 'BetMGM', 'https://www.betmgm.co.uk/', array( 'oddschecker' => 'KN' ) ),
		'betvictor'      => array( 'BetVictor', 'https://www.betvictor.com/', array( 'oddschecker' => 'VC' ) ),
		'betway'         => array( 'Betway', 'https://betway.com/', array( 'oddschecker' => 'WA' ) ),
		'boylesports'    => array( 'BoyleSports', 'https://www.boylesports.com/', array( 'oddschecker' => 'BY' ) ),
		'casumo'         => array( 'Casumo', 'https://www.casumo.com/' ),
		'coral'          => array( 'Coral', 'https://sports.coral.co.uk/', array( 'oddschecker' => 'CE' ) ),
		'grosvenor'      => array( 'Grosvenor', 'https://www.grosvenorcasinos.com/sports' ),
		'ladbrokes_uk'   => array( 'Ladbrokes', 'https://sports.ladbrokes.com/', array( 'oddschecker' => 'LD' ) ),
		'leovegas'       => array( 'LeoVegas', 'https://www.leovegas.com/' ),
		'livescorebet'   => array( 'LiveScore Bet', 'https://www.livescorebet.com/' ),
		'matchbook'      => array( 'Matchbook', 'https://www.matchbook.com/', array( 'oddschecker' => 'MA' ) ),
		'paddypower'     => array( 'Paddy Power', 'https://www.paddypower.com/', array( 'oddschecker' => 'PP' ) ),
		'pricedup'       => array( 'PricedUp', 'https://www.pricedup.bet/', array( 'oddschecker' => 'PUP' ) ),
		'quinnbet'       => array( 'QuinnBet', 'https://www.quinnbet.com/', array( 'oddschecker' => 'QN' ) ),
		'skybet'         => array( 'Sky Bet', 'https://m.skybet.com/', array( 'oddschecker' => 'SK' ) ),
		'smarkets'       => array( 'Smarkets', 'https://smarkets.com/', array( 'oddschecker' => 'SKM' ) ),
		'sport888'       => array( '888sport', 'https://www.888sport.com/' ),
		'sporting_index' => array( 'Sporting Index', 'https://www.sportingindex.com/', array( 'oddschecker' => 'SI' ) ),
		'spreadex'       => array( 'Spreadex', 'https://www.spreadex.com/', array( 'oddschecker' => 'SX' ) ),
		'star_sports'    => array( 'Star Sports', 'https://www.starsports.bet/', array( 'oddschecker' => 'S6' ) ),
		'tenbet'         => array( '10bet', 'https://www.10bet.co.uk/', array( 'oddschecker' => 'OE' ) ),
		'unibet_uk'      => array( 'Unibet', 'https://www.unibet.co.uk/', array( 'oddschecker' => 'UN' ) ),
		'virginbet'      => array( 'Virgin Bet', 'https://www.virginbet.com/' ),
		'williamhill'    => array( 'William Hill', 'https://www.williamhill.com/', array( 'oddschecker' => 'WH' ) ),
	);

	/**
	 * Built-in plus filtered entries, keyed by canonical key.
	 *
	 * @var array<string, array{0: string, 1: string|null, 2?: array<string, string>}>|null
	 */
	private ?array $entries = null;

	/**
	 * Alias lookup: "provider:CODE" => canonical key.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $aliases = null;

	/**
	 * Catalogue entries after the filter.
	 *
	 * @return array<string, array{0: string, 1: string|null, 2?: array<string, string>}>
	 */
	private function entries(): array {
		if ( null === $this->entries ) {
			/**
			 * Filters the bookmaker catalogue.
			 *
			 * Each entry is [ title, homepage URL or null, optional aliases keyed by provider ].
			 *
			 * @param array<string, array> $entries Canonical key => entry.
			 */
			$filtered      = apply_filters( 'odds_compare_bookmakers', self::BUILT_IN );
			$this->entries = is_array( $filtered ) ? $filtered : self::BUILT_IN;
		}

		return $this->entries;
	}

	/**
	 * Every built-in bookmaker.
	 *
	 * @return array<string, Bookmaker>
	 */
	public function all(): array {
		$bookmakers = array();
		foreach ( $this->entries() as $key => $entry ) {
			$bookmakers[ $key ] = new Bookmaker( (string) $key, (string) $entry[0], $entry[1] ?? null );
		}

		return $bookmakers;
	}

	/**
	 * Built-in plus discovered bookmakers, keyed.
	 *
	 * @return array<string, Bookmaker>
	 */
	public function all_with_seen(): array {
		$bookmakers = $this->all();
		foreach ( $this->seen() as $key => $title ) {
			if ( ! isset( $bookmakers[ $key ] ) ) {
				$bookmakers[ $key ] = new Bookmaker( $key, $title );
			}
		}

		return $bookmakers;
	}

	/**
	 * One bookmaker by canonical key, built-in or discovered, or null.
	 *
	 * @param string $key Canonical key.
	 */
	public function get( string $key ): ?Bookmaker {
		return $this->all_with_seen()[ $key ] ?? null;
	}

	/**
	 * Whether the key ships with the plugin (or was added through the filter).
	 *
	 * @param string $key Canonical key.
	 */
	public function is_built_in( string $key ): bool {
		return isset( $this->entries()[ $key ] );
	}

	/**
	 * Canonical key for a provider's bookmaker code.
	 *
	 * @param string $provider_key Provider key, e.g. "oddschecker".
	 * @param string $code         The provider's code, e.g. "WH".
	 */
	public function canonical_key( string $provider_key, string $code ): string {
		if ( null === $this->aliases ) {
			$this->aliases = array();
			foreach ( $this->entries() as $key => $entry ) {
				foreach ( (array) ( $entry[2] ?? array() ) as $provider => $alias ) {
					$this->aliases[ $provider . ':' . strtoupper( (string) $alias ) ] = (string) $key;
				}
			}
		}

		return $this->aliases[ $provider_key . ':' . strtoupper( $code ) ]
			?? $provider_key . ':' . Bookmaker::key_from( $code );
	}

	/**
	 * Display title for a key, falling back to the key.
	 *
	 * @param string $key Canonical key.
	 */
	public function title( string $key ): string {
		return $this->get( $key )?->title ?? $key;
	}

	/**
	 * Discovered bookmakers: key => title.
	 *
	 * @return array<string, string>
	 */
	public function seen(): array {
		$seen = get_option( self::SEEN_OPTION, array() );

		return is_array( $seen ) ? array_map( 'strval', $seen ) : array();
	}

	/**
	 * Records bookmakers from provider data that the catalogue does not know, so the admin
	 * can switch them on. Known keys are ignored; the option is only written when something new appears.
	 *
	 * @param array<string, string> $bookmakers Canonical key => title as the provider calls it.
	 */
	public function remember_seen( array $bookmakers ): void {
		$seen  = $this->seen();
		$added = false;

		foreach ( $bookmakers as $key => $title ) {
			$key = (string) $key;
			if ( '' === $key || $this->is_built_in( $key ) || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = sanitize_text_field( (string) $title );
			$added        = true;
		}

		if ( $added ) {
			update_option( self::SEEN_OPTION, $seen, false );
		}
	}
}
