<?php
/**
 * Outpost_Uninstaller — deletes exactly the persistent state Outpost created,
 * and nothing WordPress or another plugin owns.
 *
 * Runs from uninstall.php (the plugin is NOT loaded then, so this uses only
 * core WordPress APIs and never references Outpost classes or constants). It is
 * a class rather than inline script so the behavior is testable: the census it
 * enacts must match the code that ships, and both the deletions and the
 * preservations are asserted in `tests/integration/UninstallTest.php`.
 *
 * The census is derived from the actual option / user-meta / post-meta /
 * transient / scheduled-hook writers in the plugin (see the pre-1.0.4
 * remediation). Deliberately NOT deleted: `_wp_attachment_image_alt`,
 * `_yoast_wpseo_focuskw`, `_thumbnail_id`, and any `category` terms — Outpost
 * writes those but does not own them.
 *
 * @package Outpost
 * @since   1.0.4
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Uninstaller {

	/** OAuth/credential providers that write `outpost_creds_{provider}`. */
	private const CREDENTIAL_PROVIDERS = array( 'notion', 'oura', 'polar', 'ravelry', 'ridewithgps', 'whoop', 'telegraph' );

	/** Exact option names Outpost owns. */
	private const OPTIONS = array(
		'outpost_rewrite_version',
		'outpost_encryption_key',
		'outpost_settings',
		'outpost_settings_api_keys',
		'outpost_bridgy_silos_enabled',
		'outpost_telegraph_short_name',
		'outpost_telegraph_author_name',
		'outpost_telegraph_author_url',
	);

	/** Exact user-meta keys Outpost owns (deleted for every user). */
	private const USER_META_KEYS = array(
		'outpost_appearance_overrides',
		'outpost_appearance_mode',
		'outpost_ios_shortcut_token',
		'outpost_ios_shortcut_first_seen',
	);

	/** User-meta key PREFIXES Outpost owns (version/user-id suffixed). */
	private const USER_META_PREFIXES = array(
		'outpost_dismissed_encryption_key_notice_',
		'outpost_telegraph_access_token_user_',
		'outpost_creds_',
	);

	/** Post-meta keys Outpost owns (deleted across all posts). */
	private const POST_META_KEYS = array(
		'_outpost_place_name',
		'_outpost_xfn',
		'_outpost_posse_targets',
		'_outpost_syndication_urls',
		'_outpost_posse_failures',
		'_outpost_posse_in_flight',
		'_outpost_skip_telegraph',
		'outpost_syndication_links',
		'outpost_manual_share_log',
		'outpost_bridgy_publish_log',
		'outpost_telegraph_post_url',
		'outpost_telegraph_page_path',
		'outpost_telegraph_author_name_override',
		'outpost_telegraph_author_url_override',
	);

	/** Scheduled action hooks Outpost registers. */
	private const CRON_HOOKS = array( 'outpost_posse_dispatch' );

	/**
	 * Run the full uninstall — every site on multisite, otherwise the one site.
	 */
	public static function run(): void {
		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields'        => 'ids',
					'number'        => 0,
					'no_found_rows' => true,
				)
			);
			foreach ( (array) $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::clean_current_site();
				restore_current_blog();
			}
			return;
		}
		self::clean_current_site();
	}

	/**
	 * Delete Outpost's data on the current site.
	 */
	public static function clean_current_site(): void {
		global $wpdb;

		// Options — exact names, plus the per-provider credential options
		// (dormant today, but the site-scope filter could have written them).
		$options = self::OPTIONS;
		foreach ( self::CREDENTIAL_PROVIDERS as $provider ) {
			$options[] = 'outpost_creds_' . $provider;
		}
		foreach ( array_unique( $options ) as $name ) {
			delete_option( $name );
		}

		// User meta — exact keys, deleted for every user.
		foreach ( self::USER_META_KEYS as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
		// User meta — prefixed keys (version/user-id/provider suffixed).
		foreach ( self::USER_META_PREFIXES as $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup; no meta API covers a key prefix.
			$keys = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
			foreach ( (array) $keys as $key ) {
				delete_metadata( 'user', 0, (string) $key, '', true );
			}
		}

		// Post meta — exact keys Outpost owns, deleted across all posts. Never
		// touches core/Yoast keys (_wp_attachment_image_alt, _yoast_wpseo_focuskw,
		// _thumbnail_id) — they are not in the list.
		foreach ( self::POST_META_KEYS as $key ) {
			delete_metadata( 'post', 0, $key, '', true );
		}

		// Transients — every `outpost_*` transient and its timeout twin. WP has
		// no bulk-by-prefix transient API, so target the options table directly.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_outpost_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_outpost_' ) . '%'
			)
		);
		// Site transients on multisite (stored in options here; sitemeta only on
		// the network — Outpost writes none there, but clear the local twins).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_site_transient_outpost_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_outpost_' ) . '%'
			)
		);

		// Scheduled events. wp_unschedule_hook() clears EVERY event for the hook
		// regardless of its args — POSSE retries are scheduled with per-post
		// args, which wp_clear_scheduled_hook( $hook ) (no args) would miss.
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}
}
