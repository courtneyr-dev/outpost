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
define( 'OUTPOST_INDIEAUTH_PLUGIN_FILE', 'indieauth/indieauth.php' );

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
 * Detect the registration state of any companion plugin by its main file path.
 *
 * Returns one of three values:
 * - 'active'   — `is_plugin_active()` reports true
 * - 'inactive' — file is on disk under wp-content/plugins but not activated
 * - 'absent'   — file is not on disk
 *
 * Note: 'active' here is WordPress's registration state, not "fully functional."
 * Some plugins (notably Micropub) self-disable when their own dependencies are
 * missing; that case looks 'active' to WP but is functionally inactive. The
 * caller is responsible for chaining dependency checks in upstream-first order.
 *
 * @since 0.1.0
 *
 * @param string $plugin_file Plugin main file path relative to wp-content/plugins/.
 * @return string One of 'active', 'inactive', or 'absent'.
 */
function outpost_companion_plugin_status( string $plugin_file ): string {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( is_plugin_active( $plugin_file ) ) {
		return 'active';
	}

	$installed = get_plugins();
	if ( isset( $installed[ $plugin_file ] ) ) {
		return 'inactive';
	}

	return 'absent';
}

/**
 * Detect the state of the IndieAuth companion plugin.
 *
 * IndieAuth is a hard dependency of the Micropub plugin (David Shanske), so
 * Outpost surfaces IndieAuth status as the most-upstream notice — without
 * IndieAuth, Micropub itself refuses to register its endpoints.
 *
 * @since 0.1.0
 *
 * @return string One of 'active', 'inactive', or 'absent'.
 */
function outpost_indieauth_status(): string {
	return outpost_companion_plugin_status( OUTPOST_INDIEAUTH_PLUGIN_FILE );
}

/**
 * Detect the state of the Micropub companion plugin.
 *
 * @since 0.1.0
 *
 * @return string One of 'active', 'inactive', or 'absent'.
 */
function outpost_micropub_status(): string {
	return outpost_companion_plugin_status( OUTPOST_MICROPUB_PLUGIN_FILE );
}

/**
 * Whether the plugin's full feature surface (PWA composer, REST endpoints) is available.
 *
 * Both IndieAuth and Micropub must be active. The Micropub plugin hard-requires
 * IndieAuth at its own preflight, so a Micropub-active-but-IndieAuth-missing
 * environment is functionally broken from Outpost's perspective.
 *
 * Used by the route handler and REST controller in later sessions to decide whether
 * to render the composer or a friendly install-prompt page (PWA) / 503 response (REST).
 *
 * @since 0.1.0
 *
 * @return bool True only when both IndieAuth and Micropub are active.
 */
function outpost_is_ready(): bool {
	return outpost_meets_requirements()
		&& 'active' === outpost_indieauth_status()
		&& 'active' === outpost_micropub_status();
}

/**
 * Render a single dependency notice with an action button.
 *
 * @since 0.1.0
 *
 * @param string $plugin_label Human-readable plugin name (untranslated; brand name).
 * @param string $plugin_file  Plugin main file path relative to wp-content/plugins/.
 * @param string $wporg_slug   WordPress.org plugin slug for the install link.
 * @param string $status       'inactive' or 'absent' (caller filters out 'active').
 */
function outpost_render_dependency_notice( string $plugin_label, string $plugin_file, string $wporg_slug, string $status ): void {
	if ( 'inactive' === $status ) {
		$action_url   = wp_nonce_url(
			self_admin_url( 'plugins.php?action=activate&plugin=' . $plugin_file ),
			'activate-plugin_' . $plugin_file
		);
		$action_label = sprintf(
			/* translators: %s: plugin name. */
			__( 'Activate %s', 'outpost' ),
			$plugin_label
		);
		$message      = sprintf(
			/* translators: %s: plugin name. */
			__( 'Outpost needs the %s plugin to be activated before the composer can run.', 'outpost' ),
			$plugin_label
		);
	} else {
		$action_url   = wp_nonce_url(
			self_admin_url( 'update.php?action=install-plugin&plugin=' . $wporg_slug ),
			'install-plugin_' . $wporg_slug
		);
		$action_label = sprintf(
			/* translators: %s: plugin name. */
			__( 'Install %s', 'outpost' ),
			$plugin_label
		);
		$message      = sprintf(
			/* translators: %s: plugin name. */
			__( 'Outpost requires the %s plugin. Install it from WordPress.org to continue.', 'outpost' ),
			$plugin_label
		);
	}

	printf(
		'<div class="notice notice-warning"><p>%1$s &nbsp; <a class="button button-primary" href="%2$s">%3$s</a></p></div>',
		esc_html( $message ),
		esc_url( $action_url ),
		esc_html( $action_label )
	);
}

/**
 * Render the dependency-status admin notice on every admin screen.
 *
 * Hybrid gate: plugin loads regardless, but warns when a required dependency
 * is missing or inactive. Notices surface in upstream-first order:
 *
 *   1. Host requirements (WP and PHP versions)
 *   2. IndieAuth (required by Micropub's own preflight)
 *   3. Micropub (required by Outpost's PWA and REST surfaces)
 *
 * The chain short-circuits — once a notice is shown, downstream checks
 * are skipped because they can't be satisfied without the upstream one.
 * Admins without `install_plugins` capability see nothing.
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

	$indieauth = outpost_indieauth_status();
	if ( 'active' !== $indieauth ) {
		outpost_render_dependency_notice( 'IndieAuth', OUTPOST_INDIEAUTH_PLUGIN_FILE, 'indieauth', $indieauth );
		return;
	}

	$micropub = outpost_micropub_status();
	if ( 'active' !== $micropub ) {
		outpost_render_dependency_notice( 'Micropub', OUTPOST_MICROPUB_PLUGIN_FILE, 'micropub', $micropub );
		return;
	}
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
