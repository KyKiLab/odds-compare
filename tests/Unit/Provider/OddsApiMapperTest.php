<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Provider;

use OddsCompare\Provider\OddsApiMapper;
use OddsCompare\Tests\TestCase;

final class OddsApiMapperTest extends TestCase {

	public function test_maps_saved_epl_response(): void {
		$events = ( new OddsApiMapper() )->events( json_decode( self::fixture( 'odds-api/epl-uk.json' ), true ) );

		self::assertCount( 21, $events );

		$first = $events[0];
		self::assertSame( 'Ipswich Town', $first->home_team );
		self::assertSame( 'Nottingham Forest', $first->away_team );
		self::assertSame( 'soccer_epl', $first->sport_key );
		self::assertSame( '2026-10-23T19:00:00+00:00', $first->commence_time->format( DATE_ATOM ) );
		self::assertSame( array( 'h2h', 'totals', 'spreads' ), $first->markets() );
		self::assertContains( 'betfred_uk', $first->bookmakers() );

		$betfred_h2h = array_values( array_filter( $first->quotes_for( 'h2h' ), static fn( $q ) => 'betfred_uk' === $q->bookmaker_key ) );
		self::assertCount( 3, $betfred_h2h );
		self::assertSame( 'Ipswich Town', $betfred_h2h[0]->outcome );
		self::assertSame( 3.0, $betfred_h2h[0]->odds->to_decimal() );
		self::assertSame( 'Draw', $betfred_h2h[2]->outcome );
		self::assertSame( 3.6, $betfred_h2h[2]->odds->to_decimal() );
		self::assertSame( '2026-10-22T14:17:42+00:00', $betfred_h2h[0]->updated_at->format( DATE_ATOM ) );

		$totals = $first->quotes_for( 'totals' );
		self::assertNotEmpty( $totals );
		self::assertSame( 'Over', $totals[0]->outcome );
		self::assertNotNull( $totals[0]->point );
	}

	public function test_skips_broken_records_without_failing(): void {
		$data = array(
			array( 'id' => 'no-teams' ),
			array(
				'id'            => 'ok',
				'home_team'     => 'A',
				'away_team'     => 'B',
				'commence_time' => '2030-01-01T12:00:00Z',
				'bookmakers'    => array(
					array(
						'key'     => 'coral',
						'markets' => array(
							array(
								'key'      => 'h2h',
								'outcomes' => array(
									array(
										'name'  => 'A',
										'price' => 1.0,
									),
									array(
										'name'  => 'B',
										'price' => 2.5,
									),
									array( 'name' => 'Draw' ),
								),
							),
						),
					),
					array( 'markets' => array() ),
				),
			),
			array(
				'id'            => 'bad-date',
				'home_team'     => 'A',
				'away_team'     => 'B',
				'commence_time' => 'not a date',
			),
		);

		$events = ( new OddsApiMapper() )->events( $data );

		self::assertCount( 1, $events );
		self::assertCount( 1, $events[0]->quotes );
		self::assertSame( 2.5, $events[0]->quotes[0]->odds->to_decimal() );
	}

	public function test_maps_sports_and_drops_inactive(): void {
		$sports = ( new OddsApiMapper() )->sports( json_decode( self::fixture( 'odds-api/sports.json' ), true ) );

		self::assertNotEmpty( $sports );
		$keys = array_map( static fn( $s ) => $s->key, $sports );
		self::assertContains( 'soccer_epl', $keys );
		self::assertSame( 'Soccer', $sports[ array_search( 'soccer_epl', $keys, true ) ]->group );
	}
}
