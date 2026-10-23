<?php
/**
 * Just enough of WP_REST_Request for unit tests.
 *
 * @package OddsCompare
 */

final class WP_REST_Request {

	/** @param array<string, mixed> $params */
	public function __construct( private array $params = array() ) {}

	public function get_param( string $key ): mixed {
		return $this->params[ $key ] ?? null;
	}
}
