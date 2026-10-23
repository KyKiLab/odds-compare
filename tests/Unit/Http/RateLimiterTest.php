<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Http;

use Brain\Monkey\Functions;
use OddsCompare\Http\RateLimiter;
use OddsCompare\Tests\TestCase;

final class RateLimiterTest extends TestCase {

	/** @var array<string, mixed> */
	private array $store = array();

	protected function setUp(): void {
		parent::setUp();
		$store = &$this->store;
		Functions\when( 'sanitize_key' )->returnArg();
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
	}

	public function test_allows_up_to_limit_then_denies(): void {
		$limiter = new RateLimiter( array( 'scraper' => 3 ) );

		self::assertTrue( $limiter->allow( 'scraper' ) );
		self::assertTrue( $limiter->allow( 'scraper' ) );
		self::assertTrue( $limiter->allow( 'scraper' ) );
		self::assertFalse( $limiter->allow( 'scraper' ) );
		self::assertGreaterThan( 0, $limiter->retry_after( 'scraper' ) );
		self::assertCount( 3, $this->store['odds_compare_rl_scraper'] );
	}

	public function test_unlisted_bucket_is_unlimited(): void {
		$limiter = new RateLimiter( array( 'scraper' => 1 ) );

		for ( $i = 0; $i < 50; $i++ ) {
			self::assertTrue( $limiter->allow( 'api' ) );
		}
		self::assertArrayNotHasKey( 'odds_compare_rl_api', $this->store );
		self::assertSame( 0, $limiter->retry_after( 'api' ) );
	}

	public function test_old_hits_fall_out_of_the_window(): void {
		$this->store['odds_compare_rl_scraper'] = array( time() - 120, time() - 61 );
		$limiter                                = new RateLimiter( array( 'scraper' => 2 ), 60 );

		self::assertTrue( $limiter->allow( 'scraper' ) );
		self::assertCount( 1, $this->store['odds_compare_rl_scraper'] );
	}
}
