<?php
/**
 * Sporting event with its quotes.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Domain;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * A fixture (home v away at a time) and every quote the provider returned for it.
 */
final class Event {

	/**
	 * Builds an event.
	 *
	 * @param string                $id            Provider's event id; opaque to the plugin.
	 * @param string                $sport_key     Provider's sport/league key, e.g. "soccer_epl".
	 * @param string                $sport_title   Human readable league name.
	 * @param string                $home_team     Home team.
	 * @param string                $away_team     Away team.
	 * @param DateTimeImmutable     $commence_time Kick-off, UTC.
	 * @param Quote[]               $quotes        Quotes across all markets and bookmakers.
	 * @param array<string, string> $bookmaker_titles Bookmaker key => title as the provider names it.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $sport_key,
		public readonly string $sport_title,
		public readonly string $home_team,
		public readonly string $away_team,
		public readonly DateTimeImmutable $commence_time,
		public readonly array $quotes = array(),
		public readonly array $bookmaker_titles = array(),
	) {}

	/**
	 * "Ipswich v Nottingham Forest".
	 */
	public function title(): string {
		return $this->home_team . ' v ' . $this->away_team;
	}

	/**
	 * Whether kick-off has passed.
	 *
	 * @param DateTimeImmutable|null $now Reference time, defaults to the current time.
	 */
	public function has_started( ?DateTimeImmutable $now = null ): bool {
		return $this->commence_time <= ( $now ?? new DateTimeImmutable() );
	}

	/**
	 * Quotes for one market only.
	 *
	 * @param string $market_key Market key.
	 *
	 * @return Quote[]
	 */
	public function quotes_for( string $market_key ): array {
		return array_values(
			array_filter( $this->quotes, static fn( Quote $quote ) => $quote->market_key === $market_key )
		);
	}

	/**
	 * Market keys present in the quotes, in order of first appearance.
	 *
	 * @return string[]
	 */
	public function markets(): array {
		return array_values( array_unique( array_map( static fn( Quote $quote ) => $quote->market_key, $this->quotes ) ) );
	}

	/**
	 * Bookmaker keys present in the quotes, in order of first appearance.
	 *
	 * @return string[]
	 */
	public function bookmakers(): array {
		return array_values( array_unique( array_map( static fn( Quote $quote ) => $quote->bookmaker_key, $this->quotes ) ) );
	}

	/**
	 * Copy with a different set of quotes.
	 *
	 * @param Quote[] $quotes New quotes.
	 */
	public function with_quotes( array $quotes ): self {
		return new self( $this->id, $this->sport_key, $this->sport_title, $this->home_team, $this->away_team, $this->commence_time, $quotes, $this->bookmaker_titles );
	}

	/**
	 * Plain-array form for the cache.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'            => $this->id,
			'sport_key'     => $this->sport_key,
			'sport_title'   => $this->sport_title,
			'home_team'     => $this->home_team,
			'away_team'     => $this->away_team,
			'commence_time' => $this->commence_time->format( DateTimeInterface::ATOM ),
			'quotes'        => array_map( static fn( Quote $quote ) => $quote->to_array(), $this->quotes ),
			'titles'        => $this->bookmaker_titles,
		);
	}

	/**
	 * Inverse of to_array().
	 *
	 * @param array<string, mixed> $data Array produced by to_array().
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) $data['id'],
			(string) $data['sport_key'],
			(string) ( $data['sport_title'] ?? '' ),
			(string) $data['home_team'],
			(string) $data['away_team'],
			new DateTimeImmutable( (string) $data['commence_time'] ),
			array_map( array( Quote::class, 'from_array' ), (array) ( $data['quotes'] ?? array() ) ),
			array_map( 'strval', (array) ( $data['titles'] ?? array() ) ),
		);
	}
}
