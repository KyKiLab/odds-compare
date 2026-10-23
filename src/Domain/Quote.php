<?php
/**
 * A single price from one bookmaker.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Domain;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * One cell of the comparison: bookmaker x market x outcome.
 *
 * $point carries the line for totals ("Over 2.5") and spreads ("-1.5"); it is null for
 * head-to-head prices.
 */
final class Quote {

	/**
	 * Builds a quote.
	 *
	 * @param string                 $bookmaker_key Canonical bookmaker key.
	 * @param string                 $market_key    Market key: h2h, totals, spreads.
	 * @param string                 $outcome       Outcome name as the provider gives it (team name, "Draw", "Over").
	 * @param Odds                   $odds          The price.
	 * @param float|null             $point         Line for totals/spreads, null otherwise.
	 * @param DateTimeImmutable|null $updated_at    When the provider last saw this price.
	 */
	public function __construct(
		public readonly string $bookmaker_key,
		public readonly string $market_key,
		public readonly string $outcome,
		public readonly Odds $odds,
		public readonly ?float $point = null,
		public readonly ?DateTimeImmutable $updated_at = null,
	) {}

	/**
	 * Outcome plus line, e.g. "Over 2.5" or "Ipswich -1.5".
	 */
	public function label(): string {
		if ( null === $this->point ) {
			return $this->outcome;
		}

		$point = $this->point;
		// Spreads carry a sign, totals do not.
		$text = 'totals' === $this->market_key
			? rtrim( rtrim( number_format( $point, 2, '.', '' ), '0' ), '.' )
			: sprintf( '%+g', $point );

		return $this->outcome . ' ' . $text;
	}

	/**
	 * Plain-array form for the cache.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'bookmaker'  => $this->bookmaker_key,
			'market'     => $this->market_key,
			'outcome'    => $this->outcome,
			'decimal'    => $this->odds->to_decimal(),
			'point'      => $this->point,
			'updated_at' => $this->updated_at?->format( DateTimeInterface::ATOM ),
		);
	}

	/**
	 * Inverse of to_array(). Invalid odds are the caller's problem: the cache only ever
	 * holds values that passed validation on the way in.
	 *
	 * @param array<string, mixed> $data Array produced by to_array().
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) $data['bookmaker'],
			(string) $data['market'],
			(string) $data['outcome'],
			Odds::from_decimal( (float) $data['decimal'] ),
			isset( $data['point'] ) ? (float) $data['point'] : null,
			! empty( $data['updated_at'] ) ? new DateTimeImmutable( $data['updated_at'] ) : null,
		);
	}
}
