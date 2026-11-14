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
		'outpost_telegraph_enabled',
		'outpost_telegraph_short_name',
		'outpost_telegraph_author_name',
		'outpost_telegraph_author_url',
		'outpost_appearance_schema',
		'outpost_ios_shortcut_legacy_purged',
	);

	/**
	 * `app_id` of the iOS Shortcut application passwords Outpost creates
	 * (Outpost_IOS_Shortcut_App_Password::APP_ID; repeated here because the
	 * plugin isn't loaded during uninstall). Only passwords with this id are
	 * deleted; passwords the person made for anything else stay.
	 */
	private const SHORTCUT_APP_ID = 'eb290b80-79b0-4104-b470-4d6188280ccc';

	/** Exact user-meta keys Outpost owns (deleted for every user). */
	private const USER_META_KEYS = array(
		'outpost_appearance_overrides',
		'outpost_appearance_mode',
		'outpost_appearance_source',
		'outpost_start_screen',
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
	public static function run( string $plugin_basename = '' ): void {
		// Application passwords live in network-wide user meta, so they are
		// removed once, not per site. If one survives, stop before deleting
		// anything else. Uninstall runs after deactivation and without
		// loading Outpost, so keeping the files alone can't keep the password
		// scoped: turn Outpost back on, so the next request loads its route
		// rule, and then stop (T10-S4-27, final delta review D-01).
		if ( ! self::delete_shortcut_application_passwords() ) {
			self::stop_uninstall( self::reactivate( $plugin_basename ) );
			return;
		}

		if ( is_multisite() ) {
			delete_site_option( 'outpost_ios_shortcut_unscoped_swept' );
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
	 * Delete the iOS Shortcut application passwords Outpost created, for every
	 * user who has one, and leave every other application password alone.
	 *
	 * Returns whether none is left (T10-S4-27): after deleting, it reads every
	 * user's passwords again rather than trusting the delete calls, because a
	 * password that outlives Outpost's route rule works everywhere.
	 */
	public static function delete_shortcut_application_passwords(): bool {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return true;
		}
		foreach ( self::users_with_application_passwords() as $user_id ) {
			foreach ( WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
				if ( self::SHORTCUT_APP_ID === ( $item['app_id'] ?? '' ) && isset( $item['uuid'] ) ) {
					WP_Application_Passwords::delete_application_password( $user_id, (string) $item['uuid'] );
				}
			}
		}
		foreach ( self::users_with_application_passwords() as $user_id ) {
			foreach ( WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
				if ( self::SHORTCUT_APP_ID === ( $item['app_id'] ?? '' ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Stop deactivation when a Shortcut application password survived
	 * cleanup. WordPress saves the plugin as inactive only after the
	 * deactivation hooks, so Outpost and its route rule stay loaded.
	 */
	public static function stop_deactivation(): void {
		wp_die(
			esc_html__( 'Deactivation stopped: Outpost could not delete the iOS Shortcut application password, so Outpost stays active and that password stays limited to the Shortcut. Try again.', 'outpost-mobile-publishing' ),
			esc_html__( 'Outpost is still active', 'outpost-mobile-publishing' ),
			array(
				'response'  => 500,
				'back_link' => true,
			)
		);
	}

	/**
	 * Stop uninstall when a Shortcut application password survived cleanup.
	 *
	 * @param bool $reactivated Whether Outpost was turned back on.
	 */
	private static function stop_uninstall( bool $reactivated ): void {
		$message = $reactivated
			? __( 'Uninstall stopped: Outpost could not delete the iOS Shortcut application password, so it turned itself back on to keep that password limited to the Shortcut. Try again, or revoke the password under Users → Profile → Application Passwords.', 'outpost-mobile-publishing' )
			: __( 'Uninstall stopped: Outpost could not delete the iOS Shortcut application password and could not turn itself back on, so that password is not limited to the Shortcut. Revoke it under Users → Profile → Application Passwords, then try again.', 'outpost-mobile-publishing' );
		wp_die(
			esc_html( $message ),
			esc_html__( 'Outpost uninstall stopped', 'outpost-mobile-publishing' ),
			array(
				'response'  => 500,
				'back_link' => true,
			)
		);
	}

	/**
	 * Turn Outpost back on, network-wide on multisite (where the Shortcut
	 * password needs network activation), without running activation hooks.
	 *
	 * @param string $plugin_basename Plugin basename, e.g. outpost-mobile-publishing/outpost.php.
	 * @return bool Whether Outpost is saved as active afterward.
	 */
	private static function reactivate( string $plugin_basename ): bool {
		if ( '' === $plugin_basename ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$result = activate_plugin( $plugin_basename, '', is_multisite(), true );
		if ( is_wp_error( $result ) ) {
			return false;
		}
		// Trust only the saved state: a failed option write would leave the
		// next request without Outpost loaded (T10-S4-27 v1.2).
		if ( is_multisite() ) {
			return is_plugin_active_for_network( $plugin_basename );
		}
		return in_array( $plugin_basename, (array) get_option( 'active_plugins', array() ), true );
	}

	/**
	 * Every user id with at least one application password, network-wide.
	 *
	 * @return array<int, int>
	 */
	private static function users_with_application_passwords(): array {
		$user_ids = get_users(
			array(
				'blog_id'  => 0,
				'meta_key' => '_application_passwords', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time uninstall sweep.
				'fields'   => 'ID',
			)
		);
		return array_map( 'intval', (array) $user_ids );
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

		// Rewrite rules. WP-CLI's `plugin uninstall --deactivate` runs this
		// in the request that loaded Outpost, so drop its registered rules
		// first; then delete the stored table, which WordPress rebuilds on
		// the next request without Outpost (T10-S5-02).
		if ( class_exists( 'Outpost_Route_Handler' ) ) {
			Outpost_Route_Handler::unregister_rewrite_rules();
		}
		delete_option( 'rewrite_rules' );
	}
}
