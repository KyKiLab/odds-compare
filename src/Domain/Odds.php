<?php
/**
 * Odds value object.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Domain;

use InvalidArgumentException;

/**
 * A single price, stored as decimal odds and convertible to any display format.
 *
 * Decimal is the canonical form because it is the only one with simple arithmetic:
 * comparison is numeric, implied probability is 1/decimal, and both other formats are pure
 * functions of it. Providers normalise to this on the way in so the rest of the plugin never
 * sees a "13/10" string.
 */
final class Odds {

	/**
	 * Decimal odds, strictly greater than 1.
	 *
	 * @var float
	 */
	private readonly float $decimal;

	/**
	 * Use the named constructors.
	 *
	 * @param float $decimal Validated decimal odds.
	 */
	private function __construct( float $decimal ) {
		$this->decimal = $decimal;
	}

	/**
	 * From decimal odds, e.g. 2.5.
	 *
	 * @param float $decimal Must be finite and greater than 1.0 (1.0 would mean a certain outcome).
	 *
	 * @throws InvalidArgumentException On an out-of-range value.
	 */
	public static function from_decimal( float $decimal ): self {
		if ( ! is_finite( $decimal ) || $decimal <= 1.0 ) {
			throw new InvalidArgumentException( sprintf( 'Decimal odds must be greater than 1.0, got %s.', $decimal ) );
		}

		return new self( $decimal );
	}

	/**
	 * From fractional odds: from_fractional( '5/2' ) or from_fractional( 5, 2 ).
	 *
	 * @param string|int $numerator   Fraction text, or the numerator when $denominator is given.
	 * @param int|null   $denominator Denominator, or null to parse $numerator as text.
	 *
	 * @throws InvalidArgumentException When the fraction is malformed or zero.
	 */
	public static function from_fractional( string|int $numerator, ?int $denominator = null ): self {
		$fraction = null === $denominator
			? Fraction::from_string( (string) $numerator )
			: new Fraction( (int) $numerator, $denominator );

		return self::from_decimal( 1 + $fraction->to_float() );
	}

	/**
	 * From American (moneyline) odds, e.g. +150 or -200.
	 *
	 * @param int $american Any value outside the open interval (-100, 100).
	 *
	 * @throws InvalidArgumentException For values between -100 and 100, which have no meaning.
	 */
	public static function from_american( int $american ): self {
		if ( abs( $american ) < 100 ) {
			throw new InvalidArgumentException( sprintf( 'American odds must be at least +100 or at most -100, got %d.', $american ) );
		}

		$decimal = $american > 0
			? 1 + $american / 100
			: 1 + 100 / abs( $american );

		return self::from_decimal( $decimal );
	}

	/**
	 * Raw decimal value, unrounded.
	 */
	public function to_decimal(): float {
		return $this->decimal;
	}

	/**
	 * Fractional text such as "3/2".
	 */
	public function to_fractional(): string {
		return (string) $this->to_fraction();
	}

	/**
	 * Fractional odds as a value object.
	 */
	public function to_fraction(): Fraction {
		return Fraction::from_float( $this->decimal - 1 );
	}

	/**
	 * American odds, rounded to a whole number.
	 */
	public function to_american(): int {
		if ( $this->decimal >= 2.0 ) {
			return (int) round( ( $this->decimal - 1 ) * 100 );
		}

		return (int) round( -100 / ( $this->decimal - 1 ) );
	}

	/**
	 * Bookmaker's implied probability of the outcome (ignores the overround).
	 */
	public function implied_probability(): float {
		return 1 / $this->decimal;
	}

	/**
	 * Text for display in the given format.
	 *
	 * @param OddsFormat $format Target format.
	 */
	public function format( OddsFormat $format ): string {
		return match ( $format ) {
			OddsFormat::Decimal    => number_format( $this->decimal, 2, '.', '' ),
			OddsFormat::Fractional => $this->to_fractional(),
			OddsFormat::American   => sprintf( '%+d', $this->to_american() ),
		};
	}

	/**
	 * Higher decimal odds pay more, so they are "better" for the punter.
	 *
	 * @param Odds $other Odds to compare against.
	 */
	public function is_better_than( Odds $other ): bool {
		return $this->decimal > $other->decimal;
	}

	/**
	 * Spaceship-style comparison for sorting: positive when $this pays more.
	 *
	 * @param Odds $other Odds to compare against.
	 */
	public function compare( Odds $other ): int {
		return $this->decimal <=> $other->decimal;
	}
}
