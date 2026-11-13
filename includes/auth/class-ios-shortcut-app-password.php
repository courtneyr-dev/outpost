<?php
/**
 * Outpost_IOS_Shortcut_App_Password.
 *
 * The iOS Shortcut bridge authenticates with a WordPress Application
 * Password, so login is handled by core's own authentication (and any
 * plugin that extends it). Outpost only:
 *
 *  - creates and revokes the person's Shortcut password through
 *    WP_Application_Passwords, tagged with a fixed Outpost `app_id`;
 *  - narrows what that one password may do: an Application Password
 *    carrying Outpost's `app_id` is accepted only on the
 *    `/outpost/v1/shortcut` REST route. Core passes every candidate
 *    password through `wp_authenticate_application_password_errors`
 *    before accepting it; adding an error there makes core refuse it
 *    (including over XML-RPC). Passwords the person created for other
 *    apps are untouched.
 *
 * Versions before 1.0.27 stored a plugin-issued token in user meta. Those
 * tokens no longer authenticate anything; `purge_legacy_tokens()` deletes
 * them once.
 *
 * @package Outpost
 * @since   1.0.27
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_IOS_Shortcut_App_Password {

	/**
	 * Fixed application id for passwords Outpost creates. A UUID, as core's
	 * `app_id` field expects.
	 */
	public const APP_ID = 'eb290b80-79b0-4104-b470-4d6188280ccc';

	/** The only REST route an Outpost Shortcut password may authenticate. */
	public const ROUTE = '/outpost/v1/shortcut';

	/** User-meta keys of the pre-1.0.27 token, deleted on upgrade. */
	public const LEGACY_META_KEYS = array( 'outpost_ios_shortcut_token', 'outpost_ios_shortcut_first_seen' );

	/** Option recording that legacy tokens were purged. */
	public const LEGACY_PURGED_OPTION = 'outpost_ios_shortcut_legacy_purged';

	/**
	 * Hook registration.
	 */
	public static function register(): void {
		add_action( 'wp_authenticate_application_password_errors', array( __CLASS__, 'restrict_to_shortcut_route' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'purge_legacy_tokens' ) );
	}

	/**
	 * Refuse an Outpost Shortcut password anywhere but the Shortcut route.
	 *
	 * @param \WP_Error            $error Errors core collected for this password; add to it to refuse.
	 * @param \WP_User             $user  User the password belongs to.
	 * @param array<string, mixed> $item  The application-password record.
	 */
	public static function restrict_to_shortcut_route( $error, $user, $item ): void {
		if ( ! $error instanceof \WP_Error || ! is_array( $item ) ) {
			return;
		}
		if ( self::APP_ID !== ( $item['app_id'] ?? '' ) ) {
			return;
		}
		if ( Outpost_Request_Headers::is_rest_route( self::ROUTE ) ) {
			return;
		}
		$error->add(
			'outpost_shortcut_password_out_of_scope',
			__( 'This application password works only for the Outpost iOS Shortcut.', 'outpost-mobile-publishing' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Whether core lets this user create Application Passwords (HTTPS, or a
	 * local environment, and not disabled by a filter).
	 *
	 * @param int $user_id User id.
	 */
	public static function is_available( int $user_id ): bool {
		$user = get_userdata( $user_id );
		return $user instanceof \WP_User && wp_is_application_passwords_available_for_user( $user );
	}

	/**
	 * The user's current Outpost Shortcut password record, if any.
	 *
	 * @param int $user_id User id.
	 * @return array<string, mixed>|null
	 */
	public static function find( int $user_id ): ?array {
		foreach ( \WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( self::APP_ID === ( $item['app_id'] ?? '' ) ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Replace the user's Outpost Shortcut password with a new one.
	 *
	 * @param int $user_id User id.
	 * @return string|\WP_Error The new password in plain text (shown once), or an error.
	 */
	public static function create( int $user_id ) {
		if ( ! self::is_available( $user_id ) ) {
			return new \WP_Error(
				'outpost_shortcut_password_unavailable',
				__( 'Application passwords are not available on this site.', 'outpost-mobile-publishing' )
			);
		}
		self::revoke( $user_id );
		$created = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array(
				'name'   => __( 'Outpost iOS Shortcut', 'outpost-mobile-publishing' ),
				'app_id' => self::APP_ID,
			)
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		return (string) $created[0];
	}

	/**
	 * Delete every Outpost Shortcut password the user has.
	 *
	 * @param int $user_id User id.
	 */
	public static function revoke( int $user_id ): void {
		foreach ( \WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			if ( self::APP_ID === ( $item['app_id'] ?? '' ) && isset( $item['uuid'] ) ) {
				\WP_Application_Passwords::delete_application_password( $user_id, (string) $item['uuid'] );
			}
		}
	}

	/**
	 * Delete pre-1.0.27 plaintext Shortcut tokens, once per site.
	 */
	public static function purge_legacy_tokens(): void {
		if ( get_option( self::LEGACY_PURGED_OPTION ) ) {
			return;
		}
		foreach ( self::LEGACY_META_KEYS as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
		update_option( self::LEGACY_PURGED_OPTION, '1', true );
	}
}
