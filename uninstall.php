<?php
/**
 * Removes everything the plugin stored when it is deleted.
 *
 * @package Jcore\Siirto
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Drops the plugin's tables and options on the current site.
 *
 * Names are spelled out rather than read from the classes so this file stands
 * alone, as uninstall.php runs without the plugin loaded.
 *
 * Staging directories (wp-content/jcore-siirto-*) and the temporary tables of
 * an unfinished migration are left alone: they may be the only copy of a
 * replaced site. The admin screen's "Clean up" removes them.
 *
 * @return void
 */
function jcore_siirto_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'jcore_siirto_keys', 'jcore_siirto_jobs', 'jcore_siirto_log' ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
	}

	delete_option( 'jcore_siirto_db_version' );
	delete_option( 'jcore_siirto_source_until' );
	delete_transient( 'jcore_siirto_upgrading' );
}

if ( is_multisite() ) {
	$jcore_siirto_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $jcore_siirto_site_ids as $jcore_siirto_site_id ) {
		switch_to_blog( (int) $jcore_siirto_site_id );
		jcore_siirto_uninstall_site();
		restore_current_blog();
	}
} else {
	jcore_siirto_uninstall_site();
}
