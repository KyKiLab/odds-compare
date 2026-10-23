<?php
/**
 * HTTP response.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Http;

use JsonException;

/**
 * Thin wrapper over what wp_remote_get() returns, with the bits providers need.
 */
final class Response {

	/**
	 * Headers with lower-case names.
	 *
	 * @var array<string, string>
	 */
	private array $headers;

	/**
	 * Builds a response.
	 *
	 * @param int                   $status  HTTP status code.
	 * @param array<string, string> $headers Response headers, any case.
	 * @param string                $body    Raw body.
	 */
	public function __construct(
		public readonly int $status,
		array $headers,
		public readonly string $body,
	) {
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
	}

	/**
	 * 2xx.
	 */
	public function is_success(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Header value by case-insensitive name, or null.
	 *
	 * @param string $name Header name.
	 */
	public function header( string $name ): ?string {
		$value = $this->headers[ strtolower( $name ) ] ?? null;

		return null === $value ? null : (string) $value;
	}

	/**
	 * Body decoded as a JSON array.
	 *
	 * @throws JsonException When the body is not valid JSON (including a body truncated by the size limit).
	 *
	 * @return array<mixed>
	 */
	public function json(): array {
		$data = json_decode( $this->body, true, 512, JSON_THROW_ON_ERROR );

		return is_array( $data ) ? $data : array();
	}
}
