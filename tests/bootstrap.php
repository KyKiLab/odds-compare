<?php
/**
 * PHPUnit bootstrap. Unit tests run without WordPress: Brain Monkey stubs the functions we touch.
 *
 * @package OddsCompare
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ODDS_COMPARE_VERSION', '1.0.0-test' );
define( 'ODDS_COMPARE_FILE', dirname( __DIR__ ) . '/odds-compare-kate.php' );
define( 'ODDS_COMPARE_DIR', dirname( __DIR__ ) . '/' );
define( 'ODDS_COMPARE_URL', 'https://example.test/wp-content/plugins/odds-compare-kate/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'ODDS_COMPARE_FIXTURES', __DIR__ . '/fixtures/' );

require __DIR__ . '/stubs/WP_Error.php';
require __DIR__ . '/stubs/WP_REST_Request.php';
