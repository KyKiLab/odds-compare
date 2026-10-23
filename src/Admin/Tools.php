<?php
/**
 * Admin actions: purge cache, refresh now.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Admin;

use Closure;
use OddsCompare\Cache\OddsCache;
use OddsCompare\Cache\ProviderState;
use OddsCompare\Provider\ProviderException;

/**
 * Two admin-post handlers behind nonces and manage_options, reporting back through a
 * redirect to the settings page.
 */
final class Tools {

	public const ACTION_PURGE   = 'odds_compare_purge';
	public const ACTION_REFRESH = 'odds_compare_refresh_now';

	private const NOTICE_TRANSIENT = 'odds_compare_tools_notice_';

	/**
	 * Builds the tools.
	 *
	 * @param Closure       $repository_factory fn( string $provider_key ): ?OddsRepository.
	 * @param OddsCache     $cache              Cache.
	 * @param ProviderState $state              Provider health.
	 */
	public function __construct(
		private readonly Closure $repository_factory,
		private readonly OddsCache $cache,
		private readonly ProviderState $state,
	) {}

	/**
	 * Hooks the handlers and the result notice.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_PURGE, array( $this, 'purge' ) );
		add_action( 'admin_post_' . self::ACTION_REFRESH, array( $this, 'refresh' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Drops cached odds and resets provider health.
	 */
	public function purge(): void {
		$this->guard( self::ACTION_PURGE );

		$count = $this->cache->purge();
		$this->state->reset();

		/* translators: %d: number of cache entries */
		$this->finish( 'success', sprintf( __( 'Cache cleared: %d entries removed.', 'odds-compare-kate' ), $count ) );
	}

	/**
	 * Re-fetches every sport/markets combination the cache knows about, synchronously.
	 */
	public function refresh(): void {
		$this->guard( self::ACTION_REFRESH );

		$done   = 0;
		$errors = array();
		foreach ( $this->cache->entries() as $meta ) {
			if ( empty( $meta['sport'] ) || empty( $meta['provider'] ) ) {
				continue;
			}
			$repository = ( $this->repository_factory )( (string) $meta['provider'] );
			if ( ! $repository ) {
				continue;
			}
			try {
				$repository->refresh( (string) $meta['sport'], (array) ( $meta['markets'] ?? array() ) );
				++$done;
			} catch ( ProviderException $e ) {
				$errors[] = $e->getMessage();
			}
		}

		if ( $errors ) {
			/* translators: 1: number refreshed, 2: number failed, 3: first error message */
			$this->finish( 'error', sprintf( __( 'Refreshed %1$d, failed %2$d. %3$s', 'odds-compare-kate' ), $done, count( $errors ), $errors[0] ) );
		}
		if ( 0 === $done ) {
			$this->finish( 'warning', __( 'Nothing to refresh yet: odds are fetched the first time a block shows them.', 'odds-compare-kate' ) );
		}
		/* translators: %d: number refreshed */
		$this->finish( 'success', sprintf( __( 'Refreshed %d cache entries.', 'odds-compare-kate' ), $done ) );
	}

	/**
	 * Prints the result of the last action, once.
	 */
	public function notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( in_array( $notice['type'], array( 'success', 'warning', 'error' ), true ) ? $notice['type'] : 'info' ),
			esc_html( (string) $notice['message'] )
		);
	}

	/**
	 * Buttons for the settings page.
	 */
	public static function render_buttons(): void {
		?>
		<h2><?php esc_html_e( 'Tools', 'odds-compare-kate' ); ?></h2>
		<div class="odds-compare-admin__tools">
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_PURGE ); ?>" />
				<?php wp_nonce_field( self::ACTION_PURGE ); ?>
				<?php submit_button( __( 'Clear cache', 'odds-compare-kate' ), 'secondary', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'Removes cached odds and resets provider errors. The next page view fetches again.', 'odds-compare-kate' ); ?></p>
			</form>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_REFRESH ); ?>" />
				<?php wp_nonce_field( self::ACTION_REFRESH ); ?>
				<?php submit_button( __( 'Refresh now', 'odds-compare-kate' ), 'secondary', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'Fetches fresh odds for everything currently cached. Costs one API credit per market per cached league.', 'odds-compare-kate' ); ?></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Capability and nonce check; dies on failure.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'odds-compare-kate' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Stores the notice and redirects back. Never returns.
	 *
	 * @param string $type    success|warning|error.
	 * @param string $message Text.
	 */
	private function finish( string $type, string $message ): never {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( SettingsPage::url() );
		exit;
	}
}
