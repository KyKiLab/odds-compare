<?php
/**
 * Provider failure.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Provider;

use OddsCompare\Http\HttpException;
use RuntimeException;

/**
 * Anything that stops a provider from returning data. The cache layer only looks at
 * is_retryable() to decide between "try again shortly" and "stop until someone fixes it".
 */
final class ProviderException extends RuntimeException {

	/**
	 * Builds an exception.
	 *
	 * @param string $message   Human readable reason, safe to show an administrator.
	 * @param bool   $retryable Whether a later attempt could succeed.
	 */
	public function __construct(
		string $message,
		public readonly bool $retryable = true,
	) {
		parent::__construct( $message );
	}

	/**
	 * Wraps a transport/HTTP failure.
	 *
	 * @param HttpException $http The failure.
	 * @param string        $label Provider label for the message.
	 */
	public static function from_http( HttpException $http, string $label ): self {
		return new self( sprintf( '%s: %s', $label, $http->getMessage() ), $http->is_retryable() );
	}

	/**
	 * Missing API key or similar.
	 *
	 * @param string $label Provider label.
	 */
	public static function not_configured( string $label ): self {
		return new self( sprintf( '%s is not configured.', $label ), false );
	}

	/**
	 * The source cannot be parsed.
	 *
	 * @param string $label  Provider label.
	 * @param string $detail What was wrong.
	 */
	public static function bad_response( string $label, string $detail ): self {
		return new self( sprintf( '%s returned an unexpected response: %s', $label, $detail ), true );
	}

	/**
	 * Whether a later attempt could succeed.
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}
}
