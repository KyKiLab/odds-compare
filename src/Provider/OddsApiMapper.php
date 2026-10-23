<?php
/**
 * The Odds API response mapper.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Provider;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use OddsCompare\Domain\Event;
use OddsCompare\Domain\Odds;
use OddsCompare\Domain\Quote;
use OddsCompare\Domain\Sport;
use OddsCompare\Support\Logger;

/**
 * Turns the /v4/sports/{sport}/odds JSON into Event/Quote objects.
 *
 * Kept separate from the provider so the mapping can be unit-tested against a saved
 * response and reused by the fixture provider, which ships the same JSON shape.
 */
final class OddsApiMapper {

	/**
	 * Builds a mapper.
	 *
	 * @param Logger|null $logger Logger for skipped records.
	 */
	public function __construct( private readonly ?Logger $logger = null ) {}

	/**
	 * Maps the /v4/sports list.
	 *
	 * @param array<int, array<string, mixed>> $data Decoded JSON.
	 *
	 * @return Sport[]
	 */
	public function sports( array $data ): array {
		$sports = array();
		foreach ( $data as $row ) {
			if ( empty( $row['key'] ) || empty( $row['title'] ) || empty( $row['active'] ) ) {
				continue;
			}
			$sports[] = new Sport( (string) $row['key'], (string) $row['title'], (string) ( $row['group'] ?? '' ) );
		}

		return $sports;
	}

	/**
	 * Maps the odds response for one sport.
	 *
	 * @param array<int, array<string, mixed>> $data Decoded JSON.
	 *
	 * @return Event[]
	 */
	public function events( array $data ): array {
		$events = array();
		foreach ( $data as $row ) {
			$event = $this->event( $row );
			if ( $event ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Maps one event, or null when the record is unusable.
	 *
	 * @param array<string, mixed> $row One event from the API.
	 */
	private function event( array $row ): ?Event {
		if ( empty( $row['id'] ) || empty( $row['home_team'] ) || empty( $row['away_team'] ) || empty( $row['commence_time'] ) ) {
			$this->logger?->warning( 'Skipping event with missing fields', array( 'id' => $row['id'] ?? null ) );
			return null;
		}

		try {
			$commence = new DateTimeImmutable( (string) $row['commence_time'] );
		} catch ( Exception ) {
			$this->logger?->warning( 'Skipping event with bad date', array( 'id' => $row['id'] ) );
			return null;
		}

		$quotes = array();
		$titles = array();
		foreach ( (array) ( $row['bookmakers'] ?? array() ) as $bookmaker ) {
			if ( empty( $bookmaker['key'] ) ) {
				continue;
			}
			$titles[ (string) $bookmaker['key'] ] = (string) ( $bookmaker['title'] ?? $bookmaker['key'] );
			$updated                              = $this->time( $bookmaker['last_update'] ?? null );

			foreach ( (array) ( $bookmaker['markets'] ?? array() ) as $market ) {
				// Exchange lay prices (h2h_lay etc.) are what you would offer, not take; they do not
				// belong in a back-odds comparison.
				if ( empty( $market['key'] ) || str_ends_with( (string) $market['key'], '_lay' ) ) {
					continue;
				}
				$market_updated = $this->time( $market['last_update'] ?? null ) ?? $updated;

				foreach ( (array) ( $market['outcomes'] ?? array() ) as $outcome ) {
					$quote = $this->quote( (string) $bookmaker['key'], (string) $market['key'], $outcome, $market_updated );
					if ( $quote ) {
						$quotes[] = $quote;
					}
				}
			}
		}//end foreach

		return new Event(
			(string) $row['id'],
			(string) ( $row['sport_key'] ?? '' ),
			(string) ( $row['sport_title'] ?? '' ),
			(string) $row['home_team'],
			(string) $row['away_team'],
			$commence,
			$quotes,
			$titles
		);
	}

	/**
	 * Maps one outcome, or null when the price is missing or invalid.
	 *
	 * @param string                 $bookmaker_key Bookmaker key.
	 * @param string                 $market_key    Market key.
	 * @param array<string, mixed>   $outcome       Outcome record.
	 * @param DateTimeImmutable|null $updated       Last update time.
	 */
	private function quote( string $bookmaker_key, string $market_key, array $outcome, ?DateTimeImmutable $updated ): ?Quote {
		if ( ! isset( $outcome['name'], $outcome['price'] ) ) {
			return null;
		}

		try {
			$odds = Odds::from_decimal( (float) $outcome['price'] );
		} catch ( InvalidArgumentException $e ) {
			$this->logger?->warning(
				'Skipping quote with invalid price',
				array(
					'bookmaker' => $bookmaker_key,
					'price'     => $outcome['price'],
				)
			);
			return null;
		}

		return new Quote(
			$bookmaker_key,
			$market_key,
			(string) $outcome['name'],
			$odds,
			isset( $outcome['point'] ) ? (float) $outcome['point'] : null,
			$updated
		);
	}

	/**
	 * Parses an ISO timestamp leniently.
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
