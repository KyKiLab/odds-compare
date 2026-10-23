<?php
/**
 * Maps Oddschecker page state to domain objects.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Provider\Oddschecker;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use OddsCompare\Bookmakers\Catalogue;
use OddsCompare\Domain\Event;
use OddsCompare\Domain\Odds;
use OddsCompare\Domain\Quote;
use OddsCompare\Support\Logger;

/**
 * Two blobs matter: "competitionsaccumulatormatches" on a league page lists the fixtures,
 * "subeventmarkets" on a match page carries every bookmaker's price per outcome.
 */
final class Parser {

	public const PROVIDER_KEY = 'oddschecker';

	/**
	 * Oddschecker market type name => plugin market key. The /winner page only carries the
	 * match-result market; totals and handicaps live on other pages and are not fetched.
	 *
	 * @var array<string, string>
	 */
	private const MARKET_TYPES = array(
		'Win Market' => 'h2h',
	);

	/**
	 * Builds a parser.
	 *
	 * @param Catalogue   $catalogue For bookmaker code => canonical key.
	 * @param Logger|null $logger    Logger for skipped records.
	 */
	public function __construct(
		private readonly Catalogue $catalogue,
		private readonly ?Logger $logger = null,
	) {}

	/**
	 * Fixtures from a league page, keyed by their URL slug, kick-off order, without quotes.
	 *
	 * Every fixture appears twice (pre-match and in-play "siblings", flagged isLive); the
	 * pre-match one is kept.
	 *
	 * @param array<mixed> $blob        Decoded "competitionsaccumulatormatches" props.
	 * @param string       $sport_key   Plugin sport key.
	 * @param string       $sport_title League title.
	 *
	 * @return array<string, Event>
	 */
	public function league_events( array $blob, string $sport_key, string $sport_title ): array {
		$rows = array();
		foreach ( (array) ( $blob['subevents']['entities'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['urlMap'] ) || empty( $row['homeTeamName'] ) || empty( $row['awayTeamName'] ) || empty( $row['startTime'] ) ) {
				continue;
			}
			$slug = (string) $row['urlMap'];
			if ( isset( $rows[ $slug ] ) && empty( $rows[ $slug ]['isLive'] ) ) {
				continue;
			}
			$rows[ $slug ] = $row;
		}

		$events = array();
		foreach ( $rows as $slug => $row ) {
			try {
				$time = new DateTimeImmutable( (string) $row['startTime'] );
			} catch ( Exception ) {
				$this->logger?->warning( 'Oddschecker fixture with bad date skipped', array( 'slug' => $slug ) );
				continue;
			}

			$events[ $slug ] = new Event(
				(string) ( $row['id'] ?? $slug ),
				$sport_key,
				$sport_title,
				(string) $row['homeTeamName'],
				(string) $row['awayTeamName'],
				$time
			);
		}

		uasort( $events, static fn( Event $a, Event $b ) => $a->commence_time <=> $b->commence_time );

		return $events;
	}

	/**
	 * Quotes and bookmaker titles from a match page.
	 *
	 * @param array<mixed> $blob        Decoded "subeventmarkets" props.
	 * @param string[]     $market_keys Markets wanted.
	 *
	 * @return array{quotes: Quote[], titles: array<string, string>}
	 */
	public function match_quotes( array $blob, array $market_keys ): array {
		$best = (array) ( $blob['bestOdds'] ?? array() );

		$titles = array();
		foreach ( (array) ( $best['bookmakers']['entities'] ?? array() ) as $code => $bookmaker ) {
			$titles[ $this->catalogue->canonical_key( self::PROVIDER_KEY, (string) $code ) ] = (string) ( $bookmaker['bookmakerName'] ?? $code );
		}

		/**
		 * Filters the Oddschecker market type => market key map.
		 *
		 * @param array<string, string> $types Market type name as Oddschecker labels it => plugin market key.
		 */
		$types = (array) apply_filters( 'odds_compare_oddschecker_market_types', self::MARKET_TYPES );

		$market_by_id = array();
		foreach ( (array) ( $best['markets']['entities'] ?? array() ) as $id => $market ) {
			$key = $types[ (string) ( $market['marketTypeName'] ?? '' ) ] ?? null;
			if ( $key && in_array( $key, $market_keys, true ) ) {
				$market_by_id[ (string) $id ] = $key;
			}
		}

		$quotes = array();
		foreach ( (array) ( $best['bets']['entities'] ?? array() ) as $bet_id => $bet ) {
			$market = $market_by_id[ (string) ( $bet['marketId'] ?? '' ) ] ?? null;
			if ( null === $market || empty( $bet['betName'] ) ) {
				continue;
			}
			$line  = $bet['line'] ?? null;
			$point = ( null === $line || '' === $line ) ? null : (float) $line;

			foreach ( (array) ( $best['odds'][ $bet_id ] ?? array() ) as $code => $price ) {
				if ( 'ACTIVE' !== ( $price['status'] ?? '' ) || ! empty( $price['expired'] ) ) {
					continue;
				}
				try {
					$odds = Odds::from_decimal( (float) ( $price['oddsDecimal'] ?? 0 ) );
				} catch ( InvalidArgumentException ) {
					continue;
				}

				$quotes[] = new Quote(
					$this->catalogue->canonical_key( self::PROVIDER_KEY, (string) $code ),
					$market,
					(string) $bet['betName'],
					$odds,
					$point,
					$this->time( $price['betFeedTimestamp'] ?? null )
				);
			}
		}//end foreach

		return array(
			'quotes' => $quotes,
			'titles' => $titles,
		);
	}

	/**
	 * Parses a feed timestamp leniently.
	 *
	 * @param mixed $value Timestamp or null.
	 */
	private function time( mixed $value ): ?DateTimeImmutable {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $value );
		} catch ( Exception ) {
			return null;
		}
	}
}
