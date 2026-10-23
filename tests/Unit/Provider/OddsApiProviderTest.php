<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Provider;

use Brain\Monkey\Functions;
use OddsCompare\Http\Client;
use OddsCompare\Provider\OddsApiMapper;
use OddsCompare\Provider\OddsApiProvider;
use OddsCompare\Provider\OddsApiQuota;
use OddsCompare\Provider\ProviderException;
use OddsCompare\Tests\TestCase;

final class OddsApiProviderTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		$options = &$this->options;
		Functions\when( 'wp_parse_args' )->alias( static fn( array $a, array $d ) => array_merge( $d, $a ) );
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( array $r ) => $r['body'] );
		Functions\when( 'wp_remote_retrieve_headers' )->alias( static fn( array $r ) => $r['headers'] ?? array() );
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) use ( &$options ) {
				return $options[ $key ] ?? $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$options ): bool {
				$options[ $key ] = $value;
				return true;
			}
		);
	}

	private function provider( string $key = 'secret', int $reserve = 20 ): OddsApiProvider {
		return new OddsApiProvider( new Client(), new OddsApiQuota(), new OddsApiMapper(), $key, 'uk', $reserve );
	}

	public function test_not_configured_means_no_request(): void {
		Functions\expect( 'wp_remote_get' )->never();
		$provider = $this->provider( '' );

		self::assertFalse( $provider->is_configured() );
		try {
			$provider->events( 'soccer_epl', array( 'h2h' ) );
			self::fail( 'expected ProviderException' );
		} catch ( ProviderException $e ) {
			self::assertFalse( $e->is_retryable() );
		}
	}

	public function test_quota_below_reserve_means_no_request(): void {
		$this->options[ OddsApiQuota::OPTION ] = array(
			'remaining'  => 15,
			'used'       => 485,
			'updated_at' => time(),
		);
		Functions\expect( 'wp_remote_get' )->never();

		try {
			$this->provider()->events( 'soccer_epl', array( 'h2h' ) );
			self::fail( 'expected ProviderException' );
		} catch ( ProviderException $e ) {
			self::assertFalse( $e->is_retryable() );
			self::assertStringContainsString( '15', $e->getMessage() );
		}
	}

	public function test_success_maps_events_and_records_quota(): void {
		Functions\expect( 'wp_remote_get' )
			->once()
			->with(
				\Mockery::on(
					static fn( string $url ) => str_starts_with( $url, 'https://api.the-odds-api.com/v4/sports/soccer_epl/odds/?' )
						&& str_contains( $url, 'regions=uk' )
						&& str_contains( $url, 'markets=h2h%2Ctotals' )
						&& str_contains( $url, 'apiKey=secret' )
				),
				\Mockery::type( 'array' )
			)
			->andReturn(
				array(
					'response' => array( 'code' => 200 ),
					'body'     => self::fixture( 'odds-api/epl-uk.json' ),
					'headers'  => array(
						'x-requests-remaining' => '497',
						'x-requests-used'      => '3',
					),
				)
			);

		$events = $this->provider()->events( 'soccer_epl', array( 'h2h', 'totals', 'h2h' ) );

		self::assertCount( 21, $events );
		self::assertSame( 497, ( new OddsApiQuota() )->remaining() );
		self::assertSame( 3, $this->options[ OddsApiQuota::OPTION ]['used'] );
	}

	public function test_rejected_key_is_reported_without_leaking_it(): void {
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			array(
				'response' => array( 'code' => 401 ),
				'body'     => '{"message":"bad key"}',
			)
		);

		try {
			$this->provider( 'topsecret' )->events( 'soccer_epl', array( 'h2h' ) );
			self::fail( 'expected ProviderException' );
		} catch ( ProviderException $e ) {
			self::assertFalse( $e->is_retryable() );
			self::assertStringNotContainsString( 'topsecret', $e->getMessage() );
		}
	}

	public function test_server_errors_are_retryable(): void {
		Functions\expect( 'wp_remote_get' )->twice()->andReturn(
			array(
				'response' => array( 'code' => 502 ),
				'body'     => '',
			)
		);

		try {
			$this->provider()->events( 'soccer_epl', array( 'h2h' ) );
			self::fail( 'expected ProviderException' );
		} catch ( ProviderException $e ) {
			self::assertTrue( $e->is_retryable() );
		}
	}

	public function test_invalid_json_is_a_retryable_bad_response(): void {
		Functions\expect( 'wp_remote_get' )->once()->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '<html>',
			)
		);

		$this->expectException( ProviderException::class );
		$this->provider()->events( 'soccer_epl', array( 'h2h' ) );
	}
}
