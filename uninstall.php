<?php
/**
 * Uninstall handler for Media Mage.
 *
 * Runs when the user deletes the plugin from the WordPress admin
 * (NOT when they merely deactivate it). Cleans up every piece of
 * state the plugin wrote to the database so the site is left exactly
 * as it was before Media Mage was installed.
 *
 * Removes:
 *   - wpmj_results transient (last scan results)
 *   - wpmj_scan_state transient (in-progress scan state)
 *   - _wpmj_hash postmeta on every attachment (cached MD5)
 *   - _wpmj_hash_mtime postmeta on every attachment (cache key)
 *   - _wpmj_ignored postmeta (the user's ignore list)
 *   - _wpmj_trashed_at postmeta (stamp marking what this plugin trashed)
 *
 * Deliberately does NOT untrash anything. Attachments this plugin moved to the
 * trash stay in the trash, where WordPress itself can restore or empty them.
 * Removing the plugin is not consent to resurrect files the user chose to
 * delete, and it is not consent to destroy them either.
 *
 * Does NOT touch:
 *   - any attachment, post, page, or option that wasn't created by this plugin
 *   - the user's actual media files on disk
 */

// Only run when WordPress invokes this file via the uninstall mechanism.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1. Drop scan-related transients (regular and site-wide).
delete_transient( 'wpmj_results' );
delete_transient( 'wpmj_scan_state' );
delete_site_transient( 'wpmj_results' );
delete_site_transient( 'wpmj_scan_state' );

// 2. Clear every piece of postmeta this plugin wrote: cached MD5 hashes and
//    their mtime cache key, the ignore list, and the trashed-by-us stamp.
foreach ( [ '_wpmj_hash', '_wpmj_hash_mtime', '_wpmj_ignored', '_wpmj_trashed_at' ] as $wpmj_meta_key ) {
	$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $wpmj_meta_key ] );
}

// 3. Multisite: do the same on every site if this is a network deactivation.
if ( is_multisite() ) {
	$sites = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
	foreach ( $sites as $site_id ) {
		switch_to_blog( $site_id );
		delete_transient( 'wpmj_results' );
		delete_transient( 'wpmj_scan_state' );
		foreach ( [ '_wpmj_hash', '_wpmj_hash_mtime', '_wpmj_ignored', '_wpmj_trashed_at' ] as $wpmj_meta_key ) {
			$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $wpmj_meta_key ] );
		}
		restore_current_blog();
	}
}
