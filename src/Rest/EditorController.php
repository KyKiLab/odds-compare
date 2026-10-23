<?php
/**
 * REST endpoints for the block editor.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Rest;

use Closure;
use OddsCompare\Bookmakers\Catalogue;
use OddsCompare\Cache\OddsCache;
use OddsCompare\Domain\Bookmaker;
use OddsCompare\Domain\Event;
use OddsCompare\Domain\Sport;
use OddsCompare\Provider\ProviderException;
use OddsCompare\Settings\Markets;
use OddsCompare\Settings\Settings;
use WP_Error;
use WP_REST_Request;

/**
 * Routes under odds-compare/v1: what the editor needs to pick an event and a set of bookmakers.
 *
 * Every route requires edit_posts. There is deliberately no public endpoint: the block is
 * rendered server-side, so visitors never need one, and an open endpoint would let anyone
 * burn the API quota or probe the provider.
 */
final class EditorController {

	public const ROUTE_NAMESPACE = 'odds-compare/v1';

	private const SPORTS_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Builds the controller.
	 *
	 * @param Closure   $repository fn(): ?OddsRepository for the active provider.
	 * @param Closure   $provider   fn(): ProviderInterface, the active provider.
	 * @param Settings  $settings   Settings.
	 * @param Catalogue $catalogue  Bookmaker catalogue.
	 * @param OddsCache $cache      Cache, for the sports list.
	 */
	public function __construct(
		private readonly Closure $repository,
		private readonly Closure $provider,
		private readonly Settings $settings,
		private readonly Catalogue $catalogue,
		private readonly OddsCache $cache,
	) {}

	/**
	 * Hooks route registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the three routes.
	 */
	public function register_routes(): void {
		$permission = array( $this, 'permission' );

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/sports',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'sports' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'events' ),
				'permission_callback' => $permission,
				'args'                => array(
					'sport'   => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( self::class, 'is_sport_key' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'markets' => array(
						'type'              => 'string',
						'default'           => '',
						'validate_callback' => array( self::class, 'is_market_list' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/bookmakers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'bookmakers' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Editors only.
	 */
	public function permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Sport keys are lower-case slugs. Runs before sanitization, so "../x" is a 400, not "x".
	 *
	 * @param mixed $value Raw parameter.
	 */
	public static function is_sport_key( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-z0-9_-]{1,64}$/', $value );
	}

	/**
	 * Comma-separated list of known market keys, or empty.
	 *
	 * @param mixed $value Raw parameter.
	 */
	public static function is_market_list( mixed $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		if ( '' === $value ) {
			return true;
		}

		return array() === array_diff( explode( ',', $value ), Markets::keys() );
	}

	/**
	 * GET /sports: competitions of the active provider, cached for half a day.
	 */
	public function sports(): mixed {
		$provider = ( $this->provider )();

		try {
			$sports = $this->cache->remember(
				'odds_compare_sports_' . $provider->key(),
				self::SPORTS_TTL,
				static fn() => array_map( static fn( Sport $sport ) => $sport->to_array(), $provider->sports() )
			);
		} catch ( ProviderException $e ) {
			return $this->provider_error( $e->getMessage() );
		}

		return rest_ensure_response( array_values( $sports ) );
	}

	/**
	 * GET /events: upcoming events for a sport, without quotes (the picker only needs names).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function events( WP_REST_Request $request ): mixed {
		$repository = ( $this->repository )();
		if ( ! $repository ) {
			return $this->provider_error( __( 'No odds provider is configured.', 'odds-compare-kate' ) );
		}

		$result = $repository->events( (string) $request->get_param( 'sport' ), $this->markets( (string) $request->get_param( 'markets' ) ) );

		if ( ! $result->has_events() && null !== $result->error ) {
			return $this->provider_error( $result->error );
		}

		return rest_ensure_response(
			array(
				'status'     => $result->status,
				'fetched_at' => $result->fetched_at,
				'events'     => array_map( array( $this, 'event_summary' ), $result->events ),
			)
		);
	}

	/**
	 * GET /bookmakers: catalogue plus discovered bookmakers with their enabled state.
	 */
	public function bookmakers(): mixed {
		$rows = array();
		foreach ( $this->catalogue->all_with_seen() as $key => $bookmaker ) {
			$rows[] = array(
				'key'     => $key,
				'title'   => $bookmaker->title,
				'enabled' => $this->settings->is_bookmaker_enabled( $key, $this->catalogue->is_built_in( $key ) ),
			);
		}

		return rest_ensure_response( $rows );
	}

	/**
	 * Markets to request: the given subset of the configured ones, or all configured.
	 *
	 * @param string $csv Comma-separated market keys.
	 *
	 * @return string[]
	 */
	private function markets( string $csv ): array {
		$configured = $this->settings->markets();
		$requested  = '' === $csv ? array() : array_intersect( explode( ',', $csv ), $configured );

		return array_values( $requested ? $requested : $configured );
	}

	/**
	 * Picker-sized view of an event.
	 *
	 * @param Event $event Event.
	 *
	 * @return array<string, mixed>
	 */
	private function event_summary( Event $event ): array {
		return array(
			'id'            => $event->id,
			'title'         => $event->title(),
			'home_team'     => $event->home_team,
			'away_team'     => $event->away_team,
			'commence_time' => $event->commence_time->format( DATE_ATOM ),
			'markets'       => $event->markets(),
			'bookmakers'    => $event->bookmakers(),
		);
	}

	/**
	 * 503 with a message the editor can show. Provider messages never contain the API key
	 * (see OddsApiProvider), so they are safe to pass through.
	 *
	 * @param string $message Reason.
	 */
	private function provider_error( string $message ): WP_Error {
		return new WP_Error(
			'odds_compare_provider_error',
			sprintf(
				/* translators: 1: provider error message, 2: settings page URL */
				__( '%1$s Check the plugin settings: %2$s', 'odds-compare-kate' ),
				$message,
				admin_url( 'options-general.php?page=odds-compare-kate' )
			),
			array( 'status' => 503 )
		);
	}
}
