<?php
/**
 * Outpost_OAuth_Provider_Ravelry (G14b).
 *
 * Ravelry OAuth 2.0 provider. Reads from Ravelry's API for the connected
 * user's patterns library, projects, and queue. Future PRs add the
 * source class.
 *
 * - Authorize URL: https://www.ravelry.com/oauth2/auth
 * - Token URL:     https://www.ravelry.com/oauth2/token
 * - Scopes:        offline + read-only patterns + projects + library
 * - Revocation:    NOT SUPPORTED at the time of writing — Ravelry's
 *                  OAuth 2.0 docs don't expose RFC 7009. revocation_endpoint()
 *                  returns null. Disconnect falls through to local delete.
 * - Token shape:   standard OAuth 2.0 with refresh_token (`offline` scope
 *                  is what triggers refresh-token issuance).
 *
 * MIGRATION NOTE
 *
 * Outpost uses OAuth 2.0 only. Ravelry's old OAuth 1.0a API is deprecated
 * here. Users who registered 1.0a apps must register a new OAuth 2.0
 * app at https://www.ravelry.com/pro/developer. Documented in
 * docs/adapters/ravelry.md.
 *
 * SCOPE STRINGS — VERIFY BEFORE PRODUCTION
 *
 * Ravelry's scope-naming convention has shifted across API revisions
 * (kebab-case vs underscore-prefixed). The defaults below are the most
 * common shape but should be verified against the current Ravelry
 * developer dashboard before production deployment. Override via the
 * `outpost_oauth_provider_ravelry_scopes` filter.
 *
 * Client credentials resolved from constants:
 *   OUTPOST_RAVELRY_CLIENT_ID
 *   OUTPOST_RAVELRY_CLIENT_SECRET
 *
 * @package Outpost
 * @since   0.1.73
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_OAuth_Provider_Ravelry extends Outpost_OAuth_Provider_Base {

	private const VERIFY_ENDPOINT = 'https://api.ravelry.com/current_user.json';

	private const VERIFY_TIMEOUT = 5;

	public function id(): string {
		return 'ravelry';
	}

	public function label(): string {
		return __( 'Ravelry', 'outpost' );
	}

	public function authorize_url(): string {
		return 'https://www.ravelry.com/oauth2/auth';
	}

	public function token_url(): string {
		return 'https://www.ravelry.com/oauth2/token';
	}

	public function revocation_endpoint(): ?string {
		// Ravelry does not currently expose an RFC 7009 revocation
		// endpoint. Documented in docs/adapters/ravelry.md.
		return null;
	}

	/**
	 * Default Ravelry OAuth 2.0 scope set. `offline` is required to
	 * receive a refresh_token. Per-resource read scopes follow the
	 * kebab-case convention common in current Ravelry docs.
	 *
	 * Site owners can override via `outpost_oauth_provider_ravelry_scopes`.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		$default = array(
			'offline',
			'personal-data',
			'library-read',
			'projects-read',
			'patterns-read',
		);
		/**
		 * Filter the Ravelry OAuth scope set.
		 *
		 * @since 0.1.73
		 *
		 * @param string[] $scopes Default scope list.
		 */
		$filtered = apply_filters( 'outpost_oauth_provider_ravelry_scopes', $default );
		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $default;
	}

	public function client_id(): string {
		if ( defined( 'OUTPOST_RAVELRY_CLIENT_ID' ) ) {
			return (string) constant( 'OUTPOST_RAVELRY_CLIENT_ID' );
		}
		return '';
	}

	public function client_secret(): string {
		if ( defined( 'OUTPOST_RAVELRY_CLIENT_SECRET' ) ) {
			return (string) constant( 'OUTPOST_RAVELRY_CLIENT_SECRET' );
		}
		return '';
	}

	/**
	 * Verify the stored connection by hitting current_user.json.
	 *
	 * @since 0.1.73
	 *
	 * @param int $user_id User id.
	 * @return array<string,mixed>|\WP_Error
	 * @phpstan-ignore return.unusedType (parent allows WP_Error; Ravelry always returns the structured shape)
	 */
	public function verify_connection( int $user_id ) {
		$creds = Outpost_Credentials_Store::get( $this->id(), $user_id );
		if ( ! is_array( $creds ) || empty( $creds['access_token'] ) ) {
			return array(
				'ok'     => false,
				'reason' => 'no_credentials',
			);
		}
		$response = wp_remote_get(
			self::VERIFY_ENDPOINT,
			array(
				'timeout' => self::VERIFY_TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . (string) $creds['access_token'],
					'Accept'        => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'reason' => 'transport_failed',
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== $status ) {
			return array(
				'ok'     => false,
				'reason' => 'auth_failed',
				'status' => $status,
			);
		}
		$decoded = json_decode( $body, true );
		// Ravelry wraps the user object under `user`. Tolerate both
		// nested and flat shapes — historical revisions have used both.
		$user = is_array( $decoded ) && isset( $decoded['user'] ) && is_array( $decoded['user'] )
			? $decoded['user']
			: ( is_array( $decoded ) ? $decoded : array() );
		return array(
			'ok'           => true,
			'username'     => isset( $user['username'] ) ? (string) $user['username'] : '',
			'display_name' => isset( $user['displayname'] ) ? (string) $user['displayname'] : ( isset( $user['display_name'] ) ? (string) $user['display_name'] : '' ),
			'id'           => isset( $user['id'] ) && is_numeric( $user['id'] ) ? (int) $user['id'] : 0,
		);
	}
}
