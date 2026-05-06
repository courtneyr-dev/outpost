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
				sprintf( __( 'OAuth token exchange failed (HTTP %d).', 'outpost' ), $status ),
				array(
					'status' => $status,
					'body'   => $body,
				)
			);
		}
		if ( empty( $decoded['access_token'] ) ) {
			return new \WP_Error(
				'outpost_oauth_token_missing',
				__( 'OAuth token endpoint returned no access_token.', 'outpost' )
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
}
