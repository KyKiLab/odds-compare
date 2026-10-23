<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Domain;

use DateTimeImmutable;
use OddsCompare\Domain\Bookmaker;
use OddsCompare\Domain\Event;
use OddsCompare\Domain\Odds;
use OddsCompare\Domain\Quote;
use OddsCompare\Tests\TestCase;

final class EventTest extends TestCase {

	public function test_round_trips_through_plain_arrays(): void {
		$event = new Event(
			'abc',
			'soccer_epl',
			'Premier League',
			'Arsenal',
			'Everton',
			new DateTimeImmutable( '2026-10-24T14:00:00Z' ),
			array(
				new Quote( 'coral', 'h2h', 'Arsenal', Odds::from_decimal( 1.5 ), null, new DateTimeImmutable( '2026-10-22T09:30:00Z' ) ),
				new Quote( 'coral', 'spreads', 'Arsenal', Odds::from_decimal( 1.9 ), -1.5 ),
			)
		);

		$copy = Event::from_array( json_decode( json_encode( $event->to_array() ), true ) );

		self::assertSame( $event->to_array(), $copy->to_array() );
		self::assertSame( 'Arsenal v Everton', $copy->title() );
		self::assertSame( array( 'h2h', 'spreads' ), $copy->markets() );
		self::assertSame( array( 'coral' ), $copy->bookmakers() );
		self::assertSame( 'Arsenal -1.5', $copy->quotes_for( 'spreads' )[0]->label() );
		self::assertNull( $copy->quotes_for( 'h2h' )[0]->point );
	}

	public function test_has_started(): void {
		$event = new Event( 'x', 's', 'S', 'A', 'B', new DateTimeImmutable( '2026-10-24T14:00:00Z' ) );

		self::assertFalse( $event->has_started( new DateTimeImmutable( '2026-10-24T13:59:59Z' ) ) );
		self::assertTrue( $event->has_started( new DateTimeImmutable( '2026-10-24T14:00:00Z' ) ) );
	}

	public function test_bookmaker_key_normalisation(): void {
		self::assertSame( 'betfair_sportsbook', Bookmaker::key_from( ' Betfair Sportsbook ' ) );
		self::assertSame( 'wh', Bookmaker::key_from( 'WH' ) );
		self::assertSame( '888sport', Bookmaker::key_from( '888sport' ) );
		self::assertSame( 'https://x.test/?a=1', ( new Bookmaker( 'x', 'X' ) )->with_url( 'https://x.test/?a=1' )->url );
	}
}
