<?php
/**
 * Outpost_Encryption_Key_Notice (G3.5a).
 *
 * Persistent admin notice that fires when Outpost's encryption key
 * lives in wp_options instead of wp-config.php's
 * `OUTPOST_ENCRYPTION_KEY` constant. Notice is dismissible per-user,
 * but re-appears on each plugin version update via a version-stamped
 * dismissal key.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Encryption_Key_Notice {

	/**
	 * Dismissal user-meta key prefix; full key suffixed with plugin
	 * version so a new plugin version re-shows the notice.
	 */
	private const DISMISSAL_META_PREFIX = 'outpost_dismissed_encryption_key_notice_';

	private const DISMISS_NONCE_ACTION = 'outpost_dismiss_encryption_key_notice';

	private const DISMISS_QUERY_ACTION = 'outpost_dismiss_encryption_key_notice';

	/**
	 * Hook registration. Wires the admin_notices output, the dismissal
	 * AJAX-style query handler, and the docs-link tag for the
	 * "[Show instructions →]" link target.
	 *
	 * @since 0.1.69
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_dismissal' ) );
	}

	/**
	 * Render the notice when (a) constant not defined AND (b) wp_options
	 * key exists AND (c) current user has not dismissed for this version.
	 *
	 * @since 0.1.69
	 */
	public static function maybe_render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( Outpost_Encryption_Key_Resolver::constant_is_defined() ) {
			return;
		}
		if ( ! Outpost_Encryption_Key_Resolver::option_value_exists() ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		$dismissal_key = self::dismissal_meta_key();
		if ( '1' === (string) get_user_meta( $user_id, $dismissal_key, true ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'action', self::DISMISS_QUERY_ACTION, admin_url() ),
			self::DISMISS_NONCE_ACTION
		);
		$docs_url    = admin_url( 'admin.php?page=outpost-docs&doc=encryption-key' );

		echo '<div class="notice notice-warning is-dismissible outpost-encryption-key-notice">';
		echo '<p>';
		echo esc_html__(
			'Outpost: Your encryption key is stored in the database. For best security, move it to wp-config.php.',
			'outpost'
		);
		echo ' <a href="' . esc_url( $docs_url ) . '" target="_blank" rel="noopener">';
		echo esc_html__( 'Show instructions →', 'outpost' );
		echo '</a>';
		echo ' <a href="' . esc_url( $dismiss_url ) . '" style="margin-left:0.5em;">';
		echo esc_html__( 'Dismiss', 'outpost' );
		echo '</a>';
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Handle dismissal via the URL passed back from the notice's
	 * "Dismiss" link. Records dismissal under the version-stamped
	 * meta key so a plugin upgrade reshow the notice.
	 *
	 * @since 0.1.69
	 */
	public static function maybe_handle_dismissal(): void {
		if ( empty( $_GET['action'] ) || self::DISMISS_QUERY_ACTION !== $_GET['action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( self::DISMISS_NONCE_ACTION );
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::dismissal_meta_key(), '1' );
		}
		wp_safe_redirect( remove_query_arg( array( 'action', '_wpnonce' ) ) );
		if ( ! defined( 'OUTPOST_TESTING_PWA_SHELL' ) ) {
			exit;
		}
	}

	/**
	 * Per-version dismissal key. Plugin upgrades show the notice
	 * again so users with stale dismissal don't lose visibility on
	 * the recommendation.
	 *
	 * @return string
	 */
	private static function dismissal_meta_key(): string {
		$version = defined( 'OUTPOST_VERSION' ) ? (string) OUTPOST_VERSION : 'dev';
		return self::DISMISSAL_META_PREFIX . sanitize_key( $version );
	}
}
