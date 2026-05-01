<?php
/**
 * PWA build-asset resolver.
 *
 * Reads the Vite-emitted manifest at `build/pwa/.vite/manifest.json` so the
 * PHP shell can enqueue cache-busted asset URLs without hard-coding hashes.
 * Caches the parsed manifest per request via a static property — `manifest()`
 * touches disk at most once per render path.
 *
 * Fails gracefully when the manifest is missing/unreadable/invalid: every
 * resolver returns `null` (or an empty array, for the CSS list). The shell
 * uses that to skip the script/link tags entirely, which lets the install-
 * prompt page work in dev environments where `npm run build` hasn't run yet.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_PWA_Assets {

	private const MANIFEST_REL_PATH = 'build/pwa/.vite/manifest.json';
	private const BUILD_REL_DIR     = 'build/pwa/';
	private const ENTRY_KEY         = 'pwa/src/index.tsx';

	/**
	 * @var array<string, mixed>|null
	 */
	private static ?array $cached = null;

	/**
	 * Distinguishes "haven't tried to load yet" from "loaded and got null".
	 *
	 * @var bool
	 */
	private static bool $cache_filled = false;

	/**
	 * @var string|null Manifest path override for tests.
	 */
	private static ?string $manifest_path_override = null;

	/**
	 * @var string|null Build URL prefix override for tests.
	 */
	private static ?string $build_url_override = null;

	/**
	 * Absolute path to the Vite manifest on disk.
	 *
	 * @since 0.1.2
	 */
	public static function manifest_path(): string {
		if ( null !== self::$manifest_path_override ) {
			return self::$manifest_path_override;
		}
		return OUTPOST_PLUGIN_DIR . self::MANIFEST_REL_PATH;
	}

	/**
	 * URL prefix that asset filenames are joined onto. Defaults to the plugin
	 * URL + build/pwa/; tests substitute a fake prefix.
	 */
	private static function build_url_prefix(): string {
		if ( null !== self::$build_url_override ) {
			return self::$build_url_override;
		}
		return OUTPOST_PLUGIN_URL . self::BUILD_REL_DIR;
	}

	/**
	 * Parsed manifest, or null when missing/unreadable/invalid JSON.
	 *
	 * Cached for the lifetime of the request — repeated calls don't touch disk.
	 *
	 * @since 0.1.2
	 *
	 * @return array<string, mixed>|null
	 */
	public static function manifest(): ?array {
		if ( self::$cache_filled ) {
			return self::$cached;
		}

		self::$cache_filled = true;

		$path = self::manifest_path();
		if ( ! is_readable( $path ) ) {
			self::$cached = null;
			return null;
		}

		// WPCS suggests wp_remote_get(), which is for HTTP. This is a local
		// filesystem read of the build artefact deployed alongside the plugin.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			self::$cached = null;
			return null;
		}

		$decoded = json_decode( $contents, true );
		if ( ! is_array( $decoded ) ) {
			self::$cached = null;
			return null;
		}

		self::$cached = $decoded;
		return self::$cached;
	}

	/**
	 * Public URL of the entry-script chunk, or null when not resolvable.
	 *
	 * @since 0.1.2
	 */
	public static function entry_url(): ?string {
		$entry = self::resolve_entry();
		if ( null === $entry ) {
			return null;
		}

		$file = $entry['file'] ?? null;
		if ( ! is_string( $file ) || '' === $file ) {
			return null;
		}

		return self::build_url_prefix() . $file;
	}

	/**
	 * Public URLs of the entry's associated CSS chunks.
	 *
	 * Vite lists CSS imports inline on the entry's manifest record under `css`.
	 * Returns an empty array when the entry has no CSS (e.g. while the bundle
	 * imports no styles).
	 *
	 * @since 0.1.2
	 *
	 * @return string[]
	 */
	public static function entry_css_urls(): array {
		$entry = self::resolve_entry();
		if ( null === $entry ) {
			return array();
		}

		$css = $entry['css'] ?? null;
		if ( ! is_array( $css ) ) {
			return array();
		}

		$urls = array();
		foreach ( $css as $css_file ) {
			if ( is_string( $css_file ) && '' !== $css_file ) {
				$urls[] = self::build_url_prefix() . $css_file;
			}
		}
		return $urls;
	}

	/**
	 * Reset the per-request cache. For tests only.
	 *
	 * @internal
	 */
	public static function reset_cache_for_tests(): void {
		self::$cached       = null;
		self::$cache_filled = false;
	}

	/**
	 * Override the manifest path and build-URL prefix. For tests only.
	 *
	 * @internal
	 *
	 * @param string|null $manifest_path     Absolute filesystem path to a manifest.json, or null to clear.
	 * @param string|null $build_url_prefix  URL prefix joined to asset filenames, or null to clear.
	 */
	public static function override_paths_for_tests( ?string $manifest_path, ?string $build_url_prefix ): void {
		self::$manifest_path_override = $manifest_path;
		self::$build_url_override     = $build_url_prefix;
		self::reset_cache_for_tests();
	}

	/**
	 * Look up the entry record, or null when absent.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function resolve_entry(): ?array {
		$manifest = self::manifest();
		if ( null === $manifest ) {
			return null;
		}

		$entry = $manifest[ self::ENTRY_KEY ] ?? null;
		if ( ! is_array( $entry ) ) {
			return null;
		}

		return $entry;
	}
}
