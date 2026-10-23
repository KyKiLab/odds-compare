<?php
/**
 * Settings screen.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Admin;

use OddsCompare\Bookmakers\Catalogue;
use OddsCompare\Cache\ProviderState;
use OddsCompare\Domain\OddsFormat;
use OddsCompare\Provider\OddsApiQuota;
use OddsCompare\Provider\ProviderRegistry;
use OddsCompare\Settings\Markets;
use OddsCompare\Settings\Sanitizer;
use OddsCompare\Settings\Settings;

/**
 * Settings > Odds Compare, on the Settings API: it brings the nonce, the capability check
 * on save and the error display for free, which a hand-rolled form would have to repeat.
 */
final class SettingsPage {

	public const SLUG  = 'odds-compare-kate';
	public const GROUP = 'odds_compare';

	/**
	 * Hook suffix of the page, for asset loading.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Builds the page.
	 *
	 * @param Settings         $settings  Settings.
	 * @param ProviderRegistry $providers Providers for the select.
	 * @param Catalogue        $catalogue Bookmakers.
	 * @param ProviderState    $state     Provider health.
	 * @param OddsApiQuota     $quota     API quota.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ProviderRegistry $providers,
		private readonly Catalogue $catalogue,
		private readonly ProviderState $state,
		private readonly OddsApiQuota $quota,
	) {}

	/**
	 * Hooks menu, settings and assets.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ODDS_COMPARE_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds the page under Settings.
	 */
	public function add_menu(): void {
		$this->hook = (string) add_options_page(
			__( 'Odds Compare', 'odds-compare-kate' ),
			__( 'Odds Compare', 'odds-compare-kate' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * "Settings" link on the plugins list.
	 *
	 * @param string[] $links Existing links.
	 *
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'odds-compare-kate' ) ) );

		return $links;
	}

	/**
	 * URL of the page.
	 *
	 * @param array<string, string|int> $args Extra query args.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Registers the option, sections and fields.
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);

		add_settings_section( 'source', __( 'Data source', 'odds-compare-kate' ), array( $this, 'section_source' ), self::SLUG );
		add_settings_field( 'provider', __( 'Provider', 'odds-compare-kate' ), array( $this, 'field_provider' ), self::SLUG, 'source' );
		add_settings_field( 'odds_api_key', __( 'The Odds API key', 'odds-compare-kate' ), array( $this, 'field_api_key' ), self::SLUG, 'source' );
		add_settings_field( 'odds_api_region', __( 'Bookmaker region', 'odds-compare-kate' ), array( $this, 'field_region' ), self::SLUG, 'source' );
		add_settings_field( 'quota_reserve', __( 'Quota reserve', 'odds-compare-kate' ), array( $this, 'field_reserve' ), self::SLUG, 'source' );

		add_settings_section( 'display', __( 'Markets and display', 'odds-compare-kate' ), '__return_null', self::SLUG );
		add_settings_field( 'markets', __( 'Markets to fetch', 'odds-compare-kate' ), array( $this, 'field_markets' ), self::SLUG, 'display' );
		add_settings_field( 'default_format', __( 'Default odds format', 'odds-compare-kate' ), array( $this, 'field_format' ), self::SLUG, 'display' );

		add_settings_section( 'bookmakers', __( 'Bookmakers', 'odds-compare-kate' ), array( $this, 'section_bookmakers' ), self::SLUG );

		add_settings_section( 'cache', __( 'Caching', 'odds-compare-kate' ), array( $this, 'section_cache' ), self::SLUG );
		add_settings_field( 'fresh_ttl', __( 'Refresh odds after', 'odds-compare-kate' ), array( $this, 'field_fresh_ttl' ), self::SLUG, 'cache' );
		add_settings_field( 'stale_ttl', __( 'Keep stale odds for', 'odds-compare-kate' ), array( $this, 'field_stale_ttl' ), self::SLUG, 'cache' );
		add_settings_field( 'scraper_rate_limit', __( 'Scraper rate limit', 'odds-compare-kate' ), array( $this, 'field_rate_limit' ), self::SLUG, 'cache' );
	}

	/**
	 * Settings API sanitize callback.
	 *
	 * @param mixed $input Submitted value.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $input ): array {
		$sanitizer = new Sanitizer( array_keys( $this->providers->all() ), $this->settings->all(), $this->settings->api_key_from_constant() );
		$clean     = $sanitizer->sanitize( $input );

		foreach ( $sanitizer->errors() as $i => $message ) {
			add_settings_error( Settings::OPTION, 'odds_compare_invalid_' . $i, $message );
		}

		return $clean;
	}

	/**
	 * Loads the small stylesheet and script on this page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( $hook !== $this->hook ) {
			return;
		}

		wp_enqueue_style( 'odds-compare-admin', ODDS_COMPARE_URL . 'assets/admin.css', array(), ODDS_COMPARE_VERSION );
		wp_enqueue_script( 'odds-compare-admin', ODDS_COMPARE_URL . 'assets/admin.js', array(), ODDS_COMPARE_VERSION, true );
	}

	/**
	 * Page markup.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap odds-compare-admin">
			<h1><?php esc_html_e( 'Odds Compare', 'odds-compare-kate' ); ?></h1>
			<?php $this->render_status(); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::SLUG );
				submit_button();
				?>
			</form>
			<?php Tools::render_buttons(); ?>
		</div>
		<?php
	}

	/**
	 * Provider health, quota and cache at a glance.
	 */
	private function render_status(): void {
		$provider = $this->providers->resolve( $this->settings->provider_key() );
		$state    = $this->state->get( $provider->key() );
		$quota    = $this->quota->snapshot();
		$rows     = array();

		$rows[ __( 'Active provider', 'odds-compare-kate' ) ] = $provider->label() . ( $provider->is_configured() ? '' : ', ' . __( 'not configured', 'odds-compare-kate' ) );

		if ( ! empty( $state['last_success_at'] ) ) {
			/* translators: %s: human readable time difference */
			$rows[ __( 'Last successful fetch', 'odds-compare-kate' ) ] = sprintf( __( '%s ago', 'odds-compare-kate' ), human_time_diff( (int) $state['last_success_at'] ) );
		}
		if ( ! empty( $state['last_error'] ) ) {
			/* translators: 1: time difference, 2: error message */
			$rows[ __( 'Last error', 'odds-compare-kate' ) ] = sprintf( __( '%1$s ago: %2$s', 'odds-compare-kate' ), human_time_diff( (int) ( $state['last_error_at'] ?? time() ) ), (string) $state['last_error'] );
		}
		if ( $this->state->is_open( $provider->key() ) ) {
			$rows[ __( 'Circuit breaker', 'odds-compare-kate' ) ] = __( 'Open: the provider failed repeatedly and is paused for a few minutes. Cached odds keep being served.', 'odds-compare-kate' );
		}
		if ( $quota ) {
			/* translators: 1: credits remaining, 2: credits used, 3: time difference */
			$rows[ __( 'The Odds API quota', 'odds-compare-kate' ) ] = sprintf( __( '%1$d credits left, %2$d used (as of %3$s ago)', 'odds-compare-kate' ), $quota['remaining'], $quota['used'], human_time_diff( (int) $quota['updated_at'] ) );
		}
		?>
		<table class="widefat striped odds-compare-admin__status">
			<tbody>
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $label ); ?></th>
					<td><?php echo esc_html( $value ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Intro for the data source section.
	 */
	public function section_source(): void {
		echo '<p>' . esc_html__( 'Where odds come from. The Odds API needs a free key from the-odds-api.com; the Oddschecker scraper is blocked from most servers by Cloudflare and is here for networks it allows; sample data needs nothing and is what the block shows out of the box.', 'odds-compare-kate' ) . '</p>';
	}

	/**
	 * Intro and hint for the cache section.
	 */
	public function section_cache(): void {
		echo '<p>' . esc_html__( 'Odds are served from cache. When the cache is older than the refresh interval, visitors still get the cached odds immediately and a background refresh is queued; odds are only fetched when a page actually shows them.', 'odds-compare-kate' ) . '</p>';
	}

	/**
	 * Provider select.
	 */
	public function field_provider(): void {
		$current = $this->settings->provider_key();
		echo '<select name="' . esc_attr( Settings::OPTION ) . '[provider]">';
		foreach ( $this->providers->all() as $key => $provider ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $current, $key, false ), esc_html( $provider->label() ) );
		}
		echo '</select>';
	}

	/**
	 * API key input; locked when wp-config.php defines it. Only the tail of a stored key is shown.
	 */
	public function field_api_key(): void {
		if ( $this->settings->api_key_from_constant() ) {
			printf(
				'<input type="text" class="regular-text" value="%s" disabled /> <p class="description">%s</p>',
				esc_attr( self::mask( $this->settings->api_key() ) ),
				esc_html__( 'Set by the ODDS_COMPARE_ODDS_API_KEY constant in wp-config.php.', 'odds-compare-kate' )
			);
			return;
		}

		$stored = (string) $this->settings->get( 'odds_api_key' );
		printf(
			'<input type="password" class="regular-text" name="%s[odds_api_key]" value="%s" autocomplete="off" /> <p class="description">%s</p>',
			esc_attr( Settings::OPTION ),
			esc_attr( $stored ),
			esc_html__( 'Or define ODDS_COMPARE_ODDS_API_KEY in wp-config.php to keep the key out of the database.', 'odds-compare-kate' )
		);
	}

	/**
	 * Region select for The Odds API.
	 */
	public function field_region(): void {
		$current = $this->settings->region();
		echo '<select name="' . esc_attr( Settings::OPTION ) . '[odds_api_region]">';
		foreach ( Settings::REGIONS as $region ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $region ), selected( $current, $region, false ), esc_html( strtoupper( $region ) ) );
		}
		echo '</select> <p class="description">' . esc_html__( 'Which bookmakers The Odds API returns. UK gives around twenty.', 'odds-compare-kate' ) . '</p>';
	}

	/**
	 * Quota reserve number.
	 */
	public function field_reserve(): void {
		printf(
			'<input type="number" min="0" max="%d" name="%s[quota_reserve]" value="%d" class="small-text" /> <p class="description">%s</p>',
			(int) Sanitizer::MAX_RESERVE,
			esc_attr( Settings::OPTION ),
			(int) $this->settings->quota_reserve(),
			esc_html__( 'Credits to keep unused so you can still test from this screen. Automatic refreshes stop below this.', 'odds-compare-kate' )
		);
	}

	/**
	 * Market checkboxes with the cost hint.
	 */
	public function field_markets(): void {
		$selected = $this->settings->markets();
		foreach ( Markets::all() as $key => $label ) {
			printf(
				'<label><input type="checkbox" name="%s[markets][]" value="%s" class="odds-compare-market"%s /> %s</label><br />',
				esc_attr( Settings::OPTION ),
				esc_attr( $key ),
				checked( in_array( $key, $selected, true ), true, false ),
				esc_html( $label )
			);
		}
		printf(
			'<p class="description" data-odds-compare-cost="%s">%s</p>',
			/* translators: %d: number of credits */
			esc_attr__( 'One refresh costs %d credit(s) of the API quota: one per market.', 'odds-compare-kate' ),
			/* translators: %d: number of credits */
			esc_html( sprintf( __( 'One refresh costs %d credit(s) of the API quota: one per market.', 'odds-compare-kate' ), count( $selected ) ) )
		);
	}

	/**
	 * Default format radios.
	 */
	public function field_format(): void {
		$current = $this->settings->default_format()->value;
		foreach ( OddsFormat::labels() as $value => $label ) {
			printf(
				'<label><input type="radio" name="%s[default_format]" value="%s"%s /> %s</label><br />',
				esc_attr( Settings::OPTION ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}
	}

	/**
	 * Bookmaker table: enabled, name, link.
	 */
	public function section_bookmakers(): void {
		$bookmakers = $this->catalogue->all_with_seen();
		uasort( $bookmakers, static fn( $a, $b ) => strcasecmp( $a->title, $b->title ) );
		?>
		<p>
			<?php esc_html_e( 'Which bookmakers to show, and where their links go. Leave a link empty to use the bookmaker\'s homepage; put your affiliate URL there to earn on clicks. Bookmakers seen in provider data but unknown to the plugin are listed switched off.', 'odds-compare-kate' ); ?>
			<a href="#" class="odds-compare-select" data-select="all"><?php esc_html_e( 'Select all', 'odds-compare-kate' ); ?></a> ·
			<a href="#" class="odds-compare-select" data-select="none"><?php esc_html_e( 'Select none', 'odds-compare-kate' ); ?></a>
		</p>
		<table class="widefat striped odds-compare-admin__bookmakers">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Show', 'odds-compare-kate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Bookmaker', 'odds-compare-kate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Link', 'odds-compare-kate' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $bookmakers as $key => $bookmaker ) : ?>
				<?php $built_in = $this->catalogue->is_built_in( $key ); ?>
				<tr>
					<td>
						<input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[bookmakers][<?php echo esc_attr( $key ); ?>][enabled]" value="0" />
						<input type="checkbox" class="odds-compare-bookmaker" name="<?php echo esc_attr( Settings::OPTION ); ?>[bookmakers][<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( $this->settings->is_bookmaker_enabled( $key, $built_in ) ); ?> />
					</td>
					<td>
						<strong><?php echo esc_html( $bookmaker->title ); ?></strong>
						<code><?php echo esc_html( $key ); ?></code>
						<?php if ( ! $built_in ) : ?>
							<span class="odds-compare-admin__tag"><?php esc_html_e( 'discovered', 'odds-compare-kate' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<input type="url" class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[bookmakers][<?php echo esc_attr( $key ); ?>][url]" value="<?php echo esc_attr( (string) $this->settings->bookmaker_url( $key ) ); ?>" placeholder="<?php echo esc_attr( (string) $bookmaker->url ); ?>" />
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Fresh TTL input.
	 */
	public function field_fresh_ttl(): void {
		$this->seconds_field( 'fresh_ttl', $this->settings->fresh_ttl(), Sanitizer::MIN_FRESH_TTL, Sanitizer::MAX_FRESH_TTL, __( 'Seconds. With 500 credits a month and one market, 7200 (two hours) is as low as a single league can go.', 'odds-compare-kate' ) );
	}

	/**
	 * Stale TTL input.
	 */
	public function field_stale_ttl(): void {
		$this->seconds_field( 'stale_ttl', $this->settings->stale_ttl(), Sanitizer::MIN_STALE_TTL, Sanitizer::MAX_STALE_TTL, __( 'Seconds. How long old odds are still shown while the provider is unavailable.', 'odds-compare-kate' ) );
	}

	/**
	 * Scraper limit input.
	 */
	public function field_rate_limit(): void {
		$this->seconds_field( 'scraper_rate_limit', $this->settings->scraper_rate_limit(), 1, 60, __( 'Requests per minute the Oddschecker scraper may make.', 'odds-compare-kate' ) );
	}

	/**
	 * Number input helper.
	 *
	 * @param string $name        Setting key.
	 * @param int    $value       Current value.
	 * @param int    $min         Minimum.
	 * @param int    $max         Maximum.
	 * @param string $description Help text.
	 */
	private function seconds_field( string $name, int $value, int $min, int $max, string $description ): void {
		printf(
			'<input type="number" min="%d" max="%d" name="%s[%s]" value="%d" class="small-text" /> <p class="description">%s</p>',
			(int) $min,
			(int) $max,
			esc_attr( Settings::OPTION ),
			esc_attr( $name ),
			(int) $value,
			esc_html( $description )
		);
	}

	/**
	 * Shows only the last four characters of a secret.
	 *
	 * @param string $secret The secret.
	 */
	public static function mask( string $secret ): string {
		if ( strlen( $secret ) <= 4 ) {
			return str_repeat( '•', strlen( $secret ) );
		}

		return str_repeat( '•', 8 ) . substr( $secret, -4 );
	}
}
