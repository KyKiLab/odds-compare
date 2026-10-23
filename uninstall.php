<?php
/**
 * Removes every option the plugin created. Runs when the plugin is deleted from wp-admin.
 *
 * @package OddsCompare
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Cache keys are tracked in an index option rather than discovered with a LIKE query on
// wp_options: with a persistent object cache the transients are not in the database at all.
$odds_compare_index = get_option( 'odds_compare_cache_index', array() );
foreach ( array_keys( (array) $odds_compare_index ) as $odds_compare_key ) {
	delete_transient( $odds_compare_key );
}

foreach ( array(
	'odds_compare_settings',
	'odds_compare_quota',
	'odds_compare_provider_state',
	'odds_compare_seen_bookmakers',
	'odds_compare_cache_index',
) as $odds_compare_option ) {
	delete_option( $odds_compare_option );
}
