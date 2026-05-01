<?php
/**
 * Plugin Name:       Outpost
 * Plugin URI:        https://github.com/courtneyr-dev/outpost
 * Description:       Mobile-first Progressive Web App composer for IndieWeb POSSE workflows. Post notes, replies, likes, photos, and life-tracking entries from your phone, with one-tap syndication. Requires the Micropub plugin.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Tested up to:      6.9
 * Requires PHP:      8.2
 * Author:            Courtney Robertson
 * Author URI:        https://courtneyr.dev
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       outpost
 * Domain Path:       /languages
 *
 * @package Outpost
 */

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin metadata constants.
define( 'OUTPOST_VERSION', '0.1.0' );
define( 'OUTPOST_PLUGIN_FILE', __FILE__ );
define( 'OUTPOST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OUTPOST_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OUTPOST_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Requirements.
define( 'OUTPOST_MIN_WP', '6.5' );
define( 'OUTPOST_MIN_PHP', '8.2' );

// Companion plugin file paths (for is_plugin_active checks).
define( 'OUTPOST_MICROPUB_PLUGIN_FILE', 'micropub/micropub.php' );

/**
 * Check whether the host environment meets the plugin's minimum requirements.
 *
 * @since 0.1.0
 *
 * @return bool True when both WP and PHP versions meet the floor.
 */
function outpost_meets_requirements(): bool {
	return version_compare( get_bloginfo( 'version' ), OUTPOST_MIN_WP, '>=' )
		&& version_compare( PHP_VERSION, OUTPOST_MIN_PHP, '>=' );
}

/**
 * Detect the current state of the required Micropub companion plugin.
 *
 * Returns one of three values describing what the user needs to do next.
 *
 * @since 0.1.0
 *
 * @return string One of 'active', 'inactive', or 'absent'.
 */
function outpost_micropub_status(): string {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( is_plugin_active( OUTPOST_MICROPUB_PLUGIN_FILE ) ) {
		return 'active';
	}

	$installed = get_plugins();
	if ( isset( $installed[ OUTPOST_MICROPUB_PLUGIN_FILE ] ) ) {
		return 'inactive';
	}

	return 'absent';
}

/**
 * Whether the plugin's full feature surface (PWA composer, REST endpoints) is available.
 *
 * Used by the route handler and REST controller in later sessions to decide whether
 * to render the composer or a friendly install-prompt page (PWA) / 503 response (REST).
 *
 * @since 0.1.0
 *
 * @return bool True only when Micropub is active.
 */
function outpost_is_ready(): bool {
	return outpost_meets_requirements() && 'active' === outpost_micropub_status();
}

/**
 * Render the Micropub status admin notice on every admin screen.
 *
 * Hybrid gate: plugin loads regardless, but warns when Micropub is missing
 * or installed-but-deactivated. Admins with insufficient capabilities see nothing.
 *
 * @since 0.1.0
 */
function outpost_render_admin_notices(): void {
	if ( ! current_user_can( 'install_plugins' ) ) {
		return;
	}

	if ( ! outpost_meets_requirements() ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: minimum WP version, 2: minimum PHP version. */
					__( 'Outpost requires WordPress %1$s or newer and PHP %2$s or newer.', 'outpost' ),
					OUTPOST_MIN_WP,
					OUTPOST_MIN_PHP
				)
			)
		);
		return;
	}

	$status = outpost_micropub_status();
	if ( 'active' === $status ) {
		return;
	}

	if ( 'inactive' === $status ) {
		$action_url   = wp_nonce_url(
			self_admin_url( 'plugins.php?action=activate&plugin=' . OUTPOST_MICROPUB_PLUGIN_FILE ),
			'activate-plugin_' . OUTPOST_MICROPUB_PLUGIN_FILE
		);
		$action_label = __( 'Activate Micropub', 'outpost' );
		$message      = __( 'Outpost needs the Micropub plugin to be activated before the composer can run.', 'outpost' );
	} else {
		$action_url   = wp_nonce_url(
			self_admin_url( 'update.php?action=install-plugin&plugin=micropub' ),
			'install-plugin_micropub'
		);
		$action_label = __( 'Install Micropub', 'outpost' );
		$message      = __( 'Outpost requires the Micropub plugin by David Shanske. Install it from WordPress.org to activate the composer.', 'outpost' );
	}

	printf(
		'<div class="notice notice-warning"><p>%1$s &nbsp; <a class="button button-primary" href="%2$s">%3$s</a></p></div>',
		esc_html( $message ),
		esc_url( $action_url ),
		esc_html( $action_label )
	);
}
add_action( 'admin_notices', 'outpost_render_admin_notices' );

/**
 * Register the plugin's row meta link to the bookmarklet generator (added in Phase E).
 *
 * Stub for Session A0; expanded in later sessions.
 *
 * @since 0.1.0
 *
 * @param string[] $links Plugin action links.
 * @return string[] Filtered action links.
 */
function outpost_filter_plugin_action_links( array $links ): array {
	// Future sessions add a "Settings" and "Bookmarklets" link here.
	return $links;
}
add_filter( 'plugin_action_links_' . OUTPOST_PLUGIN_BASENAME, 'outpost_filter_plugin_action_links' );

/**
 * Activation hook. Flushes rewrite rules so /post/* routes register cleanly
 * once Session A2 introduces them.
 *
 * @since 0.1.0
 */
function outpost_activate(): void {
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'outpost_activate' );

/**
 * Deactivation hook. Flushes rewrite rules so /post/* routes are removed cleanly.
 *
 * @since 0.1.0
 */
function outpost_deactivate(): void {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'outpost_deactivate' );
