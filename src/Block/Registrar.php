<?php
/**
 * Block registration.
 *
 * @package OddsCompare
 */

namespace OddsCompare\Block;

use OddsCompare\Domain\OddsFormat;
use OddsCompare\Settings\Markets;
use OddsCompare\Settings\Settings;

/**
 * Registers the block from the compiled build/ directory and hands the editor the bits of
 * configuration it needs. Without a build (a git clone that has not run npm) the block is
 * not registered and the administrator gets a notice instead of a broken site.
 */
final class Registrar {

	/**
	 * Builds the registrar.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Hooks registration, editor data and the missing-build notice.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_data' ) );
		add_action( 'admin_notices', array( $this, 'missing_build_notice' ) );
	}

	/**
	 * Whether the compiled block exists.
	 */
	public static function is_built(): bool {
		return is_file( ODDS_COMPARE_DIR . 'build/blocks-manifest.php' );
	}

	/**
	 * Registers every block in the manifest (one, today) with a single call.
	 */
	public function register_block(): void {
		if ( ! self::is_built() ) {
			return;
		}

		wp_register_block_types_from_metadata_collection( ODDS_COMPARE_DIR . 'build', ODDS_COMPARE_DIR . 'build/blocks-manifest.php' );
	}

	/**
	 * Markets, formats and links for the editor controls.
	 */
	public function editor_data(): void {
		if ( ! self::is_built() ) {
			return;
		}

		$markets = array();
		foreach ( $this->settings->markets() as $key ) {
			$markets[ $key ] = Markets::label( $key );
		}

		wp_add_inline_script(
			generate_block_asset_handle( Renderer::BLOCK_NAME, 'editorScript' ),
			'window.oddsCompareEditor = ' . wp_json_encode(
				array(
					'markets'       => $markets,
					'formats'       => OddsFormat::labels(),
					'defaultFormat' => $this->settings->default_format()->value,
					'settingsUrl'   => admin_url( 'options-general.php?page=odds-compare-kate' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Tells administrators how to get the block when build/ is missing.
	 */
	public function missing_build_notice(): void {
		if ( self::is_built() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <code>npm install &amp;&amp; npm run build</code></p></div>',
			esc_html__( 'Odds Compare: the block has not been built. Run this in the plugin directory:', 'odds-compare-kate' )
		);
	}
}
