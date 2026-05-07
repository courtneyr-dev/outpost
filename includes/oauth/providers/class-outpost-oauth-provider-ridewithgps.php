<?php
/**
 * Outpost_OAuth_Provider_Ridewithgps (G12a).
 *
 * Ride With GPS OAuth 2.0 provider. Reads from RWG's v1 API for the
 * connected user's profile and rides.
 *
 * - Authorize URL: https://ridewithgps.com/oauth/authorize
 * - Token URL:     https://ridewithgps.com/oauth/token.json
 *                  (note `.json` suffix; RWG-specific)
 * - Scopes:        read (default for v1; write deferred until outbound
 *                  trip-sync is in scope, future PR)
 * - Revocation:    NOT SUPPORTED (RWG does not currently expose RFC 7009
 *                  revocation; revocation_endpoint() returns null.
 *                  disconnect() falls through to local delete.)
 * - Token shape:   standard OAuth 2.0; access_token + token_type +
 *                  optional refresh_token. Some RWG OAuth apps issue
 *                  long-lived tokens without refresh — handle missing
 *                  refresh_token gracefully (no crash, no false expiry).
 *
 * PRIVACY BOUNDARY (for future source-class implementer)
 *
 * RWG routes have a `privacy_code` field on the route object. Future
 * source-class work that reads route content MUST NOT expose private
 * routes. The verify endpoint here only reads the user's own profile,
 * which is always accessible.
 *
 * Client credentials resolved from constants:
 *   OUTPOST_RIDEWITHGPS_CLIENT_ID
 *   OUTPOST_RIDEWITHGPS_CLIENT_SECRET
 *
 * @package Outpost
 * @since   0.1.72
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_OAuth_Provider_Ridewithgps extends Outpost_OAuth_Provider_Base {

	private const VERIFY_ENDPOINT = 'https://ridewithgps.com/api/v1/users/current.json';

	private const VERIFY_TIMEOUT = 5;

	public function id(): string {
		return 'ridewithgps';
	}

	public function label(): string {
		return __( 'Ride With GPS', 'outpost' );
	}

	public function authorize_url(): string {
		return 'https://ridewithgps.com/oauth/authorize';
	}

	public function token_url(): string {
		return 'https://ridewithgps.com/oauth/token.json';
	}

	public function revocation_endpoint(): ?string {
		// RWG does not currently expose an RFC 7009 revocation endpoint.
		// Documented in docs/adapters/ridewithgps.md.
		return null;
	}

	/**
	 * @return string[]
	 */
	public function scopes(): array {
		return array( 'read' );
	}

	public function client_id(): string {
		if ( defined( 'OUTPOST_RIDEWITHGPS_CLIENT_ID' ) ) {
			return (string) constant( 'OUTPOST_RIDEWITHGPS_CLIENT_ID' );
		}
		return '';
	}

	public function client_secret(): string {
		if ( defined( 'OUTPOST_RIDEWITHGPS_CLIENT_SECRET' ) ) {
			return (string) constant( 'OUTPOST_RIDEWITHGPS_CLIENT_SECRET' );
		}
		return '';
	}

	/**
	 * Verify the stored connection by hitting users/current.json.
	 *
	 * @since 0.1.72
	 *
	 * @param int $user_id User id.
	 * @return array<string,mixed>|\WP_Error
	 * @phpstan-ignore return.unusedType (parent allows WP_Error; RWG always returns the structured shape)
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
		// RWG's response wraps the user object under `user`. Tolerate
		// either nested or flat shapes — the API has had both at various
		// revisions.
		$user = is_array( $decoded ) && isset( $decoded['user'] ) && is_array( $decoded['user'] )
			? $decoded['user']
			: ( is_array( $decoded ) ? $decoded : array() );
		return array(
			'ok'   => true,
			'name' => isset( $user['name'] ) ? (string) $user['name'] : '',
			'id'   => isset( $user['id'] ) && is_numeric( $user['id'] ) ? (int) $user['id'] : 0,
		);
	}
}
