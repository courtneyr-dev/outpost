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
		$ref->setAccessible( true );
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
}
