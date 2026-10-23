<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Domain;

use InvalidArgumentException;
use OddsCompare\Domain\Fraction;
use OddsCompare\Tests\TestCase;

final class FractionTest extends TestCase {

	public function test_reduces_to_lowest_terms(): void {
		$fraction = new Fraction( 6, 4 );

		self::assertSame( 3, $fraction->numerator );
		self::assertSame( 2, $fraction->denominator );
		self::assertSame( '3/2', (string) $fraction );
	}

	public function test_parses_text(): void {
		self::assertSame( '5/2', (string) Fraction::from_string( ' 5 / 2 ' ) );
		self::assertSame( '3/1', (string) Fraction::from_string( '3' ) );
		self::assertSame( 0.0, Fraction::from_string( '0/5' )->to_float() );
	}

	public function test_rejects_bad_input(): void {
		$this->expectException( InvalidArgumentException::class );
		new Fraction( 1, 0 );
	}

	public function test_rejects_text_that_is_not_a_fraction(): void {
		$this->expectException( InvalidArgumentException::class );
		Fraction::from_string( '1.5' );
	}

	public function test_from_float_prefers_simplest_fraction_within_tolerance(): void {
		self::assertSame( '10/11', (string) Fraction::from_float( 0.91 ) );
		self::assertSame( '91/100', (string) Fraction::from_float( 0.91, 0.0, 100 ) );
		self::assertSame( '10/11', (string) Fraction::from_float( 0.91, 0.0 ), 'closest under the default cap' );
		self::assertSame( '1/3', (string) Fraction::from_float( 0.3333 ) );
		self::assertSame( '1/100', (string) Fraction::from_float( 0.01 ) );
		self::assertSame( '1/33', (string) Fraction::from_float( 0.03 ) );
	}

	public function test_from_float_falls_back_to_closest_when_nothing_fits(): void {
		// Tolerance too tight for 10/11 and a 10-denominator cap: 9/10 is just the closest.
		self::assertSame( '9/10', (string) Fraction::from_float( 0.91, 0.0005, 10 ) );
	}

	public function test_off_ladder_values_use_the_simplest_fraction(): void {
		self::assertSame( '9/7', (string) Fraction::from_float( 1.29 ) );
		self::assertSame( '23/10', (string) Fraction::from_float( 2.3 ) );
		self::assertSame( '9/10', (string) Fraction::from_float( 0.9 ) );
		self::assertSame( '47/20', (string) Fraction::from_float( 2.35 ), 'exchange price stays readable' );
	}
}
