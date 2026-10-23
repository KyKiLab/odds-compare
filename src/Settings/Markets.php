<?php
/**
 * Market catalogue.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Settings;

/**
 * Markets the plugin understands, keyed the way The Odds API names them. Other providers
 * map their own names onto these keys.
 */
final class Markets {

	/**
	 * Key => label, filtered.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		$markets = array(
			'h2h'     => __( 'Match result', 'odds-compare-kate' ),
			'totals'  => __( 'Total goals (over/under)', 'odds-compare-kate' ),
			'spreads' => __( 'Handicap', 'odds-compare-kate' ),
		);

		/**
		 * Filters the markets offered in settings and the block.
		 *
		 * Adding a key here only makes sense if the active provider returns quotes for it.
		 *
		 * @param array<string, string> $markets Key => label.
		 */
		$filtered = apply_filters( 'odds_compare_markets', $markets );

		return is_array( $filtered ) && $filtered ? $filtered : $markets;
	}

	/**
	 * Known keys.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array_keys( self::all() );
	}

	/**
	 * Label for a key, falling back to the key itself.
	 *
	 * @param string $key Market key.
	 */
	public static function label( string $key ): string {
		return self::all()[ $key ] ?? $key;
	}
}
