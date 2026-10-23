<?php
/**
 * Sport / league identity.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Domain;

/**
 * A competition the provider can list events for, e.g. "soccer_epl" / "EPL" / "Soccer".
 */
final class Sport {

	/**
	 * Builds a sport.
	 *
	 * @param string $key   Provider's key.
	 * @param string $title Display title.
	 * @param string $group Top-level sport, e.g. "Soccer".
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $title,
		public readonly string $group = '',
	) {}

	/**
	 * Plain-array form for REST responses.
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		return array(
			'key'   => $this->key,
			'title' => $this->title,
			'group' => $this->group,
		);
	}
}
