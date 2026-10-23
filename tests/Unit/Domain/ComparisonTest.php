<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Domain;

use DateTimeImmutable;
use OddsCompare\Domain\Comparison;
use OddsCompare\Domain\Event;
use OddsCompare\Domain\Odds;
use OddsCompare\Domain\Quote;
use OddsCompare\Tests\TestCase;

final class ComparisonTest extends TestCase {

	private function event(): Event {
		$q = static fn( string $bk, string $outcome, float $decimal, ?string $at = null, string $market = 'h2h', ?float $point = null ) => new Quote( $bk, $market, $outcome, Odds::from_decimal( $decimal ), $point, $at ? new DateTimeImmutable( $at ) : null );

		return new Event(
			'ev1',
			'soccer_epl',
			'Premier League',
			'Ipswich',
			'Nottingham Forest',
			new DateTimeImmutable( '2026-10-23T19:00:00Z' ),
			array(
				$q( 'williamhill', 'Nottingham Forest', 2.2, '2026-10-22T10:00:00Z' ),
				$q( 'williamhill', 'Ipswich', 3.2 ),
				$q( 'williamhill', 'Draw', 3.5 ),
				$q( 'betfair', 'Ipswich', 3.3, '2026-10-22T11:00:00Z' ),
				$q( 'betfair', 'Draw', 3.5 ),
				$q( 'betfair', 'Nottingham Forest', 2.29 ),
				$q( 'coral', 'Ipswich', 3.1 ),
				$q( 'coral', 'Draw', 3.6 ),
				$q( 'coral', 'Over', 1.9, null, 'totals', 2.5 ),
				$q( 'coral', 'Under', 1.95, null, 'totals', 2.5 ),
			)
		);
	}

	public function test_columns_follow_home_draw_away_order(): void {
		$table = Comparison::build( $this->event(), 'h2h' );

		self::assertSame( array( 'Ipswich', 'Draw', 'Nottingham Forest' ), $table->columns() );
	}

	public function test_rows_sorted_by_best_price_in_first_column(): void {
		$table = Comparison::build( $this->event(), 'h2h' );

		self::assertSame( array( 'betfair', 'williamhill', 'coral' ), $table->bookmaker_keys() );
	}

	public function test_best_per_column_with_ties(): void {
		$table = Comparison::build( $this->event(), 'h2h' );
		$rows  = $table->rows();

		self::assertSame( 3.3, $table->best( 'Ipswich' )->to_decimal() );
		self::assertSame( 3.6, $table->best( 'Draw' )->to_decimal() );
		self::assertTrue( $table->is_best( $rows['betfair']['Ipswich'] ) );
		self::assertFalse( $table->is_best( $rows['williamhill']['Ipswich'] ) );
		self::assertTrue( $table->is_best( $rows['coral']['Draw'] ) );
		self::assertFalse( $table->is_best( $rows['betfair']['Draw'] ) );
		self::assertNull( $table->best( 'Nope' ) );
	}

	public function test_bookmaker_filter_keeps_only_requested_rows(): void {
		$table = Comparison::build( $this->event(), 'h2h', array( 'coral', 'williamhill', 'missing' ) );

		self::assertSame( array( 'williamhill', 'coral' ), $table->bookmaker_keys() );
		// Best is recomputed on the filtered set: betfair's 3.3 is gone.
		self::assertSame( 3.2, $table->best( 'Ipswich' )->to_decimal() );
	}

	public function test_missing_cell_sorts_last_and_table_tolerates_it(): void {
		$table = Comparison::build( $this->event(), 'h2h' );

		self::assertArrayNotHasKey( 'Nottingham Forest', $table->rows()['coral'] );
		self::assertSame( 'coral', $table->bookmaker_keys()[2] );
	}

	public function test_totals_use_one_column_per_side(): void {
		$table = Comparison::build( $this->event(), 'totals' );

		self::assertSame( array( 'Over', 'Under' ), $table->columns() );
		self::assertSame( 'Over 2.5', $table->rows()['coral']['Over']->label() );
	}

	public function test_empty_when_market_absent(): void {
		$table = Comparison::build( $this->event(), 'spreads' );

		self::assertTrue( $table->is_empty() );
		self::assertSame( array(), $table->columns() );
		self::assertNull( $table->updated_at() );
	}

	public function test_updated_at_is_the_latest_quote_time(): void {
		$table = Comparison::build( $this->event(), 'h2h' );

		self::assertSame( '2026-10-22T11:00:00+00:00', $table->updated_at()->format( DATE_ATOM ) );
	}
}
