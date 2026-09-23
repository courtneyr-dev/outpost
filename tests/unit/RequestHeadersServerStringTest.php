<?php
/**
 * Unit tests for Outpost_Request_Headers::server_string() and
 * ::authorization() — every `$_SERVER` value the plugin reads is sender
 * controlled, so each one passes through sanitize_text_field() before any
 * caller sees it.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Request_Headers;
use WP_Mock;

final class RequestHeadersServerStringTest extends \WP_Mock\Tools\TestCase {

	private const KEYS = array( 'HTTP_USER_AGENT', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_WP_NONCE' );

	public function setUp(): void {
		WP_Mock::setUp();
		foreach ( self::KEYS as $key ) {
			unset( $_SERVER[ $key ] );
		}
		unset( $_REQUEST['_wpnonce'] );
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $value ) => is_string( $value ) ? stripslashes( $value ) : $value );
	}

	public function tearDown(): void {
		foreach ( self::KEYS as $key ) {
			unset( $_SERVER[ $key ] );
		}
		unset( $_REQUEST['_wpnonce'] );
		WP_Mock::tearDown();
	}

	public function test_server_string_returns_the_sanitized_unslashed_value(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla\\\'s <script>x</script>';
		WP_Mock::userFunction( 'sanitize_text_field' )
			->once()
			->with( "Mozilla's <script>x</script>" )
			->andReturn( "Mozilla's x" );

		$this->assertSame( "Mozilla's x", Outpost_Request_Headers::server_string( 'HTTP_USER_AGENT' ) );
	}

	public function test_server_string_returns_the_fallback_without_sanitizing_when_absent(): void {
		WP_Mock::userFunction( 'sanitize_text_field' )->never();

		$this->assertSame( 'GET', Outpost_Request_Headers::server_string( 'HTTP_USER_AGENT', 'GET' ) );
	}

	public function test_authorization_header_is_sanitized(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123';
		WP_Mock::userFunction( 'sanitize_text_field' )->once()->with( 'Bearer abc123' )->andReturn( 'Bearer abc123' );

		$this->assertSame( 'Bearer abc123', Outpost_Request_Headers::authorization() );
	}

	public function test_redirect_authorization_fallback_is_sanitized(): void {
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer xyz789';
		WP_Mock::userFunction( 'sanitize_text_field' )->once()->with( 'Bearer xyz789' )->andReturn( 'Bearer xyz789' );

		$this->assertSame( 'Bearer xyz789', Outpost_Request_Headers::authorization() );
	}

	public function test_authorization_falls_back_to_getallheaders_matching_the_name_case_insensitively(): void {
		WP_Mock::userFunction( 'getallheaders' )->andReturn(
			array(
				'Host'          => 'example.test',
				'AUTHORIZATION' => 'Bearer sapi-token', // outpost-lint:fixture-credential
			)
		);
		WP_Mock::userFunction( 'sanitize_text_field' )->once()->with( 'Bearer sapi-token' )->andReturn( 'Bearer sapi-token' );

		$this->assertSame( 'Bearer sapi-token', Outpost_Request_Headers::authorization() );
	}

	public function test_authorization_is_empty_when_getallheaders_has_no_authorization(): void {
		WP_Mock::userFunction( 'getallheaders' )->andReturn( array( 'Host' => 'example.test' ) );
		WP_Mock::userFunction( 'sanitize_text_field' )->never();

		$this->assertSame( '', Outpost_Request_Headers::authorization() );
	}

	public function test_rest_nonce_prefers_the_request_parameter(): void {
		$_REQUEST['_wpnonce']         = 'abc123';
		$_SERVER['HTTP_X_WP_NONCE']   = 'header-nonce';
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $value ) => is_string( $value ) ? trim( $value ) : '' );

		// Mirrors core's rest_cookie_check_errors(): _wpnonce wins over the header.
		$this->assertSame( 'abc123', Outpost_Request_Headers::rest_nonce() );
	}

	public function test_rest_nonce_falls_back_to_the_header(): void {
		unset( $_REQUEST['_wpnonce'] );
		$_SERVER['HTTP_X_WP_NONCE'] = 'header-nonce';
		WP_Mock::userFunction( 'sanitize_text_field' )->once()->with( 'header-nonce' )->andReturn( 'header-nonce' );

		$this->assertSame( 'header-nonce', Outpost_Request_Headers::rest_nonce() );
	}

	public function test_rest_nonce_is_empty_when_no_nonce_was_sent(): void {
		unset( $_REQUEST['_wpnonce'], $_SERVER['HTTP_X_WP_NONCE'] );
		WP_Mock::userFunction( 'sanitize_text_field' )->never();

		$this->assertSame( '', Outpost_Request_Headers::rest_nonce() );
	}
}
