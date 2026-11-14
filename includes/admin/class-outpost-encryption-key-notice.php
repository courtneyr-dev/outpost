<?php
/**
 * Outpost_Encryption_Key_Notice (G3.5a).
 *
 * Admin notice that fires when Outpost's encryption key lives in
 * wp_options instead of wp-config.php's `OUTPOST_ENCRYPTION_KEY`
 * constant, or when that constant is defined with the wrong length.
 *
 * Guideline 11 scope: the notice renders only where
 * `outpost_is_notice_screen()` allows — the Plugins screens and
 * Outpost's own pages — never on the dashboard or unrelated screens.
 * A dismissal is permanent for that user and that reason; plugin
 * updates don't bring it back.
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
	 * Dismissal user-meta key prefix; the full key adds the reason the
	 * notice showed (`database` or `constant_length`), so dismissing one
	 * warning doesn't silence the other. The uninstaller removes every
	 * key with this prefix.
	 */
	private const DISMISSAL_META_PREFIX = 'outpost_dismissed_encryption_key_notice_';

	private const DISMISS_NONCE_ACTION = 'outpost_dismiss_encryption_key_notice';

	/**
	 * Query key that marks a dismissal request. Unique to Outpost, so the
	 * handler never has to read core's generic `action` parameter.
	 */
	public const DISMISS_QUERY_KEY = 'outpost_dismiss_key_notice';

	/**
	 * Public documentation for moving the key into wp-config.php.
	 */
	private const DOCS_URL = 'https://courtneyr-dev.github.io/outpost/settings/#api-keys-tab';

	/**
	 * Hook registration.
	 *
	 * @since 0.1.69
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_dismissal' ) );
	}

	/**
	 * Render the notice when (a) the screen is a Plugins screen or an
	 * Outpost page, (b) the constant isn't usable, (c) the wp_options key
	 * exists, and (d) the current user hasn't dismissed this reason.
	 *
	 * @since 0.1.69
	 */
	public static function maybe_render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! function_exists( 'outpost_is_notice_screen' ) || ! outpost_is_notice_screen() ) {
			return;
		}
		if ( Outpost_Encryption_Key_Resolver::constant_is_usable() ) {
			return;
		}
		if ( ! Outpost_Encryption_Key_Resolver::option_value_exists() ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		$reason = self::current_reason();
		if ( '1' === (string) get_user_meta( $user_id, self::DISMISSAL_META_PREFIX . $reason, true ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( self::DISMISS_QUERY_KEY, $reason ),
			self::DISMISS_NONCE_ACTION
		);

		echo '<div class="notice notice-warning outpost-encryption-key-notice">';
		echo '<p>';
		if ( 'constant_length' === $reason ) {
			echo esc_html__(
				'Outpost: OUTPOST_ENCRYPTION_KEY is defined in wp-config.php but is not 32 raw bytes, so Outpost is ignoring it and using the key stored in the database. Wrap the value in base64_decode().',
				'outpost-mobile-publishing'
			);
		} else {
			echo esc_html__(
				'Outpost: Your encryption key is stored in the database. For best security, move it to wp-config.php.',
				'outpost-mobile-publishing'
			);
		}
		echo ' <a href="' . esc_url( self::DOCS_URL ) . '" target="_blank" rel="noopener">';
		echo esc_html__( 'Show instructions', 'outpost-mobile-publishing' );
		echo '</a> | <a href="' . esc_url( $dismiss_url ) . '">';
		echo esc_html__( 'Dismiss', 'outpost-mobile-publishing' );
		echo '</a>';
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Record a dismissal from the notice's "Dismiss" link.
	 *
	 * Order: capability, then an isset() check on Outpost's own query key
	 * (no value is read), then the nonce, and only then the value.
	 *
	 * @since 0.1.69
	 */
	public static function maybe_handle_dismissal(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET[ self::DISMISS_QUERY_KEY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only; the nonce is verified on the next line before any value is read.
			return;
		}
		check_admin_referer( self::DISMISS_NONCE_ACTION );
		// '' for an array-shaped value, with no warning (T10-S4-28).
		$reason  = Outpost_Request_Headers::query_key( self::DISMISS_QUERY_KEY );
		$user_id = get_current_user_id();
		if ( $user_id > 0 && in_array( $reason, array( 'database', 'constant_length' ), true ) ) {
			update_user_meta( $user_id, self::DISMISSAL_META_PREFIX . $reason, '1' );
		}
		wp_safe_redirect( remove_query_arg( array( self::DISMISS_QUERY_KEY, '_wpnonce' ) ) );
		if ( ! defined( 'OUTPOST_TESTING_PWA_SHELL' ) ) {
			exit;
		}
	}

	/**
	 * Why the notice would show right now.
	 *
	 * @return string `constant_length` when the constant is defined but
	 *                unusable, otherwise `database`.
	 */
	private static function current_reason(): string {
		return Outpost_Encryption_Key_Resolver::constant_is_defined() ? 'constant_length' : 'database';
	}
}
