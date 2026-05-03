<?php
/**
 * Plugin Name:       Outpost
 * Plugin URI:        https://github.com/courtneyr-dev/outpost
 * Description:       Mobile-first Progressive Web App composer for IndieWeb POSSE workflows. Post notes, replies, likes, photos, and life-tracking entries from your phone, with one-tap syndication. Requires the Micropub plugin.
 * Version:           0.1.63
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
define( 'OUTPOST_VERSION', '0.1.63' );
define( 'OUTPOST_PLUGIN_FILE', __FILE__ );
define( 'OUTPOST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OUTPOST_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OUTPOST_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Requirements.
define( 'OUTPOST_MIN_WP', '6.5' );
define( 'OUTPOST_MIN_PHP', '8.2' );

// Companion plugin file paths (for is_plugin_active checks).
// Required chain (upstream-first; see Outpost_Companion_Detector::dependency_chain).
define( 'OUTPOST_INDIEAUTH_PLUGIN_FILE', 'indieauth/indieauth.php' );
define( 'OUTPOST_MICROPUB_PLUGIN_FILE', 'micropub/micropub.php' );

// Optional companions (enable feature surfaces; never block the gate).
define( 'OUTPOST_POST_KINDS_PLUGIN_FILE', 'post-kinds-for-indieweb/post-kinds-for-indieweb.php' );
define( 'OUTPOST_POST_FORMATS_PLUGIN_FILE', 'post-formats-for-block-themes/post-formats-for-block-themes.php' );
define( 'OUTPOST_LINK_EXTENSION_XFN_PLUGIN_FILE', 'link-extension-for-xfn/link-extension-for-xfn.php' );
define( 'OUTPOST_SYNDICATION_LINKS_PLUGIN_FILE', 'syndication-links/syndication-links.php' );
// Yoast: slug is `wordpress-seo` but the main file is `wp-seo.php`.
define( 'OUTPOST_YOAST_PLUGIN_FILE', 'wordpress-seo/wp-seo.php' );
define( 'OUTPOST_ACTIVITYPUB_PLUGIN_FILE', 'activitypub/activitypub.php' );
define( 'OUTPOST_ACCESSIBILITY_CHECKER_PLUGIN_FILE', 'accessibility-checker/accessibility-checker.php' );

// Load the detector class and the companion-adapter base class up front so the
// rest of this bootstrap file can stay procedural shims that delegate to them.
require_once OUTPOST_PLUGIN_DIR . 'includes/class-companion-detector.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-companion-base.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-post-kinds-adapter.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-post-formats-adapter.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-xfn-adapter.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-syndication-links-adapter.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-yoast-adapter.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-activitypub-adapter.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-accessibility-checker-adapter.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-companion-registry.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-route-handler.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-pwa-assets.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-pwa-shell.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-preview-endpoint.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-composer-config-endpoint.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-geocode-endpoint.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-micropub-bridges.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-settings.php';

// Register the /wp-json/outpost/v1/preview REST route (Phase B2).
Outpost_Preview_Endpoint::register();
// Register the /wp-json/outpost/v1/composer-config REST route (Phase C5).
Outpost_Composer_Config_Endpoint::register();
// Register the /wp-json/outpost/v1/geocode REST route (Checkin coordinates).
Outpost_Geocode_Endpoint::register();
// Hook the Micropub bridges (Yoast focus keyphrase, post format, XFN) (Phase C5).
Outpost_Micropub_Bridges::register();
// Register the wp-admin Outpost menu + bookmarklet generator page (Phase E1).
Outpost_Admin_Page::register();
// Register Settings API options (Phase H).
Outpost_Settings::register();

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
 * Thin shim around {@see Outpost_Companion_Detector::status()}. Kept as a
 * procedural wrapper because earlier sessions and external callers reference
 * it; new code should call the detector class directly.
 *
 * @since 0.1.0
 *
 * @param string $plugin_file Plugin main file path relative to wp-content/plugins/.
 * @return string One of 'active', 'inactive', or 'absent'.
 */
function outpost_companion_plugin_status( string $plugin_file ): string {
	return Outpost_Companion_Detector::status( $plugin_file );
}

/**
 * Detect the state of the IndieAuth companion plugin.
 *
 * @since 0.1.0
 *
 * @return string One of 'active', 'inactive', or 'absent'.
 */
function outpost_indieauth_status(): string {
	return Outpost_Companion_Detector::is_indieauth_active();
}

/**
 * Detect the state of the Micropub companion plugin.
 *
 * @since 0.1.0
 *
 * @return string One of 'active', 'inactive', or 'absent'.
 */
function outpost_micropub_status(): string {
	return Outpost_Companion_Detector::is_micropub_active();
}

/**
 * Whether the plugin's full feature surface (PWA composer, REST endpoints) is available.
 *
 * Both required companions must be active. The Micropub plugin hard-requires
 * IndieAuth at its own preflight, so a Micropub-active-but-IndieAuth-missing
 * environment is functionally broken from Outpost's perspective. The dependency
 * order lives in {@see Outpost_Companion_Detector::dependency_chain()}.
 *
 * @since 0.1.0
 *
 * @return bool True only when host requirements are met and the dependency chain is fully satisfied.
 */
function outpost_is_ready(): bool {
	return outpost_meets_requirements()
		&& null === Outpost_Companion_Detector::first_unsatisfied();
}

/**
 * Resolve a dependency-chain plugin file to its presentation pair (label + wp.org slug).
 *
 * Returns `array( 'label' => string, 'slug' => string )` for known dependency
 * files and `null` for unknown ones. The map is filterable through
 * `outpost_dependency_presentation` so future chain extensions and third-party
 * plugins can register their own labels/slugs without editing this file.
 *
 * Two consumers share this helper today: the admin notice path
 * (`outpost_render_admin_notices()`) and the PWA install-prompt page rendered
 * by `Outpost_PWA_Shell`. Keeping the source-of-truth in one place stops the
 * two surfaces from drifting.
 *
 * @since 0.1.0
 *
 * @param string $plugin_file Plugin main file path relative to wp-content/plugins/.
 * @return array{label:string,slug:string}|null Presentation pair or null when the
 *                                              file isn't a known dependency.
 */
function outpost_dependency_presentation( string $plugin_file ): ?array {
	$map = array(
		OUTPOST_INDIEAUTH_PLUGIN_FILE => array(
			'label' => 'IndieAuth',
			'slug'  => 'indieauth',
		),
		OUTPOST_MICROPUB_PLUGIN_FILE  => array(
			'label' => 'Micropub',
			'slug'  => 'micropub',
		),
	);

	/**
	 * Filter the dependency presentation map.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array{label:string,slug:string}> $map Map of plugin file
	 *        path to presentation pair. Keys are OUTPOST_*_PLUGIN_FILE values; values
	 *        are arrays with `label` (untranslated brand name) and `slug` (wp.org
	 *        plugin slug for the install link).
	 */
	$map = apply_filters( 'outpost_dependency_presentation', $map );

	return $map[ $plugin_file ] ?? null;
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
		$message = sprintf(
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
		$message = sprintf(
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
 * is missing or inactive. Notices surface in upstream-first order driven by
 * {@see Outpost_Companion_Detector::first_unsatisfied()}; host requirements
 * are checked before the chain so an unsupported PHP/WP version doesn't get
 * masked by a missing companion.
 *
 * Optional companions (Post Kinds, Yoast, ActivityPub, etc.) never produce a
 * notice here — their absence reduces feature surface without breaking the
 * gate. Admins without `install_plugins` capability see nothing.
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

	$blocker = Outpost_Companion_Detector::first_unsatisfied();
	if ( null === $blocker ) {
		return;
	}

	$presentation = outpost_dependency_presentation( $blocker );
	if ( null === $presentation ) {
		// If dependency_chain() ever extends without a matching presentation entry
		// the notice would silently disappear. Surface it via Query Monitor's
		// doing_it_wrong panel instead so the gap can't hide. esc_html() is
		// defensive — $blocker comes from dependency_chain()'s known-safe set,
		// but the message reaches the doing_it_wrong panel which renders HTML.
		_doing_it_wrong(
			__FUNCTION__,
			esc_html( 'Dependency chain entry has no presentation mapping: ' . $blocker ),
			'0.1.0'
		);
		return;
	}

	outpost_render_dependency_notice(
		$presentation['label'],
		$blocker,
		$presentation['slug'],
		Outpost_Companion_Detector::status( $blocker )
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
 * Wire the route handler on each page load so /post/* requests get routed.
 *
 * Hooks register on `init` (rewrite rules + query var) and `template_redirect`
 * (dispatch). See {@see Outpost_Route_Handler::init()} for details.
 *
 * @since 0.1.0
 */
Outpost_Route_Handler::init();

/**
 * One-shot rewrite-rule flush on version change.
 *
 * The activation hook only runs when an admin clicks "Activate" — not when a
 * deploy/update replaces the plugin file in place. Without this guard the
 * route table from the previous version stays cached in `rewrite_rules`
 * and `/post/*` URLs fall through to canonical-redirect into unrelated
 * permalinks.
 *
 * Compares the running OUTPOST_VERSION against the value stashed in
 * `outpost_rewrite_version`. On mismatch, re-registers and flushes once,
 * then writes the new version. Idempotent — registers/flushes do nothing on
 * subsequent requests.
 *
 * Hooked at priority 11 so it runs immediately after Outpost_Route_Handler's
 * own register_rewrite_rules call (default priority 10).
 *
 * @since 0.1.0
 */
function outpost_maybe_flush_rewrite_rules(): void {
	if ( get_option( 'outpost_rewrite_version' ) === OUTPOST_VERSION ) {
		return;
	}
	flush_rewrite_rules();
	update_option( 'outpost_rewrite_version', OUTPOST_VERSION );
}
add_action( 'init', 'outpost_maybe_flush_rewrite_rules', 11 );

/**
 * Activation hook. Registers the rewrite rules immediately, then flushes so
 * the freshly-registered rules land in the rewrite rule cache without
 * requiring a manual Settings → Permalinks visit.
 *
 * @since 0.1.0
 */
function outpost_activate(): void {
	Outpost_Route_Handler::register_rewrite_rules();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'outpost_activate' );

/**
 * Deactivation hook. Flushes rewrite rules so /post/* rules are dropped from
 * the cache. The rules themselves don't need explicit removal — they're
 * regenerated on every `init` and naturally disappear when this plugin is
 * inactive (the `init` hook stops firing).
 *
 * @since 0.1.0
 */
function outpost_deactivate(): void {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'outpost_deactivate' );
