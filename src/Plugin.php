<?php
/**
 * Plugin composition root.
 *
 * @package OddsCompare
 */

namespace OddsCompare;

use OddsCompare\Admin\Notices;
use OddsCompare\Admin\SettingsPage;
use OddsCompare\Admin\Tools;
use OddsCompare\Block\Registrar;
use OddsCompare\Block\Renderer;
use OddsCompare\Bookmakers\Catalogue;
use OddsCompare\Cache\OddsCache;
use OddsCompare\Cache\OddsRepository;
use OddsCompare\Cache\ProviderState;
use OddsCompare\Cache\RefreshScheduler;
use OddsCompare\Domain\Event;
use OddsCompare\Http\Client;
use OddsCompare\Http\RateLimiter;
use OddsCompare\Provider\FixtureProvider;
use OddsCompare\Provider\OddsApiMapper;
use OddsCompare\Provider\OddsApiProvider;
use OddsCompare\Provider\OddsApiQuota;
use OddsCompare\Provider\Oddschecker\HypernovaExtractor;
use OddsCompare\Provider\Oddschecker\Parser;
use OddsCompare\Provider\OddscheckerProvider;
use OddsCompare\Provider\ProviderRegistry;
use OddsCompare\Rest\EditorController;
use OddsCompare\Settings\Settings;
use OddsCompare\Support\Logger;

/**
 * Builds services and wires them to WordPress hooks.
 *
 * This is the only singleton in the plugin. It exists so the bootstrap file and hook
 * callbacks have one object to ask for services; every service receives its dependencies
 * through its constructor and can be instantiated in tests without WordPress. Services are
 * created lazily so a front-end request that renders no block pays for nothing.
 */
final class Plugin {

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Guards against booting twice.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Lazily built services, keyed by class name.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Returns the shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Use Plugin::instance().
	 */
	private function __construct() {}

	/**
	 * Registers hooks. Runs on plugins_loaded.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain(
			'odds-compare-kate',
			false,
			dirname( plugin_basename( ODDS_COMPARE_FILE ) ) . '/languages'
		);

		( new RefreshScheduler( fn( string $provider_key ) => $this->repository( $provider_key ) ) )->register();
		add_action( 'odds_compare_odds_refreshed', array( $this, 'remember_bookmakers' ), 10, 4 );

		( new Registrar( $this->settings() ) )->register();
		( new EditorController(
			fn() => $this->repository(),
			fn() => $this->providers()->resolve( $this->settings()->provider_key() ),
			$this->settings(),
			$this->catalogue(),
			$this->cache()
		) )->register();

		if ( is_admin() ) {
			( new SettingsPage( $this->settings(), $this->providers(), $this->catalogue(), $this->provider_state(), new OddsApiQuota() ) )->register();
			( new Tools( fn( string $provider_key ) => $this->repository( $provider_key ), $this->cache(), $this->provider_state() ) )->register();
			( new Notices( $this->settings(), $this->providers(), $this->provider_state(), new OddsApiQuota() ) )->register();
		}

		/**
		 * Fires once the plugin has registered its hooks.
		 *
		 * Extensions that need the plugin's services should hook here rather than on
		 * plugins_loaded, where load order is not guaranteed.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'odds_compare_loaded', $this );
	}

	/**
	 * Settings reader.
	 */
	public function settings(): Settings {
		return $this->services[ Settings::class ] ??= new Settings();
	}

	/**
	 * Logger.
	 */
	public function logger(): Logger {
		return $this->services[ Logger::class ] ??= new Logger();
	}

	/**
	 * Bookmaker catalogue.
	 */
	public function catalogue(): Catalogue {
		return $this->services[ Catalogue::class ] ??= new Catalogue();
	}

	/**
	 * Provider health / circuit breaker state.
	 */
	public function provider_state(): ProviderState {
		return $this->services[ ProviderState::class ] ??= new ProviderState();
	}

	/**
	 * Odds cache.
	 */
	public function cache(): OddsCache {
		return $this->services[ OddsCache::class ] ??= new OddsCache( $this->settings()->stale_ttl() );
	}

	/**
	 * HTTP client with the rate limits from settings.
	 */
	public function client(): Client {
		if ( ! isset( $this->services[ Client::class ] ) ) {
			$limits = array( 'oddschecker' => $this->settings()->scraper_rate_limit() );

			/**
			 * Filters per-provider request limits (requests per minute). Unlisted providers are unlimited.
			 *
			 * @param array<string, int> $limits Provider key => requests per minute.
			 */
			$limits = (array) apply_filters( 'odds_compare_rate_limits', $limits );

			$this->services[ Client::class ] = new Client( new RateLimiter( $limits ), $this->logger() );
		}

		return $this->services[ Client::class ];
	}

	/**
	 * Registry with the built-in providers plus anything added through the filter.
	 */
	public function providers(): ProviderRegistry {
		if ( ! isset( $this->services[ ProviderRegistry::class ] ) ) {
			$settings = $this->settings();
			$mapper   = new OddsApiMapper( $this->logger() );

			$registry = ( new ProviderRegistry( $this->logger() ) )
				->register( new FixtureProvider( $mapper, ODDS_COMPARE_DIR . 'data/fixtures' ) )
				->register(
					new OddscheckerProvider(
						$this->client(),
						new HypernovaExtractor(),
						new Parser( $this->catalogue(), $this->logger() ),
						10,
						$this->logger()
					)
				)
				->register(
					new OddsApiProvider(
						$this->client(),
						new OddsApiQuota(),
						$mapper,
						$settings->api_key(),
						$settings->region(),
						$settings->quota_reserve(),
						$this->logger()
					)
				);

			/**
			 * Filters the provider registry. Call $registry->register( $provider ) to add a source.
			 *
			 * @param ProviderRegistry $registry The registry.
			 * @param Plugin           $plugin   The plugin, for access to the HTTP client and logger.
			 */
			$filtered = apply_filters( 'odds_compare_providers', $registry, $this );

			$this->services[ ProviderRegistry::class ] = $filtered instanceof ProviderRegistry ? $filtered : $registry;
		}//end if

		return $this->services[ ProviderRegistry::class ];
	}

	/**
	 * Repository for a provider, or for the configured one when no key is given. Null when the
	 * key is unknown (a queued refresh for a provider that has since been removed).
	 *
	 * @param string|null $provider_key Provider key, or null for the active provider.
	 */
	public function repository( ?string $provider_key = null ): ?OddsRepository {
		$provider = null === $provider_key
			? $this->providers()->resolve( $this->settings()->provider_key() )
			: $this->providers()->get( $provider_key );

		if ( ! $provider ) {
			return null;
		}

		return new OddsRepository( $provider, $this->cache(), $this->provider_state(), $this->settings()->fresh_ttl(), $this->logger() );
	}

	/**
	 * Block renderer, used by the block's render.php.
	 */
	public function block_renderer(): Renderer {
		return $this->services[ Renderer::class ] ??= new Renderer( fn() => $this->repository(), $this->settings(), $this->catalogue() );
	}

	/**
	 * Records bookmakers that appeared in fresh data, so new ones show up in the admin screen.
	 *
	 * @param string   $provider_key Provider key.
	 * @param string   $sport_key    Sport key.
	 * @param string[] $market_keys  Market keys.
	 * @param Event[]  $events       Fresh events.
	 */
	public function remember_bookmakers( string $provider_key, string $sport_key, array $market_keys, array $events ): void {
		$titles = array();
		foreach ( $events as $event ) {
			$titles += $event->bookmaker_titles;
		}

		if ( $titles ) {
			$this->catalogue()->remember_seen( $titles );
		}
	}

	/**
	 * Activation: store defaults once. Picks the live provider when the API key is already
	 * in wp-config.php, so a fresh install with a key shows real odds without a visit to settings.
	 */
	public static function activate(): void {
		if ( false !== get_option( Settings::OPTION ) ) {
			return;
		}

		$defaults = Settings::defaults();
		if ( defined( Settings::KEY_CONSTANT ) && '' !== (string) constant( Settings::KEY_CONSTANT ) ) {
			$defaults['provider'] = OddsApiProvider::KEY;
		}

		( new Settings() )->save( $defaults );
	}

	/**
	 * Deactivation: settings stay, everything transient goes.
	 */
	public static function deactivate(): void {
		RefreshScheduler::clear();
		( new OddsCache( 0 ) )->purge();
		( new ProviderState() )->reset();
	}
}
