<?php
/**
 * Reads embedded page state from Oddschecker HTML.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Provider\Oddschecker;

/**
 * Oddschecker renders with Hypernova, which leaves each component's props in the page as
 * <script type="application/json" data-hypernova-key="..."><!-- {json} --></script>.
 * That JSON is typed and stable where the CSS classes are hashed per build, so the parser
 * reads the JSON and never touches the visible markup.
 *
 * The tag is located with a string scan rather than DOMDocument on purpose: libxml2's HTML
 * parser ends script content at the first "</" it meets, and the JSON contains escaped
 * HTML from bookmaker offers, so DOM returned a truncated blob on the match page.
 */
final class HypernovaExtractor {

	/**
	 * Decoded props for a component key, or null when absent or unreadable.
	 *
	 * @param string $html Full page HTML.
	 * @param string $key  Value of data-hypernova-key.
	 *
	 * @return array<mixed>|null
	 */
	public function extract( string $html, string $key ): ?array {
		$json = $this->script_body( $html, $key );
		if ( null === $json ) {
			return null;
		}

		$data = json_decode( $this->unwrap( $json ), true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Raw content of the JSON script tag for a key. The rendered <div> carries the same
	 * attribute, so the search is anchored on the <script> opening.
	 *
	 * @param string $html Page HTML.
	 * @param string $key  Component key; attribute values are plain identifiers.
	 */
	private function script_body( string $html, string $key ): ?string {
		$needle = '<script type="application/json" data-hypernova-key="' . $key . '"';
		$start  = strpos( $html, $needle );
		if ( false === $start ) {
			return null;
		}

		$open  = strpos( $html, '>', $start );
		$close = false === $open ? false : strpos( $html, '</script>', $open );

		return false === $close ? null : substr( $html, $open + 1, $close - $open - 1 );
	}

	/**
	 * Strips the HTML comment Hypernova wraps the JSON in.
	 *
	 * @param string $json Raw script content.
	 */
	private function unwrap( string $json ): string {
		$json = trim( $json );
		if ( str_starts_with( $json, '<!--' ) ) {
			$json = substr( $json, 4 );
		}
		if ( str_ends_with( $json, '-->' ) ) {
			$json = substr( $json, 0, -3 );
		}

		return trim( $json );
	}
}
