<?php
/**
 * Reduced fraction used for fractional odds.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Domain;

use InvalidArgumentException;

/**
 * Immutable numerator/denominator pair, always stored in lowest terms.
 */
final class Fraction {

	/**
	 * Fractions UK bookmakers quote in a non-reduced form. 2/3 is never shown on a coupon; 4/6 is.
	 *
	 * @var array<string, string>
	 */
	private const DISPLAY_ALIASES = array(
		'2/3' => '4/6',
	);

	/**
	 * Traditional UK price ladder, odds-on first. Not exhaustive: x/10 prices like 23/10 are
	 * found by the fallback search, which handles anything with a small denominator.
	 *
	 * @var string[]
	 */
	private const PRICE_LADDER = array(
		'1/100',
		'1/50',
		'1/40',
		'1/33',
		'1/25',
		'1/20',
		'1/16',
		'1/14',
		'1/12',
		'1/11',
		'1/10',
		'1/9',
		'1/8',
		'1/7',
		'1/6',
		'2/11',
		'1/5',
		'2/9',
		'1/4',
		'2/7',
		'3/10',
		'1/3',
		'4/11',
		'2/5',
		'4/9',
		'1/2',
		'8/15',
		'4/7',
		'8/13',
		'4/6',
		'8/11',
		'4/5',
		'5/6',
		'10/11',
		'1/1',
		'21/20',
		'11/10',
		'6/5',
		'5/4',
		'13/10',
		'11/8',
		'7/5',
		'6/4',
		'8/5',
		'13/8',
		'7/4',
		'9/5',
		'15/8',
		'2/1',
		'11/5',
		'9/4',
		'12/5',
		'5/2',
		'13/5',
		'11/4',
		'14/5',
		'3/1',
		'16/5',
		'10/3',
		'7/2',
		'4/1',
		'9/2',
		'5/1',
		'11/2',
		'6/1',
		'13/2',
		'7/1',
		'15/2',
		'8/1',
		'17/2',
		'9/1',
		'10/1',
		'11/1',
		'12/1',
		'14/1',
		'16/1',
		'18/1',
		'20/1',
		'22/1',
		'25/1',
		'28/1',
		'33/1',
		'40/1',
		'50/1',
		'66/1',
		'80/1',
		'100/1',
		'125/1',
		'150/1',
		'200/1',
		'250/1',
		'500/1',
		'1000/1',
	);

	/**
	 * Fallback search order: the denominators people read without thinking come first.
	 *
	 * @var int[]
	 */
	private const FALLBACK_DENOMINATORS = array( 1, 2, 4, 5, 10, 20, 3, 6, 8, 12, 16, 7, 9, 11, 13, 14, 15, 17, 18, 19, 25, 50, 100 );

	/**
	 * Numerator, >= 0.
	 *
	 * @var int
	 */
	public readonly int $numerator;

	/**
	 * Denominator, > 0.
	 *
	 * @var int
	 */
	public readonly int $denominator;

	/**
	 * Builds and reduces the fraction.
	 *
	 * @param int $numerator   Numerator, must be >= 0.
	 * @param int $denominator Denominator, must be > 0.
	 *
	 * @throws InvalidArgumentException On a zero/negative denominator or a negative numerator.
	 */
	public function __construct( int $numerator, int $denominator ) {
		if ( $denominator <= 0 ) {
			throw new InvalidArgumentException( sprintf( 'Fraction denominator must be positive, got %d.', $denominator ) );
		}
		if ( $numerator < 0 ) {
			throw new InvalidArgumentException( sprintf( 'Fraction numerator must not be negative, got %d.', $numerator ) );
		}

		$divisor           = self::gcd( $numerator, $denominator );
		$this->numerator   = intdiv( $numerator, $divisor );
		$this->denominator = intdiv( $denominator, $divisor );
	}

	/**
	 * Parses "5/2" or a whole number such as "3".
	 *
	 * @param string $value Fraction as text; surrounding whitespace is ignored.
	 *
	 * @throws InvalidArgumentException When the text is not a fraction.
	 */
	public static function from_string( string $value ): self {
		if ( ! preg_match( '~^\s*(\d+)\s*(?:/\s*(\d+))?\s*$~', $value, $m ) ) {
			throw new InvalidArgumentException( sprintf( 'Not a fraction: "%s".', $value ) );
		}

		return new self( (int) $m[1], isset( $m[2] ) ? (int) $m[2] : 1 );
	}

	/**
	 * Finds the fraction a bookmaker would quote for a decimal value.
	 *
	 * Decimal odds from feeds are rounded to two places, so 10/11 arrives as 1.91 and 1/100 as
	 * 1.01. The arithmetic alone cannot recover the intended price: 1/67 and 1/99 both round
	 * to 1.01, and a "closest fraction under 100" answers 91/100 for 1.91. So the traditional
	 * price ladder is tried first; anything on it within the tolerance wins. Off-ladder values
	 * (exchange prices such as 2.29) fall back to the simplest fraction within the tolerance,
	 * and to the closest fraction under $max_denominator when nothing fits. Denominators are
	 * tried roundest-first (halves, quarters, fifths, tenths, twentieths, then thirds and the
	 * rest) and capped at 20 by default: 47/20 reads naturally where 40/17 does not, and the
	 * decimal is always available anyway.
	 *
	 * @param float $value           Non-negative value to approximate.
	 * @param float $tolerance       Acceptable absolute error; 0.005 matches two-decimal input.
	 * @param int   $max_denominator Upper bound for the fallback search.
	 *
	 * @throws InvalidArgumentException On a negative value.
	 */
	public static function from_float( float $value, float $tolerance = 0.005, int $max_denominator = 20 ): self {
		if ( $value < 0 ) {
			throw new InvalidArgumentException( sprintf( 'Cannot express a negative value (%s) as odds.', $value ) );
		}

		$ladder = self::ladder_match( $value, $tolerance );
		if ( null !== $ladder ) {
			return $ladder;
		}

		$best       = null;
		$best_error = PHP_FLOAT_MAX;

		foreach ( self::FALLBACK_DENOMINATORS as $denominator ) {
			if ( $denominator > $max_denominator ) {
				continue;
			}
			$numerator = (int) round( $value * $denominator );
			$error     = abs( $value - $numerator / $denominator );

			if ( $error <= $tolerance ) {
				return new self( $numerator, $denominator );
			}
			if ( $error < $best_error ) {
				$best       = array( $numerator, $denominator );
				$best_error = $error;
			}
		}

		return new self( $best[0], $best[1] );
	}

	/**
	 * Closest traditional price within the tolerance, or null.
	 *
	 * @param float $value     Value to match.
	 * @param float $tolerance Maximum absolute error.
	 */
	private static function ladder_match( float $value, float $tolerance ): ?self {
		static $ladder = null;
		if ( null === $ladder ) {
			$ladder = array_map( array( self::class, 'from_string' ), self::PRICE_LADDER );
		}

		$closest       = null;
		$closest_error = PHP_FLOAT_MAX;
		foreach ( $ladder as $fraction ) {
			$error = abs( $value - $fraction->to_float() );
			if ( $error <= $tolerance && $error < $closest_error ) {
				$closest       = $fraction;
				$closest_error = $error;
			}
		}

		return $closest;
	}

	/**
	 * Value as a float.
	 */
	public function to_float(): float {
		return $this->numerator / $this->denominator;
	}

	/**
	 * Bookmaker-style text, e.g. "5/2" or "4/6".
	 */
	public function __toString(): string {
		$text = $this->numerator . '/' . $this->denominator;

		return self::DISPLAY_ALIASES[ $text ] ?? $text;
	}

	/**
	 * Greatest common divisor (Euclid).
	 *
	 * @param int $a First number.
	 * @param int $b Second number.
	 */
	private static function gcd( int $a, int $b ): int {
		while ( 0 !== $b ) {
			[ $a, $b ] = array( $b, $a % $b );
		}

		return max( 1, $a );
	}
}
