<?php
/**
 * Outpost_OAuth_Provider_Oura (G11a).
 *
 * Oura Ring OAuth 2.0 provider. Reads from Oura's v2 API for the
 * connected user's personal info, sleep, heart rate, and workout data.
 *
 * - Authorize URL: https://cloud.ouraring.com/oauth/authorize
 * - Token URL:     https://api.ouraring.com/oauth/token
 * - Scopes:        email personal daily heartrate workout tag session ring_configuration
 *                  (full read-only set; future write scopes added in
 *                  follow-up if/when outbound is in scope)
 * - Revocation:    https://api.ouraring.com/oauth/revoke (RFC 7009)
 * - Token shape:   standard OAuth 2.0 access_token + refresh_token +
 *                  expires_in. Tokens expire after ~24h; refresh path
 *                  inherited from the base.
 *
 * MEMBERSHIP-GATE QUIRK
 *
 * Oura's API returns HTTP 401 with a JSON body containing
 * `"detail": "expired_oura_membership"` (or a similarly-worded
 * membership-related string) when the connected user's Oura Membership
 * has lapsed for Gen3 hardware + Ring 4. This is DISTINCT from a
 * token-expired error. Membership-gate failures DO NOT clear the
 * stored credentials — the OAuth token itself is still valid; only
 * data access is gated.
 *
 * verify_connection() detects this specific error class and returns
 * `{ ok: false, reason: 'membership_required' }` so the settings UI
 * can render the membership-renewal prompt without dropping the
 * user's connection.
 *
 * Client credentials resolved from constants:
 *   OUTPOST_OURA_CLIENT_ID
 *   OUTPOST_OURA_CLIENT_SECRET
 *
 * @package Outpost
 * @since   0.1.71
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_OAuth_Provider_Oura extends Outpost_OAuth_Provider_Base {

	private const VERIFY_ENDPOINT = 'https://api.ouraring.com/v2/usercollection/personal_info';

	private const VERIFY_TIMEOUT = 5;

	public function id(): string {
		return 'oura';
	}

	public function label(): string {
		return __( 'Oura', 'outpost' );
	}

	public function authorize_url(): string {
		return 'https://cloud.ouraring.com/oauth/authorize';
	}

	public function token_url(): string {
		return 'https://api.ouraring.com/oauth/token';
	}

	/**
	 * @phpstan-ignore return.unusedType (parent allows null for providers like Notion that don't expose revoke; Oura does)
	 */
	public function revocation_endpoint(): ?string {
		return 'https://api.ouraring.com/oauth/revoke';
	}

	/**
	 * @return string[]
	 */
	public function scopes(): array {
		return array(
			'email',
			'personal',
			'daily',
			'heartrate',
			'workout',
			'tag',
			'session',
			'ring_configuration',
		);
	}

	public function client_id(): string {
		if ( defined( 'OUTPOST_OURA_CLIENT_ID' ) ) {
			return (string) constant( 'OUTPOST_OURA_CLIENT_ID' );
		}
		return '';
	}

	public function client_secret(): string {
		if ( defined( 'OUTPOST_OURA_CLIENT_SECRET' ) ) {
			return (string) constant( 'OUTPOST_OURA_CLIENT_SECRET' );
		}
		return '';
	}

	/**
	 * Verify the stored connection. Calls Oura's personal_info endpoint
	 * (the cheapest authenticated GET) and projects the result into the
	 * `{ ok: bool, ... }` shape the settings UI expects.
	 *
	 * @since 0.1.71
	 *
	 * @param int $user_id User id.
	 * @return array<string,mixed>|\WP_Error
	 * @phpstan-ignore return.unusedType (parent allows WP_Error; Oura always returns the structured shape)
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
		if ( 200 === $status ) {
			$decoded = json_decode( $body, true );
			$email   = is_array( $decoded ) && isset( $decoded['email'] ) ? (string) $decoded['email'] : '';
			return array(
				'ok'    => true,
				'email' => $email,
			);
		}
		if ( 401 === $status && self::is_membership_gate_response( $body ) ) {
			return array(
				'ok'     => false,
				'reason' => 'membership_required',
			);
		}
		return array(
			'ok'     => false,
			'reason' => 'auth_failed',
			'status' => $status,
		);
	}

	/**
	 * Detect Oura's membership-lapsed signature in a 401 response body.
	 * Oura's wording has shifted across API revisions; match any of the
	 * known phrasings rather than the exact `expired_oura_membership`
	 * string.
	 *
	 * @param string $body Raw response body.
	 * @return bool True when the body indicates a membership-gate failure.
	 */
	public static function is_membership_gate_response( string $body ): bool {
		if ( '' === $body ) {
			return false;
		}
		$lowered = strtolower( $body );
		// Known phrasings as of 2026-Q1: 'expired_oura_membership',
		// 'membership_required', 'oura membership has expired',
		// 'subscription required'. Any of these → membership gate.
		$signals = array(
			'expired_oura_membership',
			'membership_required',
			'membership has expired',
			'membership has lapsed',
			'subscription required',
			'oura membership',
		);
		foreach ( $signals as $needle ) {
			if ( false !== strpos( $lowered, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
