<?php
/**
 * Settings sanitizer.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Settings;

use OddsCompare\Domain\OddsFormat;

/**
 * Turns whatever arrived in $_POST into a settings array the rest of the plugin can trust.
 * Unknown keys are dropped, numbers are clamped, lists are intersected with what the plugin
 * knows, and an invalid affiliate URL keeps the previous value and records an error for the
 * admin screen to display.
 */
final class Sanitizer {

	public const MIN_FRESH_TTL = MINUTE_IN_SECONDS;
	public const MAX_FRESH_TTL = DAY_IN_SECONDS;
	public const MIN_STALE_TTL = 5 * MINUTE_IN_SECONDS;
	public const MAX_STALE_TTL = 30 * DAY_IN_SECONDS;
	public const MAX_RESERVE   = 500;

	/**
	 * Messages for add_settings_error().
	 *
	 * @var string[]
	 */
	private array $errors = array();

	/**
	 * Builds a sanitizer.
	 *
	 * @param string[]             $provider_keys     Registered provider keys.
	 * @param array<string, mixed> $current           Current (already clean) settings.
	 * @param bool                 $key_from_constant Whether the API key is fixed in wp-config.php.
	 */
	public function __construct(
		private readonly array $provider_keys,
		private readonly array $current,
		private readonly bool $key_from_constant = false,
	) {}

	/**
	 * Sanitizes a submitted settings array.
	 *
	 * @param mixed $input Raw value from the form.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input        = is_array( $input ) ? $input : array();
		$this->errors = array();
		$clean        = $this->current;

		$provider = isset( $input['provider'] ) ? sanitize_key( (string) $input['provider'] ) : '';
		if ( in_array( $provider, $this->provider_keys, true ) ) {
			$clean['provider'] = $provider;
		}

		if ( ! $this->key_from_constant && array_key_exists( 'odds_api_key', $input ) ) {
			$clean['odds_api_key'] = substr( sanitize_text_field( (string) $input['odds_api_key'] ), 0, 64 );
		}

		$region = isset( $input['odds_api_region'] ) ? sanitize_key( (string) $input['odds_api_region'] ) : '';
		if ( in_array( $region, Settings::REGIONS, true ) ) {
			$clean['odds_api_region'] = $region;
		}

		if ( isset( $input['quota_reserve'] ) ) {
			$clean['quota_reserve'] = max( 0, min( self::MAX_RESERVE, (int) $input['quota_reserve'] ) );
		}

		if ( array_key_exists( 'markets', $input ) ) {
			$markets          = array_map( 'sanitize_key', array_map( 'strval', (array) $input['markets'] ) );
			$markets          = array_values( array_intersect( Markets::keys(), $markets ) );
			$clean['markets'] = $markets ? $markets : array( 'h2h' );
		}

		if ( isset( $input['default_format'] ) ) {
			$fallback                = OddsFormat::from_string( (string) ( $this->current['default_format'] ?? '' ) );
			$clean['default_format'] = OddsFormat::from_string( (string) $input['default_format'], $fallback )->value;
		}

		if ( isset( $input['fresh_ttl'] ) ) {
			$clean['fresh_ttl'] = max( self::MIN_FRESH_TTL, min( self::MAX_FRESH_TTL, (int) $input['fresh_ttl'] ) );
		}

		if ( isset( $input['stale_ttl'] ) ) {
			$clean['stale_ttl'] = max( self::MIN_STALE_TTL, min( self::MAX_STALE_TTL, (int) $input['stale_ttl'] ) );
		}
		// Stale data must outlive the freshness window or nothing is ever served stale.
		$clean['stale_ttl'] = max( (int) $clean['stale_ttl'], (int) $clean['fresh_ttl'] );

		if ( isset( $input['scraper_rate_limit'] ) ) {
			$clean['scraper_rate_limit'] = max( 1, min( 60, (int) $input['scraper_rate_limit'] ) );
		}

		if ( array_key_exists( 'bookmakers', $input ) ) {
			$clean['bookmakers'] = $this->bookmakers( (array) $input['bookmakers'], (array) ( $this->current['bookmakers'] ?? array() ) );
		}

		return array_intersect_key( $clean, Settings::defaults() );
	}

	/**
	 * Validation messages from the last sanitize() call.
	 *
	 * @return string[]
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Sanitizes the per-bookmaker rows.
	 *
	 * @param array<mixed>                $rows     Submitted rows keyed by bookmaker.
	 * @param array<string, array<mixed>> $previous Stored rows.
	 *
	 * @return array<string, array{enabled: bool, url: string}>
	 */
	private function bookmakers( array $rows, array $previous ): array {
		$clean = array();

		foreach ( $rows as $raw_key => $row ) {
			$key = self::bookmaker_key( (string) $raw_key );
			if ( '' === $key || ! is_array( $row ) ) {
				continue;
			}

			$url = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';
			if ( '' !== $url && ! self::is_http_url( $url ) ) {
				$this->errors[] = sprintf(
					/* translators: 1: bookmaker key, 2: the rejected URL */
					__( 'The link for %1$s was not saved: "%2$s" is not an http(s) URL.', 'odds-compare-kate' ),
					$key,
					$url
				);
				$url = (string) ( $previous[ $key ]['url'] ?? '' );
			}

			$clean[ $key ] = array(
				'enabled' => ! empty( $row['enabled'] ),
				'url'     => '' === $url ? '' : esc_url_raw( $url, array( 'http', 'https' ) ),
			);
		}//end foreach

		return $clean;
	}

	/**
	 * Canonical bookmaker keys are lower-case with a-z, 0-9, "_" and, for discovered ones,
	 * one "provider:code" colon. sanitize_key() would strip the colon, hence the custom rule.
	 *
	 * @param string $key Raw key.
	 */
	public static function bookmaker_key( string $key ): string {
		$key = strtolower( trim( $key ) );

		return preg_match( '/^[a-z0-9_]+(:[a-z0-9_]+)?$/', $key ) ? $key : '';
	}

	/**
	 * Absolute http(s) URL with a host. wp_http_validate_url() also rejects local
	 * and private hosts, which an affiliate link has no business pointing at.
	 *
	 * @param string $url Candidate URL.
	 */
	public static function is_http_url( string $url ): bool {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		return in_array( $scheme, array( 'http', 'https' ), true ) && false !== wp_http_validate_url( $url );
	}
}
