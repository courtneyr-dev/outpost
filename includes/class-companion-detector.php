<?php
/**
 * Companion plugin detector.
 *
 * Single source of truth for whether the IndieAuth -> Micropub required chain
 * is satisfied and which optional companion surfaces (Post Kinds, Post Formats,
 * Link Extension for XFN, Syndication Links, Yoast SEO, ActivityPub) are
 * available at runtime.
 *
 * Static-only on purpose: this class wraps WordPress globals (is_plugin_active,
 * get_plugins) — there is no per-instance state worth modelling, and every
 * caller (admin notices, REST 503 reasons, the PWA install-prompt route) wants
 * a single function-call answer.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Companion_Detector {

	/**
	 * Detect the registration state of any companion plugin by its main file path.
	 *
	 * Returns one of three values:
	 *  - 'active'   — `is_plugin_active()` reports true
	 *  - 'inactive' — file is on disk under wp-content/plugins but not activated
	 *  - 'absent'   — file is not on disk
	 *
	 * 'active' is WordPress's registration state, not "fully functional." Some
	 * plugins (notably Micropub) self-disable when their own dependencies are
	 * missing; that case looks 'active' to WP but is functionally inactive.
	 * Callers chain dependency checks in upstream-first order via
	 * {@see self::first_unsatisfied()}.
	 *
	 * @since 0.1.0
	 *
	 * @param string $plugin_file Plugin main file path relative to wp-content/plugins/.
	 * @return string One of 'active', 'inactive', or 'absent'.
	 */
	public static function status( string $plugin_file ): string {
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

		// Companions installed from GitHub rather than the plugin directory land
		// in whatever directory the user cloned or unzipped into, so the exact
		// path above can miss a plugin that is present and active. Fall back to
		// matching on the main filename, which is stable across directory names.
		$resolved = self::resolve_by_basename( $plugin_file, $installed );
		if ( null !== $resolved ) {
			return is_plugin_active( $resolved ) ? 'active' : 'inactive';
		}

		return 'absent';
	}

	/**
	 * Find an installed plugin whose main filename matches, ignoring its directory.
	 *
	 * Only matches on the filename, never on the directory, so two plugins that
	 * happen to share a directory name cannot be confused for each other. Returns
	 * the first match; a duplicate main filename across two directories is not a
	 * configuration Outpost can meaningfully disambiguate.
	 *
	 * @since 0.1.1
	 *
	 * @param string               $plugin_file Expected `dir/file.php` path.
	 * @param array<string, array<string, mixed>> $installed   Result of get_plugins(), keyed by plugin file.
	 * @return string|null Matching plugin file path, or null when nothing matches.
	 */
	private static function resolve_by_basename( string $plugin_file, array $installed ): ?string {
		$wanted = basename( $plugin_file );

		foreach ( array_keys( $installed ) as $candidate ) {
			if ( basename( $candidate ) === $wanted ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Required dependency chain in upstream-first order.
	 *
	 * Single source of truth for the IndieAuth -> Micropub ordering. The Micropub
	 * plugin hard-requires IndieAuth at its own preflight, so IndieAuth is
	 * surfaced first. Every consumer of "is the gate satisfied?" iterates this
	 * array instead of hard-coding the order.
	 *
	 * Optional companions are intentionally excluded — see {@see self::optional_companions()}.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Plugin file paths in upstream-first order.
	 */
	public static function dependency_chain(): array {
		return array(
			OUTPOST_INDIEAUTH_PLUGIN_FILE,
			OUTPOST_MICROPUB_PLUGIN_FILE,
		);
	}

	/**
	 * Optional companions whose presence enables additional feature surfaces.
	 *
	 * Their absence never blocks the composer or REST API — that is the
	 * contract that lets the PWA install-prompt page (A2) and first-run wizard
	 * (H1) suggest installs without scaring users into thinking the plugin is
	 * broken.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Plugin file paths, no enforced ordering.
	 */
	public static function optional_companions(): array {
		return array(
			OUTPOST_POST_KINDS_PLUGIN_FILE,
			OUTPOST_POST_FORMATS_PLUGIN_FILE,
			OUTPOST_LINK_EXTENSION_XFN_PLUGIN_FILE,
			OUTPOST_SYNDICATION_LINKS_PLUGIN_FILE,
			OUTPOST_YOAST_PLUGIN_FILE,
			OUTPOST_ACTIVITYPUB_PLUGIN_FILE,
			OUTPOST_ACCESSIBILITY_CHECKER_PLUGIN_FILE,
		);
	}

	/**
	 * First entry in the required chain that is not 'active', or null if all are.
	 *
	 * Short-circuits on the first miss so admin notices, REST 503 reasons, and
	 * the install-prompt page all surface the most-upstream blocker first.
	 * Optional companions are never considered here.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null Plugin file path of the first unsatisfied entry, or null when satisfied.
	 */
	public static function first_unsatisfied(): ?string {
		foreach ( self::dependency_chain() as $plugin_file ) {
			if ( 'active' !== self::status( $plugin_file ) ) {
				return $plugin_file;
			}
		}
		return null;
	}

	// --- Named wrappers ------------------------------------------------------
	//
	// Thin convenience methods so callers can write `is_indieauth_active()`
	// instead of passing a magic file path. Each delegates to status() with the
	// matching constant.

	public static function is_indieauth_active(): string {
		return self::status( OUTPOST_INDIEAUTH_PLUGIN_FILE );
	}

	public static function is_micropub_active(): string {
		return self::status( OUTPOST_MICROPUB_PLUGIN_FILE );
	}

	public static function is_post_kinds_active(): string {
		return self::status( OUTPOST_POST_KINDS_PLUGIN_FILE );
	}

	public static function is_post_formats_active(): string {
		return self::status( OUTPOST_POST_FORMATS_PLUGIN_FILE );
	}

	public static function is_xfn_active(): string {
		return self::status( OUTPOST_LINK_EXTENSION_XFN_PLUGIN_FILE );
	}

	public static function is_syndication_links_active(): string {
		return self::status( OUTPOST_SYNDICATION_LINKS_PLUGIN_FILE );
	}

	public static function is_yoast_active(): string {
		return self::status( OUTPOST_YOAST_PLUGIN_FILE );
	}

	public static function is_activitypub_active(): string {
		return self::status( OUTPOST_ACTIVITYPUB_PLUGIN_FILE );
	}

	public static function is_accessibility_checker_active(): string {
		return self::status( OUTPOST_ACCESSIBILITY_CHECKER_PLUGIN_FILE );
	}
}
