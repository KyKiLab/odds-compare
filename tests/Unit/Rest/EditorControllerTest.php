<?php
/**
 * @package OddsCompare
 */

namespace OddsCompare\Tests\Unit\Rest;

use Brain\Monkey\Functions;
use Mockery;
use OddsCompare\Bookmakers\Catalogue;
use OddsCompare\Cache\OddsCache;
use OddsCompare\Cache\OddsRepository;
use OddsCompare\Cache\ProviderState;
use OddsCompare\Domain\Event;
use OddsCompare\Domain\Sport;
use OddsCompare\Provider\ProviderException;
use OddsCompare\Provider\ProviderInterface;
use OddsCompare\Rest\EditorController;
use OddsCompare\Settings\Settings;
use OddsCompare\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

final class EditorControllerTest extends TestCase {

	/** @var array<string, mixed> */
	private array $store = array();

	protected function setUp(): void {
		parent::setUp();
		$store = &$this->store;
		foreach ( array( 'get_transient', 'get_option' ) as $fn ) {
			Functions\when( $fn )->alias(
				static function ( string $key, $fallback = false ) use ( &$store ) {
					return $store[ $key ] ?? $fallback;
				}
			);
		}
		foreach ( array( 'set_transient', 'update_option' ) as $fn ) {
			Functions\when( $fn )->alias(
				static function ( string $key, $value ) use ( &$store ): bool {
					$store[ $key ] = $value;
					return true;
				}
			);
		}
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'delete_transient' )->alias(
			static function ( string $key ) use ( &$store ): bool {
				unset( $store[ $key ] );
				return true;
			}
		);
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( string $p ) => 'https://example.test/wp-admin/' . $p );
	}

	/**
	 * @return ProviderInterface&Mockery\MockInterface
	 */
	private function provider() {
		$provider = Mockery::mock( ProviderInterface::class );
		$provider->shouldReceive( 'key' )->andReturn( 'test' );

		return $provider;
	}

	private function controller( ProviderInterface $provider, bool $with_repository = true ): EditorController {
		$repository = new OddsRepository( $provider, new OddsCache( 3600 ), new ProviderState(), 3600 );

		return new EditorController(
			static fn() => $with_repository ? $repository : null,
			static fn() => $provider,
			new Settings(),
			new Catalogue(),
			new OddsCache( 3600 )
		);
	}

	public function test_routes_are_registered_behind_edit_posts(): void {
		$permissions = array();
		Functions\expect( 'register_rest_route' )
			->times( 3 )
			->with(
				'odds-compare/v1',
				Mockery::type( 'string' ),
				Mockery::on(
					static function ( array $route ) use ( &$permissions ): bool {
						$permissions[] = $route['permission_callback'];
						return isset( $route['callback'], $route['permission_callback'] );
					}
				)
			);

		$this->controller( $this->provider() )->register_routes();

		Functions\expect( 'current_user_can' )->twice()->with( 'edit_posts' )->andReturn( false, true );
		self::assertFalse( call_user_func( $permissions[0] ) );
		self::assertTrue( call_user_func( $permissions[1] ) );
	}

	public function test_argument_validation_rejects_path_tricks_and_unknown_markets(): void {
		self::assertTrue( EditorController::is_sport_key( 'soccer_epl' ) );
		self::assertTrue( EditorController::is_sport_key( 'football-english-premier-league' ) );
		self::assertFalse( EditorController::is_sport_key( '../../etc' ) );
		self::assertFalse( EditorController::is_sport_key( 'Soccer EPL' ) );
		self::assertFalse( EditorController::is_sport_key( array( 'x' ) ) );
		self::assertTrue( EditorController::is_market_list( '' ) );
		self::assertTrue( EditorController::is_market_list( 'h2h,totals' ) );
		self::assertFalse( EditorController::is_market_list( 'h2h,<script>' ) );
	}

	public function test_events_returns_picker_payload_without_quotes(): void {
		$provider = $this->provider();
		$provider->shouldReceive( 'events' )->once()->with( 'soccer_epl', array( 'h2h' ) )->andReturn(
			array( new Event( 'e1', 'soccer_epl', 'EPL', 'Arsenal', 'Everton', new \DateTimeImmutable( '2030-01-01T12:00:00Z' ) ) )
		);

		$payload = $this->controller( $provider )->events(
			new WP_REST_Request(
				array(
					'sport'   => 'soccer_epl',
					'markets' => 'h2h,totals',
				)
			)
		);

		self::assertSame( 'fresh', $payload['status'] );
		self::assertSame( 'Arsenal v Everton', $payload['events'][0]['title'] );
		self::assertSame( '2030-01-01T12:00:00+00:00', $payload['events'][0]['commence_time'] );
		self::assertArrayNotHasKey( 'quotes', $payload['events'][0] );
	}

	public function test_provider_failure_is_a_503_pointing_at_settings(): void {
		$provider = $this->provider();
		$provider->shouldReceive( 'events' )->once()->andThrow( new ProviderException( 'The Odds API quota is down to 15 credits.', false ) );

		$error = $this->controller( $provider )->events(
			new WP_REST_Request(
				array(
					'sport'   => 'soccer_epl',
					'markets' => '',
				)
			)
		);

		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'odds_compare_provider_error', $error->get_error_code() );
		self::assertSame( 503, $error->get_error_data()['status'] );
		self::assertStringContainsString( 'quota', $error->get_error_message() );
		self::assertStringContainsString( 'page=odds-compare-kate', $error->get_error_message() );
	}

	public function test_sports_are_cached_in_the_plugin_index(): void {
		$provider = $this->provider();
		$provider->shouldReceive( 'sports' )->once()->andReturn( array( new Sport( 'soccer_epl', 'EPL', 'Soccer' ) ) );
		$controller = $this->controller( $provider );

		$first  = $controller->sports();
		$second = $controller->sports();

		self::assertSame( $first, $second );
		self::assertSame( 'soccer_epl', $first[0]['key'] );
		self::assertArrayHasKey( 'odds_compare_sports_test', $this->store[ OddsCache::INDEX_OPTION ] );
	}

	public function test_bookmakers_payload_reflects_settings(): void {
		$this->store[ Settings::OPTION ] = array(
			'bookmakers' => array(
				'coral' => array(
					'enabled' => false,
					'url'     => '',
				),
			),
		);

		$rows = $this->controller( $this->provider() )->bookmakers();
		$by   = array_column( $rows, null, 'key' );

		self::assertFalse( $by['coral']['enabled'] );
		self::assertTrue( $by['williamhill']['enabled'] );
		self::assertSame( 'William Hill', $by['williamhill']['title'] );
	}
}
