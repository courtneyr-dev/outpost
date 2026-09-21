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

use Outpost\Tests\Helpers\CoreSanitizerMocks;
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
		CoreSanitizerMocks::register();

		Outpost_Share_Target_Controller::handle_request();

		$this->assertCount( 1, $this->redirects );
		$this->assertSame( 303, $this->redirects[0][1] );
		$this->assertStringStartsWith( '/post/?mode=note', $this->redirects[0][0] );
	}

	/**
	 * Core sanitizers modeled on recorded WordPress 7.1 output (see
	 * CoreSanitizerMocks), plus the functions dispatch needs.
	 */
	private function mock_core_sanitizers(): void {
		CoreSanitizerMocks::register();
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://site.test' );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( static fn( $url ) => parse_url( $url ) );
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( static fn( $hook, $value ) => $value );
	}

	/**
	 * The `url=` value the composer redirect carries, decoded once — what
	 * the PWA reads from its query string.
	 */
	private function redirected_url_param(): ?string {
		$this->assertCount( 1, $this->redirects, 'Expected exactly one redirect.' );
		$this->assertSame( 303, $this->redirects[0][1] );
		parse_str( (string) parse_url( $this->redirects[0][0], PHP_URL_QUERY ), $query );
		return isset( $query['url'] ) && is_string( $query['url'] ) ? $query['url'] : null;
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

	/**
	 * @return array<string, array{0:string, 1:string, 2:string}> field, raw value, URL the composer must receive
	 */
	public function encoded_link_shares(): array {
		return array(
			// Android share sheets put the link in `text`, often mid-sentence.
			'text: link inside a sentence'       => array( 'text', 'Check this out https://example.com/a%20b/caf%C3%A9?q=hello%20world', 'https://example.com/a%20b/caf%C3%A9?q=hello%20world' ),
			'text: bare link'                    => array( 'text', 'https://example.com/a%20b/caf%C3%A9', 'https://example.com/a%20b/caf%C3%A9' ),
			'text: query string ampersand'       => array( 'text', 'Look https://example.com/s?a=1&b=hello%20world now', 'https://example.com/s?a=1&b=hello%20world' ),
			'text: unclosed less-than first'     => array( 'text', 'I <3 this https://example.com/a%20b?x=1&y=2', 'https://example.com/a%20b?x=1&y=2' ),
			'text: tags around the link'         => array( 'text', '<p>Read <b>this</b> https://example.com/a%20b</p>', 'https://example.com/a%20b' ),
			'text: control characters'           => array( 'text', "Read\x00 this\x1b\r\nhttps://example.com/a%20b", 'https://example.com/a%20b' ),
			'title: bare link'                   => array( 'title', 'https://example.com/caf%C3%A9', 'https://example.com/caf%C3%A9' ),
			// iOS apps put a quote plus the link in the url field.
			'url: text blob with an encoded link' => array( 'url', "A good line.\n\nhttps://example.com/a%20b/caf%C3%A9", 'https://example.com/a%20b/caf%C3%A9' ),
		);
	}

	/**
	 * @dataProvider encoded_link_shares
	 */
	public function test_posted_link_reaches_the_composer_byte_for_byte( string $field, string $raw, string $expected ): void {
		$_POST = array( $field => $raw );
		$this->mock_core_sanitizers();

		Outpost_Share_Target_Controller::handle_request();

		$this->assertSame( $expected, $this->redirected_url_param() );
	}

	/**
	 * Level 1 (GET) shape: /post/share-target?text=...
	 *
	 * @dataProvider encoded_link_shares
	 */
	public function test_query_string_link_reaches_the_composer_byte_for_byte( string $field, string $raw, string $expected ): void {
		$_GET = array( $field => $raw );
		$this->mock_core_sanitizers();

		Outpost_Share_Target_Controller::handle_request();

		$this->assertSame( $expected, $this->redirected_url_param() );
	}

	public function test_text_only_share_prefills_clean_text_and_keeps_percent_sequences(): void {
		$_POST = array(
			'title' => "<b>Sale</b>\x07 notes",
			'text'  => "Code SAVE%2B20 is <i>live</i>\r\ntoday",
		);
		$this->mock_core_sanitizers();

		Outpost_Share_Target_Controller::handle_request();

		$this->assertCount( 1, $this->redirects );
		parse_str( (string) parse_url( $this->redirects[0][0], PHP_URL_QUERY ), $query );
		$this->assertSame( 'note', $query['mode'] ?? null );
		$this->assertSame( 'Sale notes', $query['title'] ?? null );
		$this->assertSame( 'Code SAVE%2B20 is live today', $query['text'] ?? null );
	}

	public function test_invalid_utf8_text_carries_no_share_data(): void {
		$_POST = array( 'text' => "bad \xC3\x28 https://example.com/a%20b" );
		$this->mock_core_sanitizers();

		Outpost_Share_Target_Controller::handle_request();

		$this->assertSame( array(), $this->redirects );
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
