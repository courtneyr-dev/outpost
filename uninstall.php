<?php
/**
 * Uninstall script for Outpost.
 *
 * Runs once when an admin clicks "Delete" on the plugin in wp-admin/plugins.php
 * (after first deactivating it). Cleans up persistent data Outpost added.
 *
 * Important distinctions, per WordPress plugin conventions:
 *
 *   - Deactivation (`outpost_deactivate()` in outpost.php) is reversible and
 *     must NOT delete user data — only clears transients/caches and flushes
 *     rewrite rules.
 *   - Uninstall (this file) is one-way: the user has explicitly chosen to
 *     remove Outpost. Persistent data added to the site is fair game to delete.
 *
 * Safe to extend: as future surfaces add persistent state, append the cleanup
 * here. Forward-looking comments below mark where each addition belongs.
 *
 * @package Outpost
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// A2 Locked Decision #11 — version-pinned rewrite-rule flush stashes
// this option to detect mismatch on init. The only persistent data Outpost
// stores at v0.1.6.
delete_option( 'outpost_rewrite_version' );

/*
 * Future cleanup — uncomment as surfaces land:
 *
 * Phase H — settings page:
 *     delete_option( 'outpost_settings' );
 *
 * B2 — server-side mf2 preview transient cache:
 *     global $wpdb;
 *     $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_outpost\\_preview\\_%'" );
 *     $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_outpost\\_preview\\_%'" );
 *
 * Phase F — companion adapters with per-adapter config:
 *     delete_option( 'outpost_companion_<name>_config' );
 *
 * Multisite: when uninstalling on a network, iterate sites:
 *     if ( is_multisite() ) {
 *         foreach ( get_sites( array( 'fields' => 'ids' ) ) as $blog_id ) {
 *             switch_to_blog( $blog_id );
 *             delete_option( 'outpost_rewrite_version' );
 *             // ... per-site cleanup ...
 *             restore_current_blog();
 *         }
 *     }
 */
