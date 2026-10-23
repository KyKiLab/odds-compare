<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Provider\Oddschecker;

use OddsCompare\Provider\Oddschecker\HypernovaExtractor;
use OddsCompare\Tests\TestCase;

final class HypernovaExtractorTest extends TestCase {

	public function test_extracts_league_and_match_blobs_from_saved_pages(): void {
		$extractor = new HypernovaExtractor();

		$league = $extractor->extract( self::fixture( 'oddschecker/premier-league.html' ), 'competitionsaccumulatormatches' );
		$match  = $extractor->extract( self::fixture( 'oddschecker/ipswich-v-nottingham-forest-winner.html' ), 'subeventmarkets' );

		self::assertIsArray( $league );
		self::assertCount( 40, $league['subevents']['entities'] );
		self::assertIsArray( $match );
		self::assertSame( 'Ipswich vs Nottingham Forest', $match['bestOdds']['subeventConfig']['name'] );
	}

	public function test_returns_null_for_missing_key_or_garbage(): void {
		$extractor = new HypernovaExtractor();

		self::assertNull( $extractor->extract( self::fixture( 'oddschecker/premier-league.html' ), 'nope' ) );
		self::assertNull( $extractor->extract( '<html><body>blocked</body></html>', 'subeventmarkets' ) );
		self::assertNull( $extractor->extract( '', 'subeventmarkets' ) );
		self::assertNull( $extractor->extract( '<script type="application/json" data-hypernova-key="x"><!--{broken--></script>', 'x' ) );
	}

	public function test_skips_the_rendered_div_that_shares_the_key(): void {
		$html = '<div data-hypernova-key="k" data-hypernova-id="1">text</div>'
			. '<script type="application/json" data-hypernova-key="k" data-hypernova-id="1"><!--{"a":1}--></script>';

		self::assertSame( array( 'a' => 1 ), ( new HypernovaExtractor() )->extract( $html, 'k' ) );
	}
}
