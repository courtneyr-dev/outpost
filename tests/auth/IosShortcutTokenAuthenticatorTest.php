<?php
/**
 * Unit tests for Outpost_IOS_Shortcut_Token_Authenticator (FX).
 *
 * Verifies the rest_authentication_errors filter callback:
 *
 *   1. Passthrough on no Bearer header.
 *   2. Passthrough on Bearer that doesn't resolve to a Shortcut token.
 *   3. wp_set_current_user + return true on Bearer + shortcut endpoint
 *      + valid token.
 *   4. WP_Error 401 on Bearer + non-shortcut endpoint + valid token
 *      (scope violation).
 *   5. Respect upstream WP_Error / true (auth chain already decided).
 *   6. Records first-seen on successful auth.
 *
 * @package Outpost\Tests\Auth
 */

declare(strict_types=1);

namespace Outpost\Tests\Auth;

use Outpost_IOS_Shortcut_Token;
use Outpost_IOS_Shortcut_Token_Authenticator;
use WP_Mock;

final class IosShortcutTokenAuthenticatorTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $user_meta = array();

	/** @var array<string, int> */
	private array $token_index = array();

	/** @var int|null Last user_id passed to wp_set_current_user. */
	private ?int $current_user = null;

	public function setUp(): void {
		WP_Mock::setUp();
		$this->user_meta    = array();
		$this->token_index  = array();
		$this->current_user = null;
		$_SERVER            = array();
		unset( $GLOBALS['wp'] );

		WP_Mock::userFunction( 'wp_generate_password' )->andReturnUsing(
			static fn ( int $length, bool $special, bool $extra ): string => substr(
				str_replace( array( '+', '/', '=' ), 'A', base64_encode( random_bytes( $length ) ) ),
				0,
				$length
			)
		);
		WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key, bool $single ) {
				$value = $this->user_meta[ $user_id ][ $key ] ?? '';
				return $single ? $value : array( $value );
			}
		);
		WP_Mock::userFunction( 'update_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key, $value ): bool {
				if ( Outpost_IOS_Shortcut_Token::META_KEY === $key ) {
					$this->token_index[ (string) $value ] = $user_id;
				}
				$this->user_meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'delete_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key ): bool {
				if ( Outpost_IOS_Shortcut_Token::META_KEY === $key
					&& isset( $this->user_meta[ $user_id ][ $key ] ) ) {
					unset( $this->token_index[ (string) $this->user_meta[ $user_id ][ $key ] ] );
				}
				unset( $this->user_meta[ $user_id ][ $key ] );
				return true;
			}
		);
		WP_Mock::userFunction( 'wp_set_current_user' )->andReturnUsing(
			function ( int $user_id ) {
				$this->current_user = $user_id;
			}
		);

		Outpost_IOS_Shortcut_Token::set_resolver_for_tests(
			fn ( string $presented ): ?int => $this->token_index[ $presented ] ?? null
		);
	}

	public function tearDown(): void {
		Outpost_IOS_Shortcut_Token::set_resolver_for_tests( null );
		WP_Mock::tearDown();
		$_SERVER = array();
		unset( $GLOBALS['wp'] );
	}

	/**
	 * Model a request whose REQUEST_URI and WP-resolved route agree (no decoy).
	 * Derives the resolved rest_route from the URI the way WordPress does:
	 * an explicit ?rest_route= wins, otherwise the path after /wp-json.
	 */
	private function set_request( string $request_uri, ?string $auth_header ): void {
		$resolved = $this->derive_resolved_route( $request_uri );
		$this->set_resolved_request( $resolved, $request_uri, $auth_header );
	}

	/**
	 * Model a request explicitly: the WP-resolved rest_route (what WordPress
	 * dispatches — the authoritative scope signal) plus the raw REQUEST_URI
	 * (which may carry a decoy that diverges from the resolved route).
	 */
	private function set_resolved_request( ?string $resolved_route, string $request_uri, ?string $auth_header ): void {
		$_SERVER['REQUEST_URI'] = $request_uri;
		if ( null !== $auth_header ) {
			$_SERVER['HTTP_AUTHORIZATION'] = $auth_header;
		}
		$wp             = new \stdClass();
		$wp->query_vars = array();
		if ( null !== $resolved_route ) {
			$wp->query_vars['rest_route'] = $resolved_route;
		}
		$GLOBALS['wp'] = $wp;
	}

	/** Derive the resolved rest_route from a decoy-free REQUEST_URI. */
	private function derive_resolved_route( string $request_uri ): ?string {
		$query = (string) parse_url( $request_uri, PHP_URL_QUERY );
		if ( '' !== $query ) {
			parse_str( $query, $args );
			if ( isset( $args['rest_route'] ) && '' !== $args['rest_route'] ) {
				return (string) $args['rest_route'];
			}
		}
		$path   = (string) parse_url( $request_uri, PHP_URL_PATH );
		$marker = '/wp-json';
		$pos    = strpos( $path, $marker );
		if ( false !== $pos ) {
			return substr( $path, $pos + strlen( $marker ) );
		}
		return null;
	}

	// --- passthrough cases -----------------------------------------------

	public function test_passthrough_when_no_bearer_header(): void {
		$this->set_request( '/wp-json/outpost/v1/shortcut', null );

		$result = Outpost_IOS_Shortcut_Token_Authenticator::authenticate( null );

		$this->assertNull( $result );
		$this->assertNull( $this->current_user );
	}

	public function test_passthrough_when_bearer_does_not_resolve(): void {
		// outpost-lint:fixture-credential — synthetic non-token Bearer.
		$this->set_request( '/wp-json/outpost/v1/shortcut', 'Bearer not-a-shortcut-token' );

		$result = Outpost_IOS_Shortcut_Token_Authenticator::authenticate( null );

		$this->assertNull( $result );
		$this->assertNull( $this->current_user );
	}

	public function test_passthrough_when_authorization_is_basic_not_bearer(): void {
		$this->set_request( '/wp-json/outpost/v1/shortcut', 'Basic dXNlcjpwYXNz' );

		$result = Outpost_IOS_Shortcut_Token_Authenticator::authenticate( null );

		$this->assertNull( $result );
	}

	public function test_respects_upstream_decision_when_already_set(): void {
		// outpost-lint:fixture-credential — synthetic test token.
		$token = Outpost_IOS_Shortcut_Token::regenerate( 42 );
		$this->set_request( '/wp-json/outpost/v1/shortcut', "Bearer $token" );

		$prior_decision = true;

		$result = Outpost_IOS_Shortcut_Token_Authenticator::authenticate( $prior_decision );

		$this->assertTrue( $result );
		// Did NOT set current_user — the upstream decision short-circuits.
		$this->assertNull( $this->current_user );
	}

	public function test_respects_upstream_wp_error(): void {
		$existing = new \WP_Error( 'rest_forbidden', 'denied' );

		$result = Outpost_IOS_Shortcut_Token_Authenticator::authenticate( $existing );

		$this->assertSame( $existing, $result );
	}

	// --- in-scope (shortcut endpoint) auth path -------------------------

	public function test_authenticates_valid_token_on_shortcut_endpoint(): void {
		$token = Outpost_IOS_Shortcut_Token::regenerate( 42 );
		$this->set_request( '/wp-json/outpost/v1/shortcut', "Bearer $token" );

		$result = Outpost_IOS_Shortcut_Token_Authenticator::authenticate( null );

		$this->assertTrue( $result );
		$this->assertSame( 42, $this->current_user );
	}

	public function test_authenticates_via_redirect_authorization_header(): void {
		// Some Apache configurations forward the Authorization header
		// as REDIRECT_HTTP_AUTHORIZATION when mod_rewrite is involved.
		$token = Outpost_IOS_Shortcut_Token::regenerate( 42 );
		// Model the resolved route (scope signal) but deliver the token via the
		// Apache REDIRECT_HTTP_AUTHORIZATION variant rather than HTTP_AUTHORIZATION.
		$this->set_resolved_request( '/outpost/v1/shortcut', '/wp-json/outpost/v1/shortcut', null );
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = "Bearer $token";

		$result = Outpost_IOS_Shortcut_Token_Authenticator::authenticate( null );

		$this->assertTrue( $result );
		$this->assertSame( 42, $this->current_user );
	}

	public function test_authentication_records_first_seen(): void {
		$token = Outpost_IOS_Shortcut_Token::regenerate( 42 );
		$this->set_request( '/wp-json/outpost/v1/shortcut', "Bearer $token" );

		Outpost_IOS_Shortcut_Token_Authenticator::authenticate( null );

		$this->assertNotNull( Outpost_IOS_Shortcut_Token::get_first_seen( 42 ) );
	}

	public function test_recognizes_rest_route_query_form(): void {
		// Plain-permalink installs use ?rest_route= instead of /wp-json/.
		$token = Outpost_IOS_Shortcut_Token::regenerate( 42 );
		$this->set_request( '/?rest_route=/outpost/v1/shortcut', "Bearer $token" );

		$result = Outpost_IOS_Shortcut_Token_Authenticator::authenticate( null );

		$this->assertTrue( $result );
	}

	// --- out-of-scope (any other endpoint) auth path --------------------

	public function test_rejects_valid_token_on_other_rest_endpoint(): void {
		$token = Outpost_IOS_Shortcut_Token::regenerate( 42 );
		$this->set_request( '/wp-json/outpost/v1/preview', "Bearer $token" );

		$result = Outpost_IOS_Shortcut_Token_Authenticator::authenticate( null );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'outpost_ios_shortcut_token_out_of_scope', $result->get_error_code() );
		$this->assertNull( $this->current_user );
	}

	public function test_rejects_valid_token_on_unrelated_rest_endpoint(): void {
		$token = Outpost_IOS_Shortcut_Token::regenerate( 42 );
		$this->set_request( '/wp-json/wp/v2/posts', "Bearer $token" );

		$result = Outpost_IOS_Shortcut_Token_Authenticator::authenticate( null );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'outpost_ios_shortcut_token_out_of_scope', $result->get_error_code() );
	}

	public function test_rejects_token_targeting_micropub_endpoint(): void {
		// The most security-critical out-of-scope route: Micropub /media.
		// Even a valid Shortcut token MUST NOT authenticate here.
		$token = Outpost_IOS_Shortcut_Token::regenerate( 42 );
		$this->set_request( '/wp-json/micropub/1.0/media', "Bearer $token" );

		$result = Outpost_IOS_Shortcut_Token_Authenticator::authenticate( null );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			401,
			(int) ( $result->get_error_data()['status'] ?? 0 )
		);
	}

	// --- scope-bypass regressions (parser-differential) -----------------
	//
	// WordPress routes on the resolved `rest_route` query var, which
	// $_GET/$_POST override ahead of the /wp-json/ permalink. The scope gate
	// must key on that resolved route, never a substring of REQUEST_URI —
	// otherwise a leaked (admin-issued) token authenticates arbitrary REST
	// routes by smuggling the shortcut path into a decoy. Reproduced live on
	// wp-env: both cases returned the full admin /wp/v2/users?context=edit dump
	// before the fix. Resolved route below models what WP actually dispatches.

	public function test_rejects_decoy_query_key_while_resolved_route_is_users(): void {
		// REQUEST_URI: /?rest_route=/wp/v2/users&x=rest_route=/outpost/v1/shortcut
		// WP resolves rest_route to /wp/v2/users (decoy sits under key `x`).
		$token = Outpost_IOS_Shortcut_Token::regenerate( 42 );
		$this->set_resolved_request(
			'/wp/v2/users',
			'/?rest_route=/wp/v2/users&x=rest_route=/outpost/v1/shortcut',
			"Bearer $token"
		);

		$result = Outpost_IOS_Shortcut_Token_Authenticator::authenticate( null );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'outpost_ios_shortcut_token_out_of_scope', $result->get_error_code() );
		$this->assertNull( $this->current_user, 'Must not set current user on a smuggled route.' );
	}

	public function test_rejects_rest_route_override_on_shortcut_path(): void {
		// REQUEST_URI: /wp-json/outpost/v1/shortcut?rest_route=/wp/v2/users
		// The /wp-json path says shortcut, but ?rest_route overrides — WP
		// dispatches /wp/v2/users. Substring matching the path would authorize.
		$token = Outpost_IOS_Shortcut_Token::regenerate( 42 );
		$this->set_resolved_request(
			'/wp/v2/users',
			'/wp-json/outpost/v1/shortcut?rest_route=/wp/v2/users',
			"Bearer $token"
		);

		$result = Outpost_IOS_Shortcut_Token_Authenticator::authenticate( null );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'outpost_ios_shortcut_token_out_of_scope', $result->get_error_code() );
		$this->assertNull( $this->current_user );
	}

	public function test_authenticates_when_resolved_route_is_shortcut_despite_wp_json_prefix(): void {
		// REQUEST_URI: /wp-json/wp/v2/users?rest_route=/outpost/v1/shortcut
		// ?rest_route overrides the path, so WP dispatches the shortcut route —
		// this IS a legitimate shortcut call and must authenticate.
		$token = Outpost_IOS_Shortcut_Token::regenerate( 42 );
		$this->set_resolved_request(
			'/outpost/v1/shortcut',
			'/wp-json/wp/v2/users?rest_route=/outpost/v1/shortcut',
			"Bearer $token"
		);

		$result = Outpost_IOS_Shortcut_Token_Authenticator::authenticate( null );

		$this->assertTrue( $result );
		$this->assertSame( 42, $this->current_user );
	}
}
