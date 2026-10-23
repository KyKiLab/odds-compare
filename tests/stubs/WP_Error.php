<?php
/**
 * Just enough of WP_Error for unit tests.
 *
 * @package OddsCompare
 */

final class WP_Error {

	public function __construct(
		private string $code = '',
		private string $message = '',
		private mixed $data = null,
	) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}
