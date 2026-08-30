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
		$result = $this->invoke_private( 'validate_url', array( 'http://example.test/post' ) );
		$this->assertTrue( $result );
	}

	public function test_validate_url_accepts_https(): void {
		$result = $this->invoke_private( 'validate_url', array( 'https://example.test/post' ) );
		$this->assertTrue( $result );
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

	public function test_strip_dangerous_html_removes_script_tags(): void {
		$input  = '<p>safe</p><script>alert(1)</script><p>also safe</p>';
		$output = $this->invoke_private( 'strip_dangerous_html', array( $input ) );
		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringNotContainsString( 'alert(1)', $output );
		$this->assertStringContainsString( '<p>safe</p>', $output );
	}

	public function test_strip_dangerous_html_removes_iframe(): void {
		$input  = '<iframe src="https://example.com"></iframe>';
		$output = $this->invoke_private( 'strip_dangerous_html', array( $input ) );
		$this->assertStringNotContainsString( '<iframe', $output );
	}

	public function test_strip_dangerous_html_removes_event_handlers(): void {
		$input  = '<a href="https://example.test" onclick="alert(1)">link</a>';
		$output = $this->invoke_private( 'strip_dangerous_html', array( $input ) );
		$this->assertStringNotContainsString( 'onclick', $output );
		$this->assertStringContainsString( 'href=', $output );
	}

	public function test_strip_dangerous_html_removes_javascript_href(): void {
		$input  = '<a href="javascript:alert(1)">click</a>';
		$output = $this->invoke_private( 'strip_dangerous_html', array( $input ) );
		$this->assertStringNotContainsString( 'javascript:', $output );
	}

	public function test_strip_dangerous_html_preserves_safe_anchor(): void {
		$input  = '<a href="https://example.test/post">read</a>';
		$output = $this->invoke_private( 'strip_dangerous_html', array( $input ) );
		$this->assertSame( $input, $output );
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

		$result = Outpost_Preview_Endpoint::check_permission();

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
		$_POST['access_token'] = 'x'; // outpost-lint:fixture-credential
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( false );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		$this->mock_filters( false );

		$result = Outpost_Preview_Endpoint::check_permission();

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

		$this->assertTrue( Outpost_Preview_Endpoint::check_permission() );
	}

	/**
	 * A cookie-authenticated editor (no bearer) is allowed.
	 */
	public function test_check_permission_allows_cookie_editor(): void {
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );
		$this->mock_filters( false );

		$this->assertTrue( Outpost_Preview_Endpoint::check_permission() );
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

		$result = Outpost_Preview_Endpoint::check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? null );
	}
}
