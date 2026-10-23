<?php
/**
 * HTTP client.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Http;

use OddsCompare\Support\Logger;

/**
 * The only place the plugin talks to the network.
 *
 * WordPress' wp_remote_get() already handles proxies (WP_PROXY_*), transports and the http_api filters;
 * this adds what every provider needs and should not repeat: sane timeouts, a response size
 * cap, an honest User-Agent, one retry for transient failures and the local rate limiter.
 */
final class Client {

	public const DEFAULT_TIMEOUT = 8;

	/**
	 * 2 MB. A league page is ~800 KB; an API response for one sport is ~120 KB.
	 * Beyond the cap the transport stops reading and the body fails to parse, which the
	 * provider reports as an error instead of exhausting memory.
	 */
	public const MAX_BYTES = 2097152;

	private const RETRY_DELAY_US = 200000;

	/**
	 * Builds a client.
	 *
	 * @param RateLimiter|null $limiter Rate limiter, or null for none.
	 * @param Logger|null      $logger  Logger, or null to stay silent.
	 */
	public function __construct(
		private readonly ?RateLimiter $limiter = null,
		private readonly ?Logger $logger = null,
	) {}

	/**
	 * GET with one retry on retryable failures.
	 *
	 * @param string               $url    Absolute URL.
	 * @param array<string, mixed> $args   Extra wp_remote_get() args; merged over the defaults.
	 * @param string               $bucket Rate limiter bucket, usually the provider key.
	 *
	 * @throws HttpException On a non-2xx response, a transport failure, or when rate limited.
	 */
	public function get( string $url, array $args = array(), string $bucket = 'default' ): Response {
		if ( $this->limiter && ! $this->limiter->allow( $bucket ) ) {
			throw HttpException::rate_limited( $bucket, $this->limiter->retry_after( $bucket ) );
		}

		$args = wp_parse_args(
			$args,
			array(
				'timeout'             => self::DEFAULT_TIMEOUT,
				'redirection'         => 2,
				'sslverify'           => true,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => $this->user_agent(),
			)
		);

		/**
		 * Filters the arguments passed to wp_remote_get() for every provider request.
		 *
		 * @param array  $args   Request arguments.
		 * @param string $url    Request URL.
		 * @param string $bucket Rate limiter bucket (provider key).
		 */
		$args = apply_filters( 'odds_compare_http_args', $args, $url, $bucket );

		for ( $attempt = 1; ; $attempt++ ) {
			$result = wp_remote_get( $url, $args );

			if ( is_wp_error( $result ) ) {
				$exception = HttpException::from_wp_error( $result );
			} else {
				$response = new Response(
					(int) wp_remote_retrieve_response_code( $result ),
					$this->headers_as_array( wp_remote_retrieve_headers( $result ) ),
					(string) wp_remote_retrieve_body( $result )
				);
				if ( $response->is_success() ) {
					return $response;
				}
				$exception = HttpException::from_response( $response );
			}

			$this->logger?->warning(
				'Request failed',
				array(
					'url'     => self::redact( $url ),
					'attempt' => $attempt,
					'error'   => $exception->getMessage(),
				)
			);

			if ( $attempt >= 2 || ! $exception->is_retryable() ) {
				throw $exception;
			}

			usleep( self::RETRY_DELAY_US );
		}//end for
	}

	/**
	 * Hides credentials passed as query arguments so a failed request can be logged safely.
	 *
	 * @param string $url Request URL.
	 */
	public static function redact( string $url ): string {
		return (string) preg_replace( '/([?&](?:apiKey|api_key|key|token|access_token|secret)=)[^&#]*/i', '$1***', $url );
	}

	/**
	 * Identifies the plugin to the remote host, as a polite scraper should.
	 */
	private function user_agent(): string {
		return sprintf( 'odds-compare-kate/%s; %s', ODDS_COMPARE_VERSION, home_url( '/' ) );
	}

	/**
	 * On real requests wp_remote_retrieve_headers() returns a CaseInsensitiveDictionary, in tests
	 * an array; Response wants an array either way.
	 *
	 * @param mixed $headers Whatever WordPress returned.
	 *
	 * @return array<string, string>
	 */
	private function headers_as_array( mixed $headers ): array {
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		return is_array( $headers ) ? $headers : array();
	}
}
