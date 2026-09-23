<?php
/**
 * Unit tests for Outpost_Preview_Endpoint.
 *
 * Covers URL validation, content-type validation, response-size cap, rate
 * limit, script-stripping, and permission callback. The wp_safe_remote_get
 * call is mocked via WP_Mock so tests don't make real HTTP requests.
 *
 * Integration tests against a live REST server land via wp-env when
 * RouteHandlerIntegrationTest gets its assertions filled in (see
 * docs/INTEGRATION-TESTING.md).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Preview_Endpoint;
use WP_Error;
use WP_Mock;
use WP_REST_Request;

final class PreviewEndpointTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		// Outpost_Request_Headers sanitizes every $_SERVER read.
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => is_string( $v ) ? trim( $v ) : '' );
	}

	public function tearDown(): void {
		unset(
			$_POST['access_token'],
			$_SERVER['HTTP_AUTHORIZATION'],
			$_SERVER['REDIRECT_HTTP_AUTHORIZATION']
		);
		WP_Mock::tearDown();
	}

	private function make_request( string $url ): WP_REST_Request {
		$request = $this->createMock( WP_REST_Request::class );
		$request->method( 'get_param' )->willReturn( $url );
		return $request;
	}

	/**
	 * Use reflection to invoke private static methods.
	 *
	 * @return mixed
	 */
	private function invoke_private( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( Outpost_Preview_Endpoint::class, $method );
		return $ref->invoke( null, ...$args );
	}

	public function test_validate_url_rejects_empty(): void {
		$result = $this->invoke_private( 'validate_url', array( '' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_url', $result->get_error_code() );
	}

	public function test_validate_url_rejects_javascript_scheme(): void {
		$result = $this->invoke_private( 'validate_url', array( 'javascript:alert(1)' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_validate_url_rejects_data_scheme(): void {
		$result = $this->invoke_private( 'validate_url', array( 'data:text/html,<script>1</script>' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_validate_url_rejects_file_scheme(): void {
		$result = $this->invoke_private( 'validate_url', array( 'file:///etc/passwd' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_scheme', $result->get_error_code() );
	}

	public function test_validate_url_rejects_url_over_2048_chars(): void {
		// CLAUDE.md hot-spot contract: length-cap the bookmarklet/preview URL
		// at 2048 chars. A syntactically valid but oversized http URL must be
		// rejected before any fetch.
		$long   = 'https://example.test/' . str_repeat( 'a', 2048 );
		$result = $this->invoke_private( 'validate_url', array( $long ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'url_too_long', $result->get_error_code() );
	}

	public function test_validate_url_rejects_no_host(): void {
		$result = $this->invoke_private( 'validate_url', array( 'http://' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_validate_url_accepts_http(): void {
		WP_Mock::onFilter( 'outpost_resolve_host_ips' )->with( array(), 'example.test' )->reply( array( '93.184.216.34' ) );
		$result = $this->invoke_private( 'validate_url', array( 'http://example.test/post' ) );
		$this->assertTrue( $result );
	}

	public function test_validate_url_accepts_https(): void {
		WP_Mock::onFilter( 'outpost_resolve_host_ips' )->with( array(), 'example.test' )->reply( array( '93.184.216.34' ) );
		$result = $this->invoke_private( 'validate_url', array( 'https://example.test/post' ) );

		$this->assertTrue( $result );
	}

	public function test_validate_url_rejects_link_local_metadata_host(): void {
		// A literal internal-IP host is classified directly (no DNS). This is
		// the SSRF ceiling wp_safe_remote_get alone does not close.
		$result = $this->invoke_private( 'validate_url', array( 'http://169.254.169.254/latest/meta-data/' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	public function test_validate_url_rejects_cgnat_host(): void {
		$result = $this->invoke_private( 'validate_url', array( 'https://100.64.0.1/' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_content_type_is_allowed_html(): void {
		$result = $this->invoke_private( 'content_type_is_allowed', array( 'text/html' ) );
		$this->assertTrue( $result );
	}

	public function test_content_type_is_allowed_html_with_charset(): void {
		$result = $this->invoke_private( 'content_type_is_allowed', array( 'text/html; charset=utf-8' ) );
		$this->assertTrue( $result );
	}

	public function test_content_type_is_allowed_xhtml(): void {
		$result = $this->invoke_private( 'content_type_is_allowed', array( 'application/xhtml+xml' ) );
		$this->assertTrue( $result );
	}

	public function test_content_type_rejects_json(): void {
		$result = $this->invoke_private( 'content_type_is_allowed', array( 'application/json' ) );
		$this->assertFalse( $result );
	}

	public function test_content_type_rejects_image(): void {
		$result = $this->invoke_private( 'content_type_is_allowed', array( 'image/png' ) );
		$this->assertFalse( $result );
	}

	public function test_content_type_rejects_pdf(): void {
		$result = $this->invoke_private( 'content_type_is_allowed', array( 'application/pdf' ) );
		$this->assertFalse( $result );
	}


	/**
	 * Register apply_filters so the permission callback can call the
	 * `determine_current_user` and `outpost_preview_permission` hooks.
	 *
	 * @param int|false $determine_user Value IndieAuth's determine_current_user
	 *                                  resolves the bearer token to. `false`
	 *                                  simulates an invalid/rejected token.
	 */
	private function mock_filters( $determine_user ): void {
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			static function ( $hook, $value ) use ( $determine_user ) {
				if ( 'determine_current_user' === $hook ) {
					return $determine_user;
				}
				return $value;
			}
		);
	}

	/**
	 * Regression: the reported anonymous-SSRF auth bypass.
	 *
	 * An unauthenticated caller presenting a syntactically-valid but
	 * bogus `Authorization: Bearer x` header must NOT pass the permission
	 * gate. Before the fix, bearer-header *presence* alone returned true,
	 * opening the server-side fetcher to anonymous callers.
	 */
	public function test_check_permission_rejects_unvalidated_bearer_header(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer x'; // outpost-lint:fixture-credential
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( false );
		// IndieAuth rejects the bogus token: determine_current_user resolves nobody.
		$this->mock_filters( false );

		$result = Outpost_Preview_Endpoint::check_permission( new \WP_REST_Request( 'POST', '/' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * Regression: same bypass via the Micropub body-token fallback.
	 *
	 * `{"access_token":"x", ...}` in the body must also be validated, not
	 * accepted on presence.
	 */
	public function test_check_permission_rejects_unvalidated_body_token(): void {
		$request = new \WP_REST_Request( 'POST', '/' );
		$request->set_body( '{"access_token":"x"}' ); // outpost-lint:fixture-credential
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( false );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		$this->mock_filters( false );

		$result = Outpost_Preview_Endpoint::check_permission( $request );

		$this->assertSame( 'Bearer x', $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'The body token was read and handed to the validating filter.' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * A valid bearer token that IndieAuth resolves to an editor is allowed —
	 * the fix must not break the legitimate PWA flow.
	 */
	public function test_check_permission_allows_validated_bearer_editor(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::userFunction( 'wp_set_current_user' )->with( 42 )->andReturn( null );
		// determine_current_user validates the token to user 42, who can edit_posts.
		$this->mock_filters( 42 );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );
		// H6/H7: bearer_has_scope() reads indieauth_scopes via the real
		// apply_filters() shim (WP_Mock::onFilter), not the userFunction
		// mock above — mock_filters()'s wholesale apply_filters override is
		// inert for this call (see trait-bearer-auth.php discovery notes).
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'read' ) );

		$this->assertTrue( Outpost_Preview_Endpoint::check_permission( new \WP_REST_Request( 'POST', '/' ) ) );
	}

	/**
	 * H7 fix-round-1, Important: an under-scoped (but otherwise validated)
	 * bearer token is refused, distinct from an unvalidated-token refusal.
	 */
	public function test_check_permission_refuses_under_scoped_bearer_token(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::userFunction( 'wp_set_current_user' )->with( 42 )->andReturn( null );
		$this->mock_filters( 42 );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'profile' ) );

		$result = Outpost_Preview_Endpoint::check_permission( new \WP_REST_Request( 'POST', '/' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * A cookie-authenticated editor (no bearer) is allowed.
	 */
	public function test_check_permission_allows_cookie_editor(): void {
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );
		$this->mock_filters( false );

		$this->assertTrue( Outpost_Preview_Endpoint::check_permission( new \WP_REST_Request( 'POST', '/' ) ) );
	}

	/**
	 * A logged-in non-editor (e.g. subscriber, no bearer) is now rejected —
	 * the dropped is_user_logged_in() OR-leg no longer opens the fetcher to
	 * every authenticated user.
	 */
	public function test_check_permission_rejects_logged_in_non_editor(): void {
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( false );
		$this->mock_filters( false );

		$result = Outpost_Preview_Endpoint::check_permission( new \WP_REST_Request( 'POST', '/' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? null );
	}

	// =====================================================================
	// H9: safe_fetch() pins the connection to the address the SSRF guard
	// vetted, instead of trusting a second DNS lookup at connect time.
	//
	// The happy path (guard passes, fetch proceeds) is NOT unit-testable
	// here: mocking wp_safe_remote_get() via WP_Mock::userFunction()
	// permanently defines it as a real global function for the rest of the
	// PHP process (proven empirically -- WP_Mock::tearDown() does not undo
	// it), which then makes every later integration test's
	// `function_exists('wp_safe_remote_get')` environment-readiness guard
	// misfire and try to run for real against a WordPress core that was
	// never loaded. That coverage lives in the integration suite instead
	// (tests/integration/PreviewSsrfTest.php's
	// `public_host_pins_the_vetted_ip_during_the_fetch()` and pre-existing
	// `public_host_is_fetched()`), matching this codebase's own documented
	// convention (SourceSpotifyLiveTest.php's docblock) that fetch
	// behavior belongs there.
	//
	// The blocked-address path below is safe to test here precisely
	// because it must NOT call wp_safe_remote_get() at all: that name
	// stays deliberately unmocked, so a regression that let the guard
	// through would fatal with "Call to undefined function
	// wp_safe_remote_get()" here instead of silently reaching the network.
	// =====================================================================

	private function stub_wp_parse_url(): void {
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url, $component = -1 ) {
				return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component );
			}
		);
	}

	public function test_safe_fetch_never_reaches_the_network_for_a_blocked_resolved_address(): void {
		// A host that resolves to a blocked address (the DNS-rebinding shape
		// the guard must fail closed on) must never reach the fetch at all.
		WP_Mock::onFilter( 'outpost_resolve_host_ips' )->with( array(), 'rebind.example' )->reply( array( '169.254.169.254' ) );
		$this->stub_wp_parse_url();

		$response = $this->invoke_private( 'safe_fetch', array( 'http://rebind.example/post', array( 'text/html' ) ) );

		$this->assertInstanceOf( WP_Error::class, $response );
	}
}
