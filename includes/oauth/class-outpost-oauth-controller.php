<?php
/**
 * Outpost_OAuth_Controller (G3.5a).
 *
 * Registers OAuth 2.0 REST routes per provider:
 *
 *   GET  /wp-json/outpost/v1/oauth/{provider}/start      → redirect to authorize URL
 *   GET  /wp-json/outpost/v1/oauth/{provider}/callback   → exchange code, store creds
 *   POST /wp-json/outpost/v1/oauth/{provider}/disconnect → revoke + delete creds
 *
 * All three routes require manage_options + a logged-in user. The
 * callback URL pattern is fixed; each provider's app registration uses
 * its own callback URL with the provider id in the path.
 *
 * Provider registration via add_provider(); G3.5a ships with Notion
 * registered.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_OAuth_Controller {

	/**
	 * Registered provider instances keyed by provider id.
	 *
	 * @var array<string,Outpost_OAuth_Provider_Base>
	 */
	private static array $providers = array();

	/**
	 * Hook registration.
	 *
	 * @since 0.1.69
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register a provider. Idempotent — re-registering the same id
	 * overwrites the previous instance (useful for tests).
	 *
	 * @since 0.1.69
	 *
	 * @param Outpost_OAuth_Provider_Base $provider Provider instance.
	 */
	public static function add_provider( Outpost_OAuth_Provider_Base $provider ): void {
		self::$providers[ $provider->id() ] = $provider;
	}

	/**
	 * Look up a provider by id; null when unknown.
	 *
	 * @since 0.1.69
	 */
	public static function get_provider( string $id ): ?Outpost_OAuth_Provider_Base {
		return self::$providers[ $id ] ?? null;
	}

	/**
	 * Reset registry. Test seam.
	 *
	 * @since 0.1.69
	 */
	public static function reset_providers_for_tests(): void {
		self::$providers = array();
	}

	/**
	 * Return all registered providers in registration order.
	 *
	 * @since 0.1.71
	 *
	 * @return array<string,Outpost_OAuth_Provider_Base>
	 */
	public static function get_all_providers(): array {
		return self::$providers;
	}

	/**
	 * Register the three REST routes per provider.
	 *
	 * @since 0.1.69
	 */
	public static function register_routes(): void {
		register_rest_route(
			'outpost/v1',
			'/oauth/(?P<provider>[a-z0-9-]+)/start',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_start' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'show_in_index'       => false,
			)
		);
		register_rest_route(
			'outpost/v1',
			'/oauth/(?P<provider>[a-z0-9-]+)/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_callback' ),
				// Callback is hit by the OAuth provider redirecting the
				// user's browser back; it can't carry a WP REST nonce.
				// Authentication is the state value itself, validated
				// inside the handler before any side effects.
				'permission_callback' => '__return_true',
				'show_in_index'       => false,
			)
		);
		register_rest_route(
			'outpost/v1',
			'/oauth/(?P<provider>[a-z0-9-]+)/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_disconnect' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'show_in_index'       => false,
			)
		);
		register_rest_route(
			'outpost/v1',
			'/oauth/(?P<provider>[a-z0-9-]+)/verify',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_verify' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'show_in_index'       => false,
			)
		);
	}

	public static function permission_check(): bool {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/**
	 * GET /start — generate state, redirect to authorize URL.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_start( \WP_REST_Request $request ) {
		$provider = self::resolve_provider( $request );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}
		$user_id  = (int) get_current_user_id();
		$state    = Outpost_OAuth_State::generate( $provider->id(), $user_id );
		$url      = $provider->build_authorize_url( $state );
		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', $url );
		return $response;
	}

	/**
	 * GET /callback — validate state, exchange code, store credentials,
	 * redirect to a settings page with success/error notice.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_callback( \WP_REST_Request $request ) {
		$provider = self::resolve_provider( $request );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}
		$code  = (string) $request->get_param( 'code' );
		$state = (string) $request->get_param( 'state' );

		if ( '' === $code ) {
			return self::redirect_to_settings( $provider->id(), 'no_code' );
		}
		// State validation derives the user_id; callback can't carry a
		// REST nonce so the state value itself is the authentication.
		$user_id = Outpost_OAuth_State::validate( $provider->id(), $state );
		if ( null === $user_id ) {
			return self::redirect_to_settings( $provider->id(), 'state_invalid' );
		}
		// Trust the user from the validated state for the rest of this
		// request (credential persistence is per-user).
		wp_set_current_user( $user_id );

		$exchange = $provider->exchange_code( $code );
		if ( is_wp_error( $exchange ) ) {
			return self::redirect_to_settings( $provider->id(), 'exchange_failed' );
		}
		$creds  = $provider->shape_credentials( $exchange );
		$stored = Outpost_Credentials_Store::set( $provider->id(), $creds, $user_id );
		if ( ! $stored ) {
			return self::redirect_to_settings( $provider->id(), 'persist_failed' );
		}
		return self::redirect_to_settings( $provider->id(), 'connected' );
	}

	/**
	 * POST /disconnect — call provider revoke (when supported), delete local creds.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_disconnect( \WP_REST_Request $request ) {
		$provider = self::resolve_provider( $request );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}
		$user_id = (int) get_current_user_id();
		$ok      = $provider->disconnect( $user_id );
		return new \WP_REST_Response( array( 'disconnected' => $ok ), 200 );
	}

	/**
	 * GET /verify — call the provider's verify_connection() and return
	 * its shape. Useful for UI confirmation that the stored credentials
	 * still work AND that data access is alive (some providers gate
	 * data access behind separate paid tiers).
	 *
	 * @since 0.1.71
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_verify( \WP_REST_Request $request ) {
		$provider = self::resolve_provider( $request );
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}
		$user_id = (int) get_current_user_id();
		$result  = $provider->verify_connection( $user_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Build a settings-page redirect with a status query parameter
	 * the page can use to render an admin notice.
	 *
	 * @param string $provider_id Provider id.
	 * @param string $status      Status code ('connected', 'state_invalid', etc.).
	 * @return \WP_REST_Response
	 */
	private static function redirect_to_settings( string $provider_id, string $status ): \WP_REST_Response {
		$url      = add_query_arg(
			array(
				'page'                   => 'outpost-oauth',
				'outpost_oauth_status'   => $status,
				'outpost_oauth_provider' => $provider_id,
			),
			admin_url( 'admin.php' )
		);
		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', $url );
		return $response;
	}

	/**
	 * Look up the provider on a callback / start request.
	 *
	 * @return Outpost_OAuth_Provider_Base|\WP_Error
	 */
	private static function resolve_provider( \WP_REST_Request $request ) {
		$id       = (string) $request->get_param( 'provider' );
		$provider = self::get_provider( $id );
		if ( null === $provider ) {
			return new \WP_Error(
				'outpost_oauth_unknown_provider',
				/* translators: %s: provider id */
				sprintf( __( 'Unknown OAuth provider: %s', 'outpost' ), $id ),
				array( 'status' => 404 )
			);
		}
		return $provider;
	}
}
