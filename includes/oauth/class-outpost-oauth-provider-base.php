<?php
/**
 * Outpost_OAuth_Provider_Base (G3.5a).
 *
 * Abstract base for per-provider OAuth 2.0 implementations. Each
 * provider subclass declares its endpoints, scope set, and any
 * provider-specific quirks (Notion's API version header, etc.).
 *
 * The base handles: state validation, code-for-token exchange,
 * credentials persistence via Outpost_Credentials_Store, token
 * refresh when supported, revocation when supported.
 *
 * Note on league/oauth2-client: the original G3.5a prompt called for a
 * Composer dep on `league/oauth2-client`. This implementation ships
 * without that dep — the auth-code flow plus token exchange runs
 * ~120 lines of focused PHP. Adding the league dep is additive when
 * 3+ providers exist; with only Notion in this PR, the inline path is
 * smaller. Documented as deviation in the PR description.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Outpost_OAuth_Provider_Base {

	/**
	 * Provider id (e.g., 'notion'). Stable, lowercase, kebab-case.
	 */
	abstract public function id(): string;

	/**
	 * Human-readable label for UI (e.g., 'Notion').
	 */
	abstract public function label(): string;

	/**
	 * Authorization endpoint (where the user is redirected to grant access).
	 */
	abstract public function authorize_url(): string;

	/**
	 * Token endpoint (where we exchange code → token).
	 */
	abstract public function token_url(): string;

	/**
	 * Optional revocation endpoint (RFC 7009). Return null when the
	 * provider does not expose one — disconnect deletes local creds
	 * regardless.
	 */
	abstract public function revocation_endpoint(): ?string;

	/**
	 * OAuth scopes. Empty array means no scopes (e.g. Notion).
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		return array();
	}

	/**
	 * Client id, resolved from config (constant or filter).
	 */
	abstract public function client_id(): string;

	/**
	 * Client secret, resolved from config.
	 */
	abstract public function client_secret(): string;

	/**
	 * Build the redirect_uri for this provider on this site.
	 *
	 * @since 0.1.69
	 */
	final public function redirect_uri(): string {
		return rest_url( 'outpost/v1/oauth/' . $this->id() . '/callback' );
	}

	/**
	 * Hook for subclasses to transform the token-endpoint response into
	 * the credentials array stored at rest. Default returns the
	 * response decoded body as-is, plus 'obtained_at' timestamp.
	 *
	 * Notion overrides this to surface workspace_id / workspace_name /
	 * bot_id / owner alongside the access_token.
	 *
	 * @param array<string,mixed> $response_body Decoded JSON body.
	 * @return array<string,mixed>
	 */
	public function shape_credentials( array $response_body ): array {
		$response_body['obtained_at'] = time();
		return $response_body;
	}

	/**
	 * Build the URL the user gets redirected to (with state in query).
	 *
	 * @since 0.1.69
	 *
	 * @param string $state State value.
	 * @return string Full authorize URL.
	 */
	public function build_authorize_url( string $state ): string {
		$params = array(
			'response_type' => 'code',
			'client_id'     => $this->client_id(),
			'redirect_uri'  => $this->redirect_uri(),
			'state'         => $state,
		);
		$scopes = $this->scopes();
		if ( ! empty( $scopes ) ) {
			$params['scope'] = implode( ' ', $scopes );
		}
		return $this->authorize_url() . '?' . http_build_query( $params );
	}

	/**
	 * Exchange an authorization code for an access token. Returns the
	 * token-endpoint response body decoded. Subclasses' shape_credentials()
	 * post-processes before persistence.
	 *
	 * @since 0.1.69
	 *
	 * @param string $code Authorization code from the callback.
	 * @return array<string,mixed>|WP_Error
	 */
	public function exchange_code( string $code ) {
		$body     = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => $this->redirect_uri(),
			'client_id'     => $this->client_id(),
			'client_secret' => $this->client_secret(),
		);
		$response = wp_remote_post(
			$this->token_url(),
			array(
				'timeout' => 10,
				'headers' => array_merge(
					array(
						'Accept' => 'application/json',
					),
					$this->extra_token_request_headers()
				),
				'body'    => $this->token_request_body( $body ),
			)
		);
		return $this->parse_token_response( $response );
	}

	/**
	 * Hook for subclasses to control the request-body shape. Default
	 * returns the array unchanged so wp_remote_post form-encodes it
	 * (the standard OAuth 2.0 token-endpoint shape per RFC 6749).
	 *
	 * Notion overrides this to JSON-encode the body — Notion's token
	 * endpoint requires Content-Type: application/json with a JSON body,
	 * NOT form-encoded.
	 *
	 * @param array<string,string> $body Body parameters.
	 * @return array<string,string>|string
	 */
	protected function token_request_body( array $body ) {
		return $body;
	}

	/**
	 * Hook for subclasses to add provider-specific token-request headers
	 * (e.g., Notion-Version on Notion's token endpoint).
	 *
	 * @return array<string,string>
	 */
	protected function extra_token_request_headers(): array {
		return array();
	}

	/**
	 * Parse a wp_remote_post response from a token-endpoint exchange.
	 *
	 * @param mixed $response wp_remote_post return.
	 * @return array<string,mixed>|WP_Error
	 */
	final protected function parse_token_response( $response ) {
		if ( is_wp_error( $response ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				/* phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log */
				error_log( 'Outpost OAuth token transport error: ' . $response->get_error_message() );
			}
			return new \WP_Error(
				'outpost_oauth_token_transport',
				$response->get_error_message()
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ( $status < 200 || $status >= 300 ) ) {
			/* phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log */
			error_log( sprintf( 'Outpost OAuth token exchange failed: HTTP %d body=%s', $status, $body ) );
		}
		$decoded = json_decode( $body, true );
		if ( $status < 200 || $status >= 300 || ! is_array( $decoded ) ) {
			return new \WP_Error(
				'outpost_oauth_token_failed',
				/* translators: %d: HTTP status */
				sprintf( __( 'OAuth token exchange failed (HTTP %d).', 'outpost-mobile-publishing' ), $status ),
				array(
					'status' => $status,
					'body'   => $body,
				)
			);
		}
		if ( empty( $decoded['access_token'] ) ) {
			return new \WP_Error(
				'outpost_oauth_token_missing',
				__( 'OAuth token endpoint returned no access_token.', 'outpost-mobile-publishing' )
			);
		}
		return $decoded;
	}

	/**
	 * Revoke the stored access token at the provider, then delete local
	 * credentials. Revocation failures are logged at debug level and
	 * do NOT block local deletion.
	 *
	 * @since 0.1.69
	 *
	 * @param int $user_id User whose creds to disconnect.
	 * @return bool True after attempting revocation + delete.
	 */
	public function disconnect( int $user_id ): bool {
		$endpoint = $this->revocation_endpoint();
		if ( null !== $endpoint ) {
			$creds = Outpost_Credentials_Store::get( $this->id(), $user_id );
			if ( is_array( $creds ) && ! empty( $creds['access_token'] ) ) {
				$response = wp_remote_post(
					$endpoint,
					array(
						'timeout' => 5,
						'body'    => array(
							'token'         => (string) $creds['access_token'],
							'client_id'     => $this->client_id(),
							'client_secret' => $this->client_secret(),
						),
					)
				);
				if ( is_wp_error( $response ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					/* phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log */
					error_log( sprintf( 'Outpost OAuth revocation failed for %s: %s', $this->id(), $response->get_error_message() ) );
				}
			}
		}
		return Outpost_Credentials_Store::delete( $this->id(), $user_id );
	}

	/**
	 * Whether the stored credentials for the user have expired or are
	 * within 60 seconds of expiry. Returns false when no expiry is
	 * tracked (e.g. Notion's never-expiring tokens).
	 *
	 * Expiry is computed against `obtained_at + expires_in - 60s`. The
	 * 60s skew gives the refresh path room to fire before the token
	 * actually expires.
	 *
	 * @since 0.1.71
	 *
	 * @param int $user_id User id.
	 * @return bool True when credentials need refresh.
	 */
	public function is_expired( int $user_id ): bool {
		$creds = Outpost_Credentials_Store::get( $this->id(), $user_id );
		if ( ! is_array( $creds ) || empty( $creds['expires_in'] ) ) {
			return false;
		}
		$obtained_at = isset( $creds['obtained_at'] ) ? (int) $creds['obtained_at'] : 0;
		$expires_in  = (int) $creds['expires_in'];
		if ( $obtained_at <= 0 || $expires_in <= 0 ) {
			return false;
		}
		return time() >= ( $obtained_at + $expires_in - 60 );
	}

	/**
	 * Refresh the stored access token using the stored refresh_token.
	 * Returns the freshly-shaped credentials array on success or a
	 * WP_Error when the refresh fails. The credentials store is updated
	 * in-place on success.
	 *
	 * Concrete provider behavior is identical to RFC 6749 §6 — POST
	 * `grant_type=refresh_token` + `refresh_token` to the token URL.
	 * Providers that need extra headers (Notion's Basic auth) inherit
	 * the existing `extra_token_request_headers()` hook automatically.
	 *
	 * @since 0.1.71
	 *
	 * @param int $user_id User id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function refresh_access_token( int $user_id ) {
		$creds = Outpost_Credentials_Store::get( $this->id(), $user_id );
		if ( ! is_array( $creds ) || empty( $creds['refresh_token'] ) ) {
			return new \WP_Error(
				'outpost_oauth_no_refresh_token',
				__( 'No refresh_token stored — reconnect this provider.', 'outpost-mobile-publishing' )
			);
		}
		$body     = array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => (string) $creds['refresh_token'],
			'client_id'     => $this->client_id(),
			'client_secret' => $this->client_secret(),
		);
		$response = wp_remote_post(
			$this->token_url(),
			array(
				'timeout' => 10,
				'headers' => array_merge(
					array( 'Accept' => 'application/json' ),
					$this->extra_token_request_headers()
				),
				'body'    => $this->token_request_body( $body ),
			)
		);
		$decoded  = $this->parse_token_response( $response );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		// If the provider didn't return a fresh refresh_token, preserve
		// the existing one — RFC 6749 §6 allows omission to mean "reuse".
		if ( empty( $decoded['refresh_token'] ) && ! empty( $creds['refresh_token'] ) ) {
			$decoded['refresh_token'] = (string) $creds['refresh_token'];
		}
		$reshaped = $this->shape_credentials( $decoded );
		Outpost_Credentials_Store::set( $this->id(), $reshaped, $user_id );
		return $reshaped;
	}

	/**
	 * Verify the stored connection by hitting a provider-specific
	 * endpoint that confirms the token is alive AND that data access
	 * works (not just OAuth validity — for some providers, OAuth is
	 * fine but data access is gated separately, e.g. Oura membership).
	 *
	 * Default returns a "not implemented" WP_Error. Providers that
	 * support verification override this method.
	 *
	 * Verification responses follow the shape:
	 *   `{ ok: true, ...provider-specific identity fields }`
	 *   `{ ok: false, reason: 'auth_failed' | 'membership_required' | ... }`
	 *
	 * @since 0.1.71
	 *
	 * @param int $user_id User id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function verify_connection( int $user_id ) {
		unset( $user_id );
		return new \WP_Error(
			'outpost_oauth_verify_not_implemented',
			__( 'This provider does not implement connection verification.', 'outpost-mobile-publishing' )
		);
	}

	/**
	 * Hook called by the controller after successful token exchange AND
	 * credential persistence. Default is a no-op. Providers that need
	 * post-exchange registration / activation steps override this.
	 *
	 * Polar Flow's AccessLink uses this to POST /v3/users with the
	 * access token to register the user with the application — without
	 * that step, no Polar data API call works. RFC 6749 has no native
	 * concept for this dance, so it lives in a provider-specific hook
	 * rather than the base flow.
	 *
	 * Failures inside after_token_exchange() MUST NOT abort the connect
	 * flow — credentials are already stored. Providers should log
	 * failures at debug level and surface them on the next
	 * verify_connection() call, where the user can retry registration.
	 *
	 * @since 0.1.76
	 *
	 * @param int                 $user_id User id.
	 * @param array<string,mixed> $creds   The freshly-shaped credentials.
	 */
	public function after_token_exchange( int $user_id, array $creds ): void {
		unset( $user_id, $creds );
	}
}
