<?php
/**
 * Outpost_Share_Target_Controller unit tests — the photo-share fallback.
 *
 * A Web Share Target Level 2 POST that carries only files normally never
 * reaches PHP: the service worker parks the photos and redirects. When it
 * does reach PHP (no controlling worker yet), the controller must still
 * land the composer on the Photo tab, and must never touch the upload.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Share_Target_Controller;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ShareTargetControllerTest extends TestCase {

	/** @var array<int, array{0:string,1:int}> */
	private array $redirects = array();

	public function setUp(): void {
		WP_Mock::setUp();
		$_GET   = array();
		$_POST  = array();
		$_FILES = array();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->redirects           = array();
		Outpost_Share_Target_Controller::set_redirect_callback_for_tests(
			function ( string $url, int $status ): void {
				$this->redirects[] = array( $url, $status );
			}
		);
	}

	public function tearDown(): void {
		Outpost_Share_Target_Controller::set_redirect_callback_for_tests( null );
		$_GET   = array();
		$_POST  = array();
		$_FILES = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		WP_Mock::tearDown();
	}

	public function test_photo_only_share_lands_on_the_photo_tab(): void {
		$_FILES = array(
			'photos' => array(
				'name'     => 'IMG_0001.jpg',
				'type'     => 'image/jpeg',
				'tmp_name' => '/tmp/php-upload-test',
				'error'    => 0,
				'size'     => 4096,
			),
		);
		// No preview warm-up, no upload handling: the file is never read.
		WP_Mock::userFunction( 'set_transient' )->never();
		WP_Mock::userFunction( 'media_handle_upload' )->never();
		WP_Mock::userFunction( 'wp_handle_upload' )->never();

		Outpost_Share_Target_Controller::handle_request();

		$this->assertSame( array( array( '/post/?mode=photo', 303 ) ), $this->redirects );
	}

	public function test_direct_navigation_without_share_data_falls_through_to_the_shell(): void {
		Outpost_Share_Target_Controller::handle_request();

		$this->assertSame( array(), $this->redirects, 'No share data means no redirect — the caller renders the shell.' );
	}

	public function test_text_share_with_a_photo_still_routes_by_the_text(): void {
		// A photo plus a caption that reached PHP: the text is the only
		// content PHP can carry forward, so the Note route wins, as before.
		$_POST  = array( 'text' => 'Sunset over the ridge' );
		$_FILES = array(
			'photos' => array(
				'name'     => 'IMG_0002.jpg',
				'type'     => 'image/jpeg',
				'tmp_name' => '/tmp/php-upload-test',
				'error'    => 0,
				'size'     => 4096,
			),
		);
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $value ) => $value );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $value ) => trim( (string) $value ) );

		Outpost_Share_Target_Controller::handle_request();

		$this->assertCount( 1, $this->redirects );
		$this->assertSame( 303, $this->redirects[0][1] );
		$this->assertStringStartsWith( '/post/?mode=note', $this->redirects[0][0] );
	}

	/**
	 * Mock the two sanitizers with core's real behavior on the inputs these
	 * tests use: sanitize_text_field() strips %XX octets and non-strings;
	 * esc_url_raw() keeps an http(s) URL intact and empties anything else.
	 */
	private function mock_core_sanitizers(): void {
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $value ) => $value );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static function ( $value ): string {
				if ( ! is_string( $value ) ) {
					return '';
				}
				return trim( (string) preg_replace( '/%[a-f0-9]{2}/i', '', $value ) );
			}
		);
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing(
			static function ( $value ): string {
				// Core's esc_url() calls ltrim() on its argument: an array is a
				// fatal TypeError, not an empty string.
				if ( ! is_string( $value ) ) {
					throw new \TypeError( 'ltrim(): Argument #1 ($string) must be of type string, array given' );
				}
				return 1 === preg_match( '#^https?://\S+$#i', $value ) ? $value : '';
			}
		);
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://site.test' );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( static fn( $url ) => parse_url( $url ) );
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( static fn( $hook, $value ) => $value );
	}

	public function test_shared_url_keeps_its_percent_encoding(): void {
		$_POST = array( 'url' => 'https://example.com/a%20b/caf%C3%A9?q=hello%20world' );
		$this->mock_core_sanitizers();

		Outpost_Share_Target_Controller::handle_request();

		$this->assertCount( 1, $this->redirects );
		$this->assertStringContainsString(
			rawurlencode( 'https://example.com/a%20b/caf%C3%A9?q=hello%20world' ),
			$this->redirects[0][0],
			'The shared URL must reach the composer byte-for-byte; sanitize_text_field() strips %XX octets.'
		);
	}

	public function test_url_field_holding_a_text_blob_still_yields_its_link(): void {
		// iOS apps put a quote plus the link in the url field.
		$_POST = array( 'url' => 'A good line. https://example.com/page' );
		$this->mock_core_sanitizers();

		Outpost_Share_Target_Controller::handle_request();

		$this->assertCount( 1, $this->redirects );
		$this->assertStringContainsString( rawurlencode( 'https://example.com/page' ), $this->redirects[0][0] );
	}

	public function test_array_valued_fields_are_dropped(): void {
		$_POST = array(
			'url'   => array( 'https://example.com/page' ),
			'text'  => array( 'x' ),
			'title' => array( 'y' ),
		);
		$this->mock_core_sanitizers();

		Outpost_Share_Target_Controller::handle_request();

		$this->assertSame( array(), $this->redirects, 'Array-valued fields carry no share data.' );
	}

	public function test_array_valued_query_string_fields_are_dropped(): void {
		// Level 1 (GET) shape: /post/share-target?url[]=...
		$_GET = array(
			'url'  => array( 'https://example.com/page' ),
			'text' => array( 'x' ),
		);
		$this->mock_core_sanitizers();

		Outpost_Share_Target_Controller::handle_request();

		$this->assertSame( array(), $this->redirects );
	}
}
