<?php
/**
 * Outpost_OAuth_Settings_Page (G3.5a — minimal).
 *
 * Renders a per-provider Connect / Disconnect block in the WP admin.
 * Single page; one row per registered OAuth provider. Rich UX (sidebar
 * plugin, multiple settings tabs, etc.) is G3.5d/c — this page is the
 * minimum surface needed to validate the OAuth flow end-to-end.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_OAuth_Settings_Page {

	private const PARENT_SLUG = 'outpost';
	private const PAGE_SLUG   = 'outpost-oauth';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	public static function register_menu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'OAuth Connections', 'outpost' ),
			__( 'OAuth Connections', 'outpost' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'outpost' ) );
		}

		echo '<div class="wrap outpost-oauth-wrap">';
		echo '<h1>' . esc_html__( 'Outpost OAuth Connections', 'outpost' ) . '</h1>';

		self::maybe_render_status_notice();

		$providers = self::registered_providers();
		if ( empty( $providers ) ) {
			echo '<p>' . esc_html__( 'No OAuth providers are registered.', 'outpost' ) . '</p>';
			echo '</div>';
			return;
		}

		$user_id = (int) get_current_user_id();
		echo '<table class="widefat striped" style="max-width:48rem">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Provider', 'outpost' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'outpost' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'outpost' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $providers as $provider ) {
			$id           = $provider->id();
			$is_connected = Outpost_Credentials_Store::is_configured( $id, $user_id );
			$start_url    = rest_url( 'outpost/v1/oauth/' . $id . '/start' );
			echo '<tr>';
			echo '<td>' . esc_html( $provider->label() ) . '</td>';
			echo '<td>' . ( $is_connected
				? '<strong>' . esc_html__( 'Connected', 'outpost' ) . '</strong>'
				: esc_html__( 'Not connected', 'outpost' ) ) . '</td>';
			echo '<td>';
			if ( $is_connected ) {
				echo '<button type="button" class="button" disabled>'
					. esc_html__( 'Disconnect (POST to /disconnect)', 'outpost' )
					. '</button>';
				echo '<p class="description" style="margin-top:.5em">'
					. sprintf(
						/* translators: %s: REST endpoint URL */
						esc_html__( 'Disconnect via authenticated POST to %s', 'outpost' ),
						'<code>' . esc_html( rest_url( 'outpost/v1/oauth/' . $id . '/disconnect' ) ) . '</code>'
					)
					. '</p>';
			} else {
				echo '<a class="button button-primary" href="' . esc_url( $start_url ) . '">';
				echo esc_html(
					sprintf(
						/* translators: %s: provider label */
						__( 'Connect %s', 'outpost' ),
						$provider->label()
					)
				);
				echo '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Surface the OAuth callback's status (connected / state_invalid /
	 * exchange_failed / etc.) as an admin notice on the settings page.
	 */
	private static function maybe_render_status_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only OAuth callback redirect; status param is sanitized + matched against allowlist below.
		$status = isset( $_GET['outpost_oauth_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['outpost_oauth_status'] ) ) : '';
		if ( '' === $status ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only OAuth callback redirect; provider id is sanitized to a key.
		$provider = isset( $_GET['outpost_oauth_provider'] ) ? sanitize_key( wp_unslash( (string) $_GET['outpost_oauth_provider'] ) ) : '';

		switch ( $status ) {
			case 'connected':
				/* translators: %s: provider label */
				$message = sprintf( __( '%s connected successfully.', 'outpost' ), $provider );
				break;
			case 'state_invalid':
				/* translators: %s: provider label */
				$message = sprintf( __( '%s connection failed: state parameter mismatch. Try again.', 'outpost' ), $provider );
				break;
			case 'no_code':
				/* translators: %s: provider label */
				$message = sprintf( __( '%s connection failed: no authorization code received.', 'outpost' ), $provider );
				break;
			case 'exchange_failed':
				/* translators: %s: provider label */
				$message = sprintf( __( '%s connection failed during token exchange.', 'outpost' ), $provider );
				break;
			case 'persist_failed':
				/* translators: %s: provider label */
				$message = sprintf( __( '%s connected with the provider but credential storage failed. Check site error logs.', 'outpost' ), $provider );
				break;
			default:
				return;
		}
		$class = ( 'connected' === $status ) ? 'notice-success' : 'notice-error';
		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	/**
	 * @return Outpost_OAuth_Provider_Base[]
	 */
	private static function registered_providers(): array {
		$out = array();
		// G3.5a ships Notion only; future providers add to this list as
		// they're registered with the controller.
		$ids = array( 'notion' );
		foreach ( $ids as $id ) {
			$p = Outpost_OAuth_Controller::get_provider( $id );
			if ( null !== $p ) {
				$out[] = $p;
			}
		}
		return $out;
	}
}
