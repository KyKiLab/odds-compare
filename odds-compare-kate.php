<?php
/**
 * Plugin Name:       Odds Compare
 * Plugin URI:        https://github.com/KyKiLab/odds-compare
 * Description:       Live odds comparison from multiple bookmakers with pluggable data providers, caching and a Gutenberg block.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Kate Kulikova
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       odds-compare-kate
 * Domain Path:       /languages
 *
 * @package OddsCompare
 */

defined( 'ABSPATH' ) || exit;

define( 'ODDS_COMPARE_VERSION', '1.0.0' );
define( 'ODDS_COMPARE_FILE', __FILE__ );
define( 'ODDS_COMPARE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ODDS_COMPARE_URL', plugin_dir_url( __FILE__ ) );

// No Composer at runtime: a zip install must work without a build step. The "Requires PHP"
// header above stops WordPress from activating this on PHP < 8.1, so nothing below needs a guard.
require_once ODDS_COMPARE_DIR . 'src/Autoloader.php';
OddsCompare\Autoloader::register( ODDS_COMPARE_DIR . 'src/' );

register_activation_hook( __FILE__, array( OddsCompare\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( OddsCompare\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( OddsCompare\Plugin::instance(), 'boot' ) );
