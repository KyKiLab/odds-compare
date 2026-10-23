<?php
/**
 * Site-wide admin notices.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Admin;

use OddsCompare\Cache\ProviderState;
use OddsCompare\Provider\OddsApiQuota;
use OddsCompare\Provider\ProviderRegistry;
use OddsCompare\Settings\Settings;

/**
 * Things an administrator should hear about without visiting the settings page: the API
 * quota running low and the active provider being paused by the circuit breaker.
 */
final class Notices {

	/**
	 * Builds the notices.
	 *
	 * @param Settings         $settings  Settings.
	 * @param ProviderRegistry $providers Providers.
	 * @param ProviderState    $state     Provider health.
	 * @param OddsApiQuota     $quota     API quota.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ProviderRegistry $providers,
		private readonly ProviderState $state,
		private readonly OddsApiQuota $quota,
	) {}

	/**
	 * Hooks the notices.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Prints whichever notices apply.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$provider  = $this->providers->resolve( $this->settings->provider_key() );
		$remaining = $this->quota->remaining();

		if ( 'odds_api' === $provider->key() && null !== $remaining && $remaining < $this->settings->quota_reserve() ) {
			$this->notice(
				'warning',
				sprintf(
					/* translators: 1: credits left, 2: reserve, 3: settings URL */
					__( 'Odds Compare: The Odds API quota is down to %1$d credits, below the reserve of %2$d. Automatic refreshes are paused until the quota resets. <a href="%3$s">Settings</a>', 'odds-compare-kate' ),
					$remaining,
					$this->settings->quota_reserve(),
					esc_url( SettingsPage::url() )
				)
			);
		}

		if ( $this->state->is_open( $provider->key() ) ) {
			$last = (string) ( $this->state->get( $provider->key() )['last_error'] ?? '' );
			$this->notice(
				'error',
				sprintf(
					/* translators: 1: provider label, 2: last error, 3: settings URL */
					__( 'Odds Compare: %1$s failed repeatedly and is paused for a few minutes. Last error: %2$s <a href="%3$s">Settings</a>', 'odds-compare-kate' ),
					esc_html( $provider->label() ),
					esc_html( $last ),
					esc_url( SettingsPage::url() )
				)
			);
		}
	}

	/**
	 * Prints one notice. The message may contain the link markup built above.
	 *
	 * @param string $type    warning|error.
	 * @param string $message Message with already escaped dynamic parts.
	 */
	private function notice( string $type, string $message ): void {
		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			esc_attr( $type ),
			wp_kses( $message, array( 'a' => array( 'href' => array() ) ) )
		);
	}
}
