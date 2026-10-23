<?php
/**
 * HTTP failure.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Http;

use RuntimeException;
use WP_Error;

/**
 * Raised by Client for anything that is not a 2xx. Carries the one fact the cache layer
 * cares about: whether trying again later makes sense.
 */
final class HttpException extends RuntimeException {

	/**
	 * Builds an exception.
	 *
	 * @param string   $message   Human readable reason.
	 * @param int|null $status    HTTP status, or null for transport failures.
	 * @param bool     $retryable Whether a later attempt could succeed.
	 */
	public function __construct(
		string $message,
		public readonly ?int $status = null,
		public readonly bool $retryable = true,
	) {
		parent::__construct( $message, (int) $status );
	}

	/**
	 * Timeouts, DNS and TLS failures: all worth retrying.
	 *
	 * @param WP_Error $error Error from wp_remote_get().
	 */
	public static function from_wp_error( WP_Error $error ): self {
		return new self( $error->get_error_message(), null, true );
	}

	/**
	 * Non-2xx response. 5xx and 429 are temporary; other 4xx are not (bad key, blocked, gone).
	 *
	 * @param Response $response The response.
	 */
	public static function from_response( Response $response ): self {
		$status    = $response->status;
		$retryable = $status >= 500 || 429 === $status;

		return new self( sprintf( 'HTTP %d', $status ), $status, $retryable );
	}

	/**
	 * Local rate limiter refused the request.
	 *
	 * @param string $bucket Limiter bucket.
	 * @param int    $retry_after Seconds until the window frees up.
	 */
	public static function rate_limited( string $bucket, int $retry_after ): self {
		return new self( sprintf( 'Rate limit reached for "%s", retry in %ds', $bucket, $retry_after ), 429, true );
	}

	/**
	 * Whether a later attempt could succeed.
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}
}
