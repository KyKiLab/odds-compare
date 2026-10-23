<?php
/**
 * The Odds API quota bookkeeping.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Provider;

use OddsCompare\Http\Response;

/**
 * Remembers the x-requests-remaining / x-requests-used headers the API sends with every
 * response, so the plugin can stop before the monthly allowance runs out and the admin
 * screen can show how much is left. One request costs (regions x markets) credits.
 */
final class OddsApiQuota {

	public const OPTION = 'odds_compare_quota';

	/**
	 * Updates the stored quota from a response, if it carried the headers.
	 *
	 * @param Response $response API response.
	 */
	public function record( Response $response ): void {
		$remaining = $response->header( 'x-requests-remaining' );
		if ( null === $remaining ) {
			return;
		}

		update_option(
			self::OPTION,
			array(
				'remaining'  => (int) $remaining,
				'used'       => (int) $response->header( 'x-requests-used' ),
				'updated_at' => time(),
			),
			false
		);
	}

	/**
	 * Credits left, or null when no request has been made yet.
	 */
	public function remaining(): ?int {
		$quota = get_option( self::OPTION );

		return is_array( $quota ) && isset( $quota['remaining'] ) ? (int) $quota['remaining'] : null;
	}

	/**
	 * Whether a request costing $cost credits keeps at least $reserve in hand. Unknown
	 * quota (first request) is allowed through: the response will tell us.
	 *
	 * @param int $cost    Credits the request will use.
	 * @param int $reserve Credits to keep for manual checks from the admin screen.
	 */
	public function can_spend( int $cost, int $reserve ): bool {
		$remaining = $this->remaining();

		return null === $remaining || $remaining - $cost >= $reserve;
	}

	/**
	 * Raw stored values for the admin screen.
	 *
	 * @return array{remaining: int, used: int, updated_at: int}|null
	 */
	public function snapshot(): ?array {
		$quota = get_option( self::OPTION );

		return is_array( $quota ) && isset( $quota['remaining'] ) ? $quota : null;
	}
}
