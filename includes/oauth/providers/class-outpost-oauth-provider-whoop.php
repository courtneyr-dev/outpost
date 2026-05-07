<?php
/**
 * Outpost_OAuth_Provider_Whoop (G11b).
 *
 * WHOOP OAuth 2.0 provider. Reads from WHOOP's developer/v1 API for
 * the connected user's profile, sleep, recovery, cycles, and workouts.
 * Future PRs add the source class.
 *
 * - Authorize URL: https://api.prod.whoop.com/oauth/oauth2/auth
 * - Token URL:     https://api.prod.whoop.com/oauth/oauth2/token
 * - Scopes:        read:profile read:sleep read:recovery read:cycles read:workout
 *                  (full read-only set covering the data domains a user
 *                  shares from a WHOOP strap)
 * - Token shape:   standard OAuth 2.0 (access_token, refresh_token,
 *                  token_type, expires_in). Tokens expire hourly —
 *                  refresh path inherited from the base.
 * - Revocation:    NOT RFC 7009 — WHOOP's docs as of 2026-Q1 expose a
 *                  custom DELETE /developer/v2/user/access endpoint
 *                  carrying the user's bearer token. revocation_endpoint()
 *                  returns null (the inherited disconnect() RFC 7009
 *                  path doesn't apply); disconnect() is overridden to
 *                  call WHOOP's DELETE before falling through to
 *                  local credential deletion.
 *
 * MEMBERSHIP
 *
 * Unlike Oura (G11a), WHOOP doesn't gate API access by separate active
 * membership beyond OAuth validity. A lapsed WHOOP membership simply
 * surfaces standard 401 from the API — handled by the existing
 * is_expired() / refresh_access_token() / token-rejection path. No
 * special detection needed.
 *
 * APP APPROVAL
 *
 * WHOOP requires app approval before public launch but NOT for
 * personal-use development. Users registering their own app at
 * https://developer.whoop.com can authorize their own account without
 * launch approval. Documented in docs/adapters/whoop.md.
 *
 * Client credentials resolved from constants:
 *   OUTPOST_WHOOP_CLIENT_ID
 *   OUTPOST_WHOOP_CLIENT_SECRET
 *
 * @package Outpost
 * @since   0.1.75
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_OAuth_Provider_Whoop extends Outpost_OAuth_Provider_Base {

	private const VERIFY_ENDPOINT = 'https://api.prod.whoop.com/developer/v1/user/profile/basic';

	private const REVOKE_ENDPOINT = 'https://api.prod.whoop.com/developer/v2/user/access';

	private const VERIFY_TIMEOUT = 5;

	private const REVOKE_TIMEOUT = 5;

	public function id(): string {
		return 'whoop';
	}

	public function label(): string {
		return __( 'WHOOP', 'outpost' );
	}

	public function authorize_url(): string {
		return 'https://api.prod.whoop.com/oauth/oauth2/auth';
	}

	public function token_url(): string {
		return 'https://api.prod.whoop.com/oauth/oauth2/token';
	}

	public function revocation_endpoint(): ?string {
		// WHOOP doesn't expose RFC 7009. disconnect() override below
		// handles the WHOOP-specific DELETE endpoint instead.
		return null;
	}

	/**
	 * @return string[]
	 */
	public function scopes(): array {
		return array(
			'read:profile',
			'read:sleep',
			'read:recovery',
			'read:cycles',
			'read:workout',
		);
	}

	public function client_id(): string {
		if ( defined( 'OUTPOST_WHOOP_CLIENT_ID' ) ) {
			return (string) constant( 'OUTPOST_WHOOP_CLIENT_ID' );
		}
		return '';
	}

	public function client_secret(): string {
		if ( defined( 'OUTPOST_WHOOP_CLIENT_SECRET' ) ) {
			return (string) constant( 'OUTPOST_WHOOP_CLIENT_SECRET' );
		}
		return '';
	}

	/**
	 * Verify the stored connection by hitting profile/basic.
	 *
	 * @since 0.1.75
	 *
	 * @param int $user_id User id.
	 * @return array<string,mixed>|\WP_Error
	 * @phpstan-ignore return.unusedType (parent allows WP_Error; WHOOP always returns the structured shape)
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
		$decoded = is_array( $decoded ) ? $decoded : array();
		return array(
			'ok'         => true,
			'first_name' => isset( $decoded['first_name'] ) ? (string) $decoded['first_name'] : '',
			'user_id'    => isset( $decoded['user_id'] ) && is_numeric( $decoded['user_id'] ) ? (int) $decoded['user_id'] : 0,
		);
	}

	/**
	 * Override the inherited disconnect to call WHOOP's custom revoke
	 * endpoint (DELETE /developer/v2/user/access with Bearer header)
	 * before deleting local credentials. Failures fall through to local
	 * delete so disconnect always succeeds locally.
	 *
	 * @since 0.1.75
	 *
	 * @param int $user_id User id.
	 * @return bool True after attempting revocation + delete.
	 */
	public function disconnect( int $user_id ): bool {
		$creds = Outpost_Credentials_Store::get( $this->id(), $user_id );
		if ( is_array( $creds ) && ! empty( $creds['access_token'] ) ) {
			$response = wp_remote_request(
				self::REVOKE_ENDPOINT,
				array(
					'method'  => 'DELETE',
					'timeout' => self::REVOKE_TIMEOUT,
					'headers' => array(
						'Authorization' => 'Bearer ' . (string) $creds['access_token'],
						'Accept'        => 'application/json',
					),
				)
			);
			if ( is_wp_error( $response ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				/* phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log */
				error_log( sprintf( 'Outpost WHOOP revocation failed: %s', $response->get_error_message() ) );
			}
		}
		return Outpost_Credentials_Store::delete( $this->id(), $user_id );
	}
}
