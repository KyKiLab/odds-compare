<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Provider\Oddschecker;

use Brain\Monkey\Functions;
use OddsCompare\Bookmakers\Catalogue;
use OddsCompare\Http\Client;
use OddsCompare\Http\RateLimiter;
use OddsCompare\Provider\Oddschecker\HypernovaExtractor;
use OddsCompare\Provider\Oddschecker\Parser;
use OddsCompare\Provider\OddscheckerProvider;
use OddsCompare\Provider\ProviderException;
use OddsCompare\Tests\TestCase;

final class OddscheckerProviderTest extends TestCase {

	/** @var string[] */
	private array $requested = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_args' )->alias( static fn( array $a, array $d ) => array_merge( $d, $a ) );
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( array $r ) => $r['body'] );
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( array() );
		Functions\when( 'sanitize_key' )->returnArg();
	}

	private function provider( ?RateLimiter $limiter = null, int $max = 2 ): OddscheckerProvider {
		return new OddscheckerProvider( new Client( $limiter ), new HypernovaExtractor(), new Parser( new Catalogue() ), $max );
	}

	private function serve_fixtures(): void {
		$requested = &$this->requested;
		Functions\when( 'wp_remote_get' )->alias(
			static function ( string $url ) use ( &$requested ): array {
				$requested[] = $url;
				$file        = str_ends_with( $url, '/winner' ) ? 'oddschecker/ipswich-v-nottingham-forest-winner.html' : 'oddschecker/premier-league.html';

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => self::fixture( $file ),
				);
			}
		);
	}

	public function test_lists_leagues_as_sports(): void {
		$keys = array_map( static fn( $s ) => $s->key, $this->provider()->sports() );

		self::assertContains( 'football-english-premier-league', $keys );
		self::assertTrue( $this->provider()->is_configured() );
	}

	public function test_fetches_league_then_nearest_fixtures(): void {
		$this->serve_fixtures();

		$events = $this->provider( null, 2 )->events( 'football-english-premier-league', array( 'h2h' ) );

		self::assertCount( 20, $events );
		self::assertSame(
			array(
				'https://www.oddschecker.com/football/english/premier-league',
				'https://www.oddschecker.com/football/english/premier-league/ipswich-v-nottingham-forest/winner',
				'https://www.oddschecker.com/football/english/premier-league/aston-villa-v-man-city/winner',
			),
			$this->requested
		);
		self::assertNotEmpty( $events[0]->quotes );
		self::assertNotEmpty( $events[1]->quotes );
		self::assertSame( array(), $events[2]->quotes, 'beyond the cap fixtures are listed without prices' );
		self::assertSame( 'William Hill', $events[0]->bookmaker_titles['williamhill'] );
	}

	public function test_rate_limit_stops_fetching_without_failing(): void {
		$this->serve_fixtures();
		$store = array();
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) use ( &$store ) {
				return $store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value ) use ( &$store ): bool {
				$store[ $key ] = $value;
				return true;
			}
		);

		$events = $this->provider( new RateLimiter( array( 'oddschecker' => 2 ) ), 5 )->events( 'football-english-premier-league', array( 'h2h' ) );

		self::assertCount( 2, $this->requested, 'league page + one match page, then the limiter said no' );
		self::assertNotEmpty( $events[0]->quotes );
		self::assertSame( array(), $events[1]->quotes );
	}

	public function test_cloudflare_block_is_explained_and_not_retried(): void {
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			array(
				'response' => array( 'code' => 403 ),
				'body'     => 'Just a moment...',
			)
		);

		try {
			$this->provider()->events( 'football-english-premier-league', array( 'h2h' ) );
			self::fail( 'expected ProviderException' );
		} catch ( ProviderException $e ) {
			self::assertFalse( $e->is_retryable() );
			self::assertStringContainsString( 'Cloudflare', $e->getMessage() );
		}
	}

	public function test_unknown_league_is_a_permanent_error(): void {
		Functions\expect( 'wp_remote_get' )->never();

		$this->expectException( ProviderException::class );
		$this->provider()->events( 'tennis', array( 'h2h' ) );
	}
}
