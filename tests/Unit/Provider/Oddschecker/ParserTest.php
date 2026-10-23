<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Provider\Oddschecker;

use OddsCompare\Bookmakers\Catalogue;
use OddsCompare\Provider\Oddschecker\HypernovaExtractor;
use OddsCompare\Provider\Oddschecker\Parser;
use OddsCompare\Tests\TestCase;

final class ParserTest extends TestCase {

	public function test_league_page_yields_deduplicated_fixtures_in_kickoff_order(): void {
		$blob   = ( new HypernovaExtractor() )->extract( self::fixture( 'oddschecker/premier-league.html' ), 'competitionsaccumulatormatches' );
		$events = ( new Parser( new Catalogue() ) )->league_events( $blob, 'football-english-premier-league', 'Premier League' );

		self::assertCount( 20, $events );
		self::assertSame( 'ipswich-v-nottingham-forest', array_key_first( $events ) );

		$first = $events['ipswich-v-nottingham-forest'];
		self::assertSame( '101985321', $first->id, 'pre-match sibling, not the in-play one' );
		self::assertSame( 'Ipswich', $first->home_team );
		self::assertSame( 'Nottingham Forest', $first->away_team );
		self::assertSame( '2026-10-23T19:00:00+00:00', $first->commence_time->format( DATE_ATOM ) );
		self::assertSame( array(), $first->quotes );

		$times  = array_map( static fn( $e ) => $e->commence_time->getTimestamp(), array_values( $events ) );
		$sorted = $times;
		sort( $sorted );
		self::assertSame( $sorted, $times );
	}

	public function test_match_page_yields_canonical_bookmaker_quotes(): void {
		$blob   = ( new HypernovaExtractor() )->extract( self::fixture( 'oddschecker/ipswich-v-nottingham-forest-winner.html' ), 'subeventmarkets' );
		$parsed = ( new Parser( new Catalogue() ) )->match_quotes( $blob, array( 'h2h', 'totals' ) );

		$quotes = $parsed['quotes'];
		self::assertGreaterThanOrEqual( 70, count( $quotes ) );
		self::assertSame( array( 'h2h' ), array_values( array_unique( array_map( static fn( $q ) => $q->market_key, $quotes ) ) ) );

		$wh = array_values( array_filter( $quotes, static fn( $q ) => 'williamhill' === $q->bookmaker_key && 'Nottingham Forest' === $q->outcome ) );
		self::assertCount( 1, $wh );
		self::assertSame( 2.2, $wh[0]->odds->to_decimal() );
		self::assertSame( '6/5', $wh[0]->odds->to_fractional() );
		self::assertSame( '2026-10-21T21:32:33+00:00', $wh[0]->updated_at->format( DATE_ATOM ) );
		self::assertNull( $wh[0]->point );

		$keys = array_unique( array_map( static fn( $q ) => $q->bookmaker_key, $quotes ) );
		self::assertContains( 'bet365', $keys );
		self::assertContains( 'betfair_ex_uk', $keys );
		self::assertContains( 'oddschecker:bah', $keys, 'unknown codes become provider-prefixed keys' );

		self::assertSame( 'William Hill', $parsed['titles']['williamhill'] );
		self::assertSame( 'BetAhoy', $parsed['titles']['oddschecker:bah'] );
		foreach ( $quotes as $quote ) {
			self::assertGreaterThan( 1.0, $quote->odds->to_decimal() );
		}
	}

	public function test_market_filter_can_add_types(): void {
		$blob = ( new HypernovaExtractor() )->extract( self::fixture( 'oddschecker/ipswich-v-nottingham-forest-winner.html' ), 'subeventmarkets' );

		\Brain\Monkey\Filters\expectApplied( 'odds_compare_oddschecker_market_types' )->once()->andReturn( array( 'Double Chance' => 'double_chance' ) );
		$parsed = ( new Parser( new Catalogue() ) )->match_quotes( $blob, array( 'double_chance' ) );

		self::assertSame( array(), $parsed['quotes'], 'the /winner page has no priced Double Chance bets' );
		self::assertNotEmpty( $parsed['titles'] );
	}
}
