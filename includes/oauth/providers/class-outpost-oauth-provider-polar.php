<?php
/**
 * Outpost_OAuth_Provider_Polar (G11c).
 *
 * Polar Flow OAuth 2.0 provider via Polar AccessLink v3. Reads from
 * AccessLink for the connected user's training, sleep, and recovery
 * data. Future PRs add the source class.
 *
 * - Authorize URL: https://flow.polar.com/oauth2/authorization
 * - Token URL:     https://polarremote.com/v2/oauth2/token
 *                  (different host from authorize — Polar-specific)
 * - Scopes:        accesslink.read_all
 *                  (single scope covering all read access; AccessLink
 *                  doesn't subdivide)
 * - API base:      https://www.polaraccesslink.com/v3/
 * - Revocation:    https://polarremote.com/v2/oauth2/revoke (RFC 7009)
 * - Token shape:   standard OAuth 2.0; tokens are long-lived but support
 *                  refresh.
 *
 * THE ACCESSLINK "REGISTER USER WITH APP" QUIRK
 *
 * After OAuth code exchange completes, AccessLink requires an
 * additional `POST /v3/users` call with the access token before any
 * data API call works. Without that registration step, every data
 * endpoint returns 404 "user not registered with app".
 *
 * This is an AccessLink-specific dance, not standard OAuth 2.0. The
 * provider hooks `after_token_exchange()` to fire the registration
 * call after standard OAuth completes:
 *
 * - 200 / 201 → user registered, success.
 * - 409 → user already registered (idempotent retry; treat as success).
 * - 4xx other than 409 → log debug warning, leave creds in place; user
 *   can retry registration later via the verify endpoint.
 *
 * On 404 from a subsequent verify call (user not registered — this
 * can happen if the after_token_exchange call failed and was never
 * retried), verify_connection() automatically retries the
 * registration. If the retry succeeds, verify returns ok; if it
 * fails again, verify returns user_not_registered_with_app.
 *
 * NO MEMBERSHIP GATE
 *
 * Polar does NOT gate API access by subscription beyond OAuth
 * validity. A lapsed Polar Flow subscription still permits AccessLink
 * reads — handled by the existing token-rejection path on 401.
 *
 * Client credentials resolved from constants:
 *   OUTPOST_POLAR_CLIENT_ID
 *   OUTPOST_POLAR_CLIENT_SECRET
 *
 * @package Outpost
 * @since   0.1.76
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_OAuth_Provider_Polar extends Outpost_OAuth_Provider_Base {

	private const REGISTER_USER_ENDPOINT = 'https://www.polaraccesslink.com/v3/users';

	private const VERIFY_ENDPOINT_BASE = 'https://www.polaraccesslink.com/v3/users/';

	private const REGISTRATION_TIMEOUT = 5;

	private const VERIFY_TIMEOUT = 5;

	public function id(): string {
		return 'polar';
	}

	public function label(): string {
		return __( 'Polar Flow', 'outpost-mobile-publishing' );
	}

	public function authorize_url(): string {
		return 'https://flow.polar.com/oauth2/authorization';
	}

	public function token_url(): string {
		return 'https://polarremote.com/v2/oauth2/token';
	}

	/**
	 * @phpstan-ignore return.unusedType (parent allows null for providers like WHOOP/RWG; Polar exposes RFC 7009)
	 */
	public function revocation_endpoint(): ?string {
		// Polar exposes RFC 7009 revocation; the inherited disconnect()
		// path POSTs token + client_id + client_secret here.
		return 'https://polarremote.com/v2/oauth2/revoke';
	}

	/**
	 * @return string[]
	 */
	public function scopes(): array {
		return array( 'accesslink.read_all' );
	}

	public function client_id(): string {
		if ( defined( 'OUTPOST_POLAR_CLIENT_ID' ) ) {
			return (string) constant( 'OUTPOST_POLAR_CLIENT_ID' );
		}
		return '';
	}

	public function client_secret(): string {
		if ( defined( 'OUTPOST_POLAR_CLIENT_SECRET' ) ) {
			return (string) constant( 'OUTPOST_POLAR_CLIENT_SECRET' );
		}
		return '';
	}

	/**
	 * Register the user with the app per AccessLink's post-exchange
	 * requirement. Failures don't abort the connect flow — credentials
	 * are already stored. The user can retry via the verify endpoint,
	 * which auto-registers on 404.
	 *
	 * Polar's `member-id` field accepts any string the app chooses;
	 * Outpost uses the WP user_id so the registration is keyed to the
	 * same user the credentials are stored under.
	 *
	 * @since 0.1.76
	 *
	 * @param int                 $user_id User id.
	 * @param array<string,mixed> $creds   Freshly-shaped credentials.
	 */
	public function after_token_exchange( int $user_id, array $creds ): void {
		$access_token = isset( $creds['access_token'] ) ? (string) $creds['access_token'] : '';
		if ( '' === $access_token ) {
			return;
		}
		$response = self::register_user_with_app( $access_token, $user_id );
		if ( is_wp_error( $response ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			/* phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log */
			error_log( sprintf( 'Outpost Polar after_token_exchange registration failed: %s', $response->get_error_message() ) );
		}
	}

	/**
	 * Verify the stored connection by hitting `/v3/users/{member-id}`.
	 * On 404 (user not registered with app — common when
	 * after_token_exchange failed silently), automatically retry
	 * registration. On retry success, return ok; on retry failure,
	 * return user_not_registered_with_app so the UI can prompt the
	 * user to reconnect.
	 *
	 * @since 0.1.76
	 *
	 * @param int $user_id User id.
	 * @return array<string,mixed>|\WP_Error
	 * @phpstan-ignore return.unusedType (parent allows WP_Error; Polar always returns the structured shape)
	 */
	public function verify_connection( int $user_id ) {
		$creds = Outpost_Credentials_Store::get( $this->id(), $user_id );
		if ( ! is_array( $creds ) || empty( $creds['access_token'] ) ) {
			return array(
				'ok'     => false,
				'reason' => 'no_credentials',
			);
		}
		$result = self::verify_against_polar( (string) $creds['access_token'], $user_id );
		// 404 → user not registered. Try registration once and re-verify.
		if ( 'user_not_registered_with_app' === ( $result['reason'] ?? '' ) ) {
			$register = self::register_user_with_app( (string) $creds['access_token'], $user_id );
			if ( ! is_wp_error( $register ) ) {
				$result = self::verify_against_polar( (string) $creds['access_token'], $user_id );
			}
		}
		return $result;
	}

	/**
	 * Hit `GET /v3/users/{member-id}` and project the result.
	 *
	 * @param string $access_token Bearer token.
	 * @param int    $user_id      User id used as Polar member-id.
	 * @return array<string,mixed>
	 */
	private static function verify_against_polar( string $access_token, int $user_id ): array {
		$response = wp_remote_get(
			self::VERIFY_ENDPOINT_BASE . (string) $user_id,
			array(
				'timeout' => self::VERIFY_TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
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
		if ( 404 === $status ) {
			return array(
				'ok'     => false,
				'reason' => 'user_not_registered_with_app',
			);
		}
		if ( 401 === $status ) {
			return array(
				'ok'     => false,
				'reason' => 'auth_failed',
				'status' => $status,
			);
		}
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
			'first_name' => isset( $decoded['first-name'] ) ? (string) $decoded['first-name'] : ( isset( $decoded['first_name'] ) ? (string) $decoded['first_name'] : '' ),
			'member_id'  => isset( $decoded['member-id'] ) ? (string) $decoded['member-id'] : ( isset( $decoded['member_id'] ) ? (string) $decoded['member_id'] : '' ),
		);
	}

	/**
	 * POST /v3/users — register the user with the app. 200/201 + 409
	 * (already registered) treated as success; 4xx other than 409
	 * returned as WP_Error. Network failures returned as WP_Error.
	 *
	 * @param string $access_token Bearer token.
	 * @param int    $user_id      User id used as Polar member-id.
	 * @return true|\WP_Error
	 */
	private static function register_user_with_app( string $access_token, int $user_id ) {
		$response = wp_remote_post(
			self::REGISTER_USER_ENDPOINT,
			array(
				'timeout' => self::REGISTRATION_TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => (string) wp_json_encode( array( 'member-id' => (string) $user_id ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $status || 201 === $status || 409 === $status ) {
			return true;
		}
		return new \WP_Error(
			'outpost_polar_registration_failed',
			/* translators: %d: HTTP status */
			sprintf( __( 'Polar AccessLink user registration failed (HTTP %d).', 'outpost-mobile-publishing' ), $status ),
			array( 'status' => $status )
		);
	}
}
