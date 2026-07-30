<?php
/**
 * Outpost_Sidebar_Assets (G3.5c).
 *
 * Enqueues the Gutenberg block-editor sidebar bundle (built by
 * @wordpress/scripts) on the post-edit screen. Reads the asset.php
 * manifest produced by `npm run build:wp` to resolve script
 * dependencies and version. Skips enqueue with a graceful warning when
 * the build artifact is missing (dev environment without a local build).
 *
 * @package Outpost
 * @since   0.1.79
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Sidebar_Assets {

	public const HANDLE = 'outpost-sidebar';

	/**
	 * Path to the build/index.js bundle relative to the plugin root.
	 */
	private const BUILD_JS = 'build/index.js';

	/**
	 * Path to the build/index.asset.php manifest relative to the plugin root.
	 */
	private const BUILD_ASSET_PHP = 'build/index.asset.php';

	/**
	 * Path to the build/index.css bundle relative to the plugin root.
	 * @wordpress/scripts emits this when the entry imports SCSS/CSS.
	 */
	private const BUILD_CSS = 'build/index.css';

	/**
	 * Hook the enqueue callback to the editor-assets action.
	 *
	 * @since 0.1.79
	 */
	public static function register(): void {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue the sidebar bundle. Reads dependencies and version from the
	 * build manifest produced by @wordpress/scripts. If the manifest is
	 * missing, log a warning and skip — production sites without a build
	 * shouldn't WSOD; dev environments get a noticeable failure mode.
	 *
	 * @since 0.1.79
	 */
	public static function enqueue(): void {
		$plugin_dir = OUTPOST_PLUGIN_DIR;
		$plugin_url = OUTPOST_PLUGIN_URL;

		$asset_path = $plugin_dir . self::BUILD_ASSET_PHP;
		$js_path    = $plugin_dir . self::BUILD_JS;

		if ( ! is_readable( $asset_path ) || ! is_readable( $js_path ) ) {
			self::warn_missing_build( $asset_path, $js_path );
			return;
		}

		$asset = require $asset_path;
		if ( ! is_array( $asset ) || ! isset( $asset['dependencies'], $asset['version'] ) ) {
			self::warn_missing_build( $asset_path, $js_path );
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			$plugin_url . self::BUILD_JS,
			(array) $asset['dependencies'],
			(string) $asset['version'],
			true
		);

		wp_set_script_translations( self::HANDLE, 'outpost-mobile-publishing' );

		$css_path = $plugin_dir . self::BUILD_CSS;
		if ( is_readable( $css_path ) ) {
			wp_enqueue_style(
				self::HANDLE,
				$plugin_url . self::BUILD_CSS,
				array(),
				(string) $asset['version']
			);
		}
	}

	/**
	 * Log a development-friendly warning when the build artifact is missing.
	 *
	 * @since 0.1.79
	 */
	private static function warn_missing_build( string $asset_path, string $js_path ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'Outpost sidebar build artifacts missing. Run `npm install && npm run build:wp` from the plugin root. Looked for: %s, %s',
				$asset_path,
				$js_path
			)
		);
	}
}
