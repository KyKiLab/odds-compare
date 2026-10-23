<?php
/**
 * Plugin settings.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Settings;

use OddsCompare\Domain\OddsFormat;
use OddsCompare\Provider\FixtureProvider;

/**
 * Typed access to the single settings option. One option, one get_option() per request,
 * one place for defaults; the sanitizer is the only writer besides activation.
 */
final class Settings {

	public const OPTION       = 'odds_compare_settings';
	public const KEY_CONSTANT = 'ODDS_COMPARE_ODDS_API_KEY';

	public const REGIONS = array( 'uk', 'eu', 'us', 'us2', 'au' );

	/**
	 * Cached option value for the request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $values = null;

	/**
	 * Defaults for a fresh install.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'provider'           => FixtureProvider::KEY,
			'odds_api_key'       => '',
			'odds_api_region'    => 'uk',
			'quota_reserve'      => 20,
			'markets'            => array( 'h2h' ),
			'default_format'     => OddsFormat::Decimal->value,
			'fresh_ttl'          => 2 * HOUR_IN_SECONDS,
			'stale_ttl'          => DAY_IN_SECONDS,
			'scraper_rate_limit' => 10,
			'bookmakers'         => array(),
		);
	}

	/**
	 * Every setting, defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->values ) {
			$stored       = get_option( self::OPTION );
			$this->values = array_replace( self::defaults(), is_array( $stored ) ? $stored : array() );
		}

		return $this->values;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 */
	public function get( string $key ): mixed {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Replaces the stored option and forgets the request cache.
	 *
	 * @param array<string, mixed> $values Complete, already sanitized values.
	 */
	public function save( array $values ): void {
		update_option( self::OPTION, $values );
		$this->values = null;
	}

	/**
	 * Active provider key.
	 */
	public function provider_key(): string {
		return (string) $this->get( 'provider' );
	}

	/**
	 * The Odds API key: the wp-config.php constant wins over the stored value, so the
	 * secret can stay out of the database and its backups.
	 */
	public function api_key(): string {
		if ( $this->api_key_from_constant() ) {
			return (string) constant( self::KEY_CONSTANT );
		}

		return (string) $this->get( 'odds_api_key' );
	}

	/**
	 * Whether the key comes from wp-config.php.
	 */
	public function api_key_from_constant(): bool {
		return defined( self::KEY_CONSTANT ) && '' !== (string) constant( self::KEY_CONSTANT );
	}

	/**
	 * Bookmaker region for The Odds API.
	 */
	public function region(): string {
		return (string) $this->get( 'odds_api_region' );
	}

	/**
	 * Credits to keep unused.
	 */
	public function quota_reserve(): int {
		return (int) $this->get( 'quota_reserve' );
	}

	/**
	 * Markets to fetch and offer.
	 *
	 * @return string[]
	 */
	public function markets(): array {
		return array_values( (array) $this->get( 'markets' ) );
	}

	/**
	 * Site-wide odds format.
	 */
	public function default_format(): OddsFormat {
		return OddsFormat::from_string( (string) $this->get( 'default_format' ) );
	}

	/**
	 * Freshness window in seconds.
	 */
	public function fresh_ttl(): int {
		return (int) $this->get( 'fresh_ttl' );
	}

	/**
	 * How long stale data stays available, in seconds.
	 */
	public function stale_ttl(): int {
		return (int) $this->get( 'stale_ttl' );
	}

	/**
	 * Requests per minute allowed for scraping providers.
	 */
	public function scraper_rate_limit(): int {
		return (int) $this->get( 'scraper_rate_limit' );
	}

	/**
	 * Per-bookmaker overrides: key => [ enabled => bool, url => string ].
	 *
	 * @return array<string, array{enabled: bool, url: string}>
	 */
	public function bookmaker_overrides(): array {
		return (array) $this->get( 'bookmakers' );
	}

	/**
	 * Whether a bookmaker should be shown. Built-in bookmakers are on unless switched off;
	 * ones discovered in provider data are off until switched on.
	 *
	 * @param string $key      Canonical bookmaker key.
	 * @param bool   $built_in Whether the key is in the shipped catalogue.
	 */
	public function is_bookmaker_enabled( string $key, bool $built_in ): bool {
		$override = $this->bookmaker_overrides()[ $key ]['enabled'] ?? null;

		return null === $override ? $built_in : (bool) $override;
	}

	/**
	 * Affiliate/override link for a bookmaker, or null to use the catalogue URL.
	 *
	 * @param string $key Canonical bookmaker key.
	 */
	public function bookmaker_url( string $key ): ?string {
		$url = $this->bookmaker_overrides()[ $key ]['url'] ?? '';

		return '' === $url ? null : (string) $url;
	}
}
