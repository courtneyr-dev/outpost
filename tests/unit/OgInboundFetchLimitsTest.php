<?php
/**
 * Outpost_Og_Inbound::fetch() caps the body and accepts only HTML (F-59).
 *
 * The method is a documented public primitive with no caller in Outpost
 * itself, so it gets the preview fetcher's size and content-type limits
 * rather than being removed.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Og_Inbound;
use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * @covers \Outpost_Og_Inbound
 */
final class OgInboundFetchLimitsTest extends TestCase {

	private const CAP = 5 * 1024 * 1024;

	/** @var array<string,mixed> */
	private array $request_args = array();

	protected function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		WP_Mock::passthruFunction( '__' );
		WP_Mock::userFunction( 'wp_http_validate_url' )->andReturnUsing( static fn ( $url ) => $url );
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		$this->request_args = array();
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	private function respond_with( string $body, string $content_type ): void {
		WP_Mock::userFunction( 'wp_safe_remote_get' )->andReturnUsing(
			function ( $url, $args ) {
				$this->request_args = $args;
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( static fn ( $v ) => $v instanceof \WP_Error );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
		WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( $content_type );
		WP_Mock::userFunction( 'wp_remote_retrieve_headers' )->andReturn( array() );
		WP_Mock::userFunction( 'set_transient' )->andReturn( true );
	}

	public function test_request_asks_the_transport_to_stop_one_byte_past_the_cap(): void {
		$this->respond_with( str_repeat( 'a', self::CAP + 1 ), 'text/html' );

		Outpost_Og_Inbound::fetch( 'https://example.com/page' );

		$this->assertSame( self::CAP + 1, $this->request_args['limit_response_size'] ?? null );
	}

	public function test_body_that_reaches_the_cap_is_refused_not_parsed(): void {
		$this->respond_with( str_repeat( 'a', self::CAP + 1 ), 'text/html; charset=utf-8' );

		$result = Outpost_Og_Inbound::fetch( 'https://example.com/page' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'outpost_og_too_large', $result->get_error_code() );
	}

	public function test_non_html_response_is_refused(): void {
		$this->respond_with( '<html><head><meta property="og:title" content="x"></head></html>', 'application/pdf' );

		$result = Outpost_Og_Inbound::fetch( 'https://example.com/file' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'outpost_og_bad_content_type', $result->get_error_code() );
	}
}
