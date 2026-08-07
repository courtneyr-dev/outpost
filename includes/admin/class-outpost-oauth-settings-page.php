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
		// Priority 11 so the parent Outpost menu (registered at default
		// priority 10 by Outpost_Admin_Page) exists before this submenu
		// runs. Without this, add_submenu_page resolves the page hook as
		// `admin_page_outpost-oauth` instead of `outpost_page_outpost-oauth`,
		// and admin.php?page=outpost-oauth fails the cap check with a
		// "not allowed" wp_die.
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 11 );
		// G99: working Disconnect button via admin-post.php (the REST
		// endpoint requires X-WP-Nonce which forms can't easily send;
		// admin-post.php's referer-based nonce is form-friendly).
		add_action( 'admin_post_outpost_oauth_disconnect', array( __CLASS__, 'handle_disconnect_post' ) );
	}

	public static function register_menu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'OAuth Connections', 'outpost-mobile-publishing' ),
			__( 'OAuth Connections', 'outpost-mobile-publishing' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'outpost-mobile-publishing' ) );
		}

		echo '<div class="wrap outpost-oauth-wrap">';
		echo '<h1>' . esc_html__( 'Outpost OAuth Connections', 'outpost-mobile-publishing' ) . '</h1>';

		self::maybe_render_status_notice();

		$providers = self::registered_providers();
		if ( empty( $providers ) ) {
			echo '<p>' . esc_html__( 'No OAuth providers are registered.', 'outpost-mobile-publishing' ) . '</p>';
			echo '</div>';
			return;
		}

		$user_id = (int) get_current_user_id();
		echo '<table class="widefat striped" style="max-width:48rem">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Provider', 'outpost-mobile-publishing' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'outpost-mobile-publishing' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'outpost-mobile-publishing' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $providers as $provider ) {
			$id           = $provider->id();
			$is_connected = Outpost_Credentials_Store::is_configured( $id, $user_id );
			// REST cookie auth requires _wpnonce when the request comes
			// from the browser (clicking a link). Without it, the start
			// endpoint rejects with rest_forbidden even for admins.
			$start_url = wp_nonce_url(
				rest_url( 'outpost/v1/oauth/' . $id . '/start' ),
				'wp_rest',
				'_wpnonce'
			);
			echo '<tr>';
			echo '<td>' . esc_html( $provider->label() ) . '</td>';
			echo '<td>' . ( $is_connected
				? '<strong>' . esc_html__( 'Connected', 'outpost-mobile-publishing' ) . '</strong>'
				: esc_html__( 'Not connected', 'outpost-mobile-publishing' ) ) . '</td>';
			echo '<td>';
			if ( $is_connected ) {
				// Working Disconnect form. The whole visible button is
				// the click target (no padding/sizing trick needed —
				// it's a real submit input). admin-post.php verifies
				// the nonce + cap; handle_disconnect_post() dispatches.
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
				echo '<input type="hidden" name="action" value="outpost_oauth_disconnect">';
				echo '<input type="hidden" name="provider" value="' . esc_attr( $id ) . '">';
				wp_nonce_field( 'outpost_oauth_disconnect', '_outpost_disconnect_nonce' );
				echo '<button type="submit" class="button">'
					. esc_html(
						sprintf(
							/* translators: %s: provider label */
							__( 'Disconnect %s', 'outpost-mobile-publishing' ),
							$provider->label()
						)
					)
					. '</button>';
				echo '</form>';
			} else {
				echo '<a class="button button-primary" href="' . esc_url( $start_url ) . '">';
				echo esc_html(
					sprintf(
						/* translators: %s: provider label */
						__( 'Connect %s', 'outpost-mobile-publishing' ),
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
				$message = sprintf( __( '%s connected successfully.', 'outpost-mobile-publishing' ), $provider );
				break;
			case 'state_invalid':
				/* translators: %s: provider label */
				$message = sprintf( __( '%s connection failed: state parameter mismatch. Try again.', 'outpost-mobile-publishing' ), $provider );
				break;
			case 'no_code':
				/* translators: %s: provider label */
				$message = sprintf( __( '%s connection failed: no authorization code received.', 'outpost-mobile-publishing' ), $provider );
				break;
			case 'exchange_failed':
				/* translators: %s: provider label */
				$message = sprintf( __( '%s connection failed during token exchange.', 'outpost-mobile-publishing' ), $provider );
				break;
			case 'persist_failed':
				/* translators: %s: provider label */
				$message = sprintf( __( '%s connected with the provider but credential storage failed. Check site error logs.', 'outpost-mobile-publishing' ), $provider );
				break;
			case 'disconnected':
				/* translators: %s: provider label */
				$message = sprintf( __( '%s disconnected.', 'outpost-mobile-publishing' ), $provider );
				break;
			default:
				return;
		}
		$class = ( 'connected' === $status || 'disconnected' === $status ) ? 'notice-success' : 'notice-error';
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
		// Pull every provider the controller has been told about. Order
		// follows registration order in outpost.php's plugins_loaded hook.
		return array_values( Outpost_OAuth_Controller::get_all_providers() );
	}

	/**
	 * Handle the Disconnect form POST. Verifies nonce + cap, calls the
	 * provider's disconnect() method, then redirects back to the
	 * settings page with a status notice.
	 *
	 * Hooked on `admin_post_outpost_oauth_disconnect`.
	 *
	 * @since 0.1.77
	 */
	public static function handle_disconnect_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to disconnect OAuth providers.', 'outpost-mobile-publishing' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'outpost_oauth_disconnect', '_outpost_disconnect_nonce' );
		$provider_id = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( (string) $_POST['provider'] ) ) : '';

		$provider = Outpost_OAuth_Controller::get_provider( $provider_id );
		if ( null !== $provider ) {
			$provider->disconnect( (int) get_current_user_id() );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                   => self::PAGE_SLUG,
					'outpost_oauth_status'   => 'disconnected',
					'outpost_oauth_provider' => $provider_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
