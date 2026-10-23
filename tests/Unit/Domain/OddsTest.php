<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Domain;

use InvalidArgumentException;
use OddsCompare\Domain\Odds;
use OddsCompare\Domain\OddsFormat;
use OddsCompare\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class OddsTest extends TestCase {

	public function test_from_decimal_converts_to_other_formats(): void {
		$odds = Odds::from_decimal( 2.5 );

		self::assertSame( 2.5, $odds->to_decimal() );
		self::assertSame( '3/2', $odds->to_fractional() );
		self::assertSame( 150, $odds->to_american() );
	}

	public function test_from_fractional_accepts_text_and_numbers(): void {
		self::assertSame( 3.5, Odds::from_fractional( '5/2' )->to_decimal() );
		self::assertSame( 3.5, Odds::from_fractional( 5, 2 )->to_decimal() );
		self::assertSame( 250, Odds::from_fractional( '5/2' )->to_american() );
		self::assertSame( 4.0, Odds::from_fractional( '3' )->to_decimal() );
	}

	public function test_from_negative_american(): void {
		$odds = Odds::from_american( -150 );

		self::assertEqualsWithDelta( 1.6667, $odds->to_decimal(), 0.0001 );
		self::assertSame( '4/6', $odds->to_fractional() );
		self::assertSame( -150, $odds->to_american() );
	}

	public function test_from_positive_american_and_even_money(): void {
		self::assertSame( 2.0, Odds::from_american( 100 )->to_decimal() );
		self::assertSame( 2.0, Odds::from_american( -100 )->to_decimal() );
		self::assertSame( 3.0, Odds::from_american( 200 )->to_decimal() );
		self::assertSame( -200, Odds::from_decimal( 1.5 )->to_american() );
	}

	#[DataProvider( 'invalid_input' )]
	public function test_invalid_input_is_rejected( callable $factory ): void {
		$this->expectException( InvalidArgumentException::class );
		$factory();
	}

	public static function invalid_input(): iterable {
		yield 'decimal 1.0'        => array( static fn() => Odds::from_decimal( 1.0 ) );
		yield 'decimal below 1'    => array( static fn() => Odds::from_decimal( 0.5 ) );
		yield 'decimal infinite'   => array( static fn() => Odds::from_decimal( INF ) );
		yield 'zero denominator'   => array( static fn() => Odds::from_fractional( '5/0' ) );
		yield 'zero fraction'      => array( static fn() => Odds::from_fractional( '0/1' ) );
		yield 'american 50'        => array( static fn() => Odds::from_american( 50 ) );
		yield 'american -99'       => array( static fn() => Odds::from_american( -99 ) );
		yield 'american zero'      => array( static fn() => Odds::from_american( 0 ) );
		yield 'not a fraction'     => array( static fn() => Odds::from_fractional( 'abc' ) );
		yield 'negative fraction'  => array( static fn() => Odds::from_fractional( '-5/2' ) );
	}

	#[DataProvider( 'conventional_fractions' )]
	public function test_fractional_output_matches_bookmaker_convention( float $decimal, string $expected ): void {
		self::assertSame( $expected, Odds::from_decimal( $decimal )->to_fractional() );
	}

	public static function conventional_fractions(): iterable {
		yield array( 1.91, '10/11' );
		yield array( 2.0, '1/1' );
		yield array( 1.5, '1/2' );
		yield array( 3.6, '13/5' );
		yield array( 1.01, '1/100' );
		yield array( 2.3, '13/10' );
		yield array( 2.25, '5/4' );
		yield array( 1.8, '4/5' );
		yield array( 1.57, '4/7' );
		yield array( 1.73, '8/11' );
		yield array( 1.67, '4/6' );
		yield array( 11.0, '10/1' );
	}

	public function test_probability_and_comparison(): void {
		$better = Odds::from_decimal( 2.1 );
		$evens  = Odds::from_fractional( '1/1' );

		self::assertTrue( $better->is_better_than( $evens ) );
		self::assertFalse( $evens->is_better_than( $better ) );
		self::assertSame( 1, $better->compare( $evens ) );
		self::assertSame( 0, $evens->compare( Odds::from_decimal( 2.0 ) ) );
		self::assertEqualsWithDelta( 0.4762, $better->implied_probability(), 0.0001 );
	}

	public function test_format_for_display(): void {
		$odds = Odds::from_decimal( 2.5 );

		self::assertSame( '2.50', $odds->format( OddsFormat::Decimal ) );
		self::assertSame( '3/2', $odds->format( OddsFormat::Fractional ) );
		self::assertSame( '+150', $odds->format( OddsFormat::American ) );
		self::assertSame( '-200', Odds::from_decimal( 1.5 )->format( OddsFormat::American ) );
		self::assertSame( '1.91', Odds::from_fractional( '10/11' )->format( OddsFormat::Decimal ) );
	}
}
