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

	private const KEYS = array( 'HTTP_USER_AGENT', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' );

	public function setUp(): void {
		WP_Mock::setUp();
		foreach ( self::KEYS as $key ) {
			unset( $_SERVER[ $key ] );
		}
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $value ) => is_string( $value ) ? stripslashes( $value ) : $value );
	}

	public function tearDown(): void {
		foreach ( self::KEYS as $key ) {
			unset( $_SERVER[ $key ] );
		}
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
}
