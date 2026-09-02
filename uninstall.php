<?php
/**
 * Uninstall script for Outpost.
 *
 * Runs once when an admin deletes the plugin in wp-admin/plugins.php (after
 * deactivating it). Deletes exactly the persistent data Outpost created and
 * nothing WordPress or another plugin owns.
 *
 * Deactivation (`outpost_deactivate()` in outpost.php) is reversible and must
 * NOT delete user data. Uninstall is one-way — the admin chose to remove
 * Outpost, so its own options, encrypted credentials, keys, tokens, caches,
 * transients, and scheduled events are removed here, on single-site and every
 * site of a multisite network. Posts, attachments, post content, alt text,
 * featured images, Yoast keys, and category terms are left untouched.
 *
 * The plugin is not loaded during uninstall, so the cleanup lives in a class
 * that uses only core WordPress APIs and is required explicitly. Keeping it in
 * a class (rather than inline here) makes the census testable — see
 * tests/integration/UninstallTest.php.
 *
 * @package Outpost
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-outpost-uninstaller.php';

Outpost_Uninstaller::run();
