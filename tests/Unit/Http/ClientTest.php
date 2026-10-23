<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Http;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use OddsCompare\Http\Client;
use OddsCompare\Http\HttpException;
use OddsCompare\Http\RateLimiter;
use OddsCompare\Support\Logger;
use OddsCompare\Tests\TestCase;
use WP_Error;

final class ClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_args' )->alias( static fn( array $args, array $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ) => $thing instanceof WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( array $r ) => $r['body'] );
		Functions\when( 'wp_remote_retrieve_headers' )->alias( static fn( array $r ) => $r['headers'] ?? array() );
	}

	private static function http( int $code, string $body = '', array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
			'headers'  => $headers,
		);
	}

	public function test_returns_response_with_defaults_and_filter_applied(): void {
		Functions\expect( 'wp_remote_get' )
			->once()
			->with(
				'https://api.test/x',
				\Mockery::on(
					static fn( array $args ) => 8 === $args['timeout']
						&& true === $args['sslverify']
						&& Client::MAX_BYTES === $args['limit_response_size']
						&& str_starts_with( $args['user-agent'], 'odds-compare-kate/' )
						&& 'abc' === $args['headers']['X-Key']
				)
			)
			->andReturn( self::http( 200, '{"ok":true}', array( 'X-Requests-Remaining' => '497' ) ) );
		Filters\expectApplied( 'odds_compare_http_args' )->once();

		$response = ( new Client() )->get( 'https://api.test/x', array( 'headers' => array( 'X-Key' => 'abc' ) ) );

		self::assertSame( 200, $response->status );
		self::assertSame( array( 'ok' => true ), $response->json() );
		self::assertSame( '497', $response->header( 'x-requests-remaining' ) );
	}

	public function test_timeout_is_retried_once_then_thrown(): void {
		Functions\expect( 'wp_remote_get' )
			->twice()
			->andReturn( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		try {
			( new Client() )->get( 'https://api.test/slow' );
			self::fail( 'expected HttpException' );
		} catch ( HttpException $e ) {
			self::assertTrue( $e->is_retryable() );
			self::assertNull( $e->status );
			self::assertStringContainsString( 'timed out', $e->getMessage() );
		}
	}

	public function test_server_error_then_success_recovers(): void {
		Functions\expect( 'wp_remote_get' )
			->twice()
			->andReturn( self::http( 503 ), self::http( 200, '[]' ) );

		self::assertSame( 200, ( new Client() )->get( 'https://api.test/flaky' )->status );
	}

	public function test_forbidden_is_not_retried(): void {
		Functions\expect( 'wp_remote_get' )->once()->andReturn( self::http( 403, 'blocked' ) );

		try {
			( new Client() )->get( 'https://www.oddschecker.com/football' );
			self::fail( 'expected HttpException' );
		} catch ( HttpException $e ) {
			self::assertFalse( $e->is_retryable() );
			self::assertSame( 403, $e->status );
		}
	}

	public function test_too_many_requests_is_retryable(): void {
		Functions\expect( 'wp_remote_get' )->twice()->andReturn( self::http( 429 ) );

		$this->expectException( HttpException::class );
		( new Client() )->get( 'https://api.test/x' );
	}

	public function test_rate_limiter_blocks_before_any_request(): void {
		Functions\expect( 'wp_remote_get' )->never();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'get_transient' )->justReturn( array( time(), time() ) );
		Functions\expect( 'set_transient' )->never();

		$client = new Client( new RateLimiter( array( 'oddschecker' => 2 ) ) );

		try {
			$client->get( 'https://www.oddschecker.com/x', array(), 'oddschecker' );
			self::fail( 'expected HttpException' );
		} catch ( HttpException $e ) {
			self::assertSame( 429, $e->status );
			self::assertTrue( $e->is_retryable() );
		}
	}

	public function test_failed_request_is_logged_without_the_api_key(): void {
		Functions\expect( 'wp_remote_get' )->once()->andReturn( self::http( 404 ) );
		Actions\expectDone( 'odds_compare_log' )
			->once()
			->with(
				'warning',
				'Request failed',
				\Mockery::on( static fn( array $context ) => str_contains( $context['url'], 'apiKey=***' ) && ! str_contains( $context['url'], 'topsecret' ) && str_contains( $context['url'], 'regions=uk' ) ),
				'odds-compare'
			);

		try {
			( new Client( null, new Logger() ) )->get( 'https://api.test/v4/odds?regions=uk&apiKey=topsecret&markets=h2h' );
		} catch ( HttpException ) {
			self::assertSame( 'https://x.test/?token=***&a=1#f', Client::redact( 'https://x.test/?token=hunter2&a=1#f' ) );
		}
	}

	public function test_truncated_body_fails_to_parse(): void {
		Functions\expect( 'wp_remote_get' )->once()->andReturn( self::http( 200, '{"events":[{"id":"a' ) );

		$this->expectException( \JsonException::class );
		( new Client() )->get( 'https://api.test/big' )->json();
	}
}
