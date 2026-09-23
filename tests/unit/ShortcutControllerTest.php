<?php
/**
 * Outpost_Shortcut_Controller unit tests — the iOS Shortcut JSON payload
 * must carry a shared link to the composer byte-for-byte.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost\Tests\Helpers\CoreSanitizerMocks;
use Outpost_Shortcut_Controller;
use Outpost_Source_Detector;
use Outpost_Source_Registry;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ShortcutControllerTest extends TestCase {

	/** @var array<int, array{0:string,1:int}> */
	private array $redirects = array();

	/**
	 * Backs the `current_user_can`/`wp_verify_nonce` mocks via a single
	 * `andReturnUsing` closure each, so a test can flip the outcome after
	 * setUp without a second, competing `WP_Mock::userFunction()` call
	 * (WP_Mock/Mockery resolves same-function expectations in registration
	 * order, so a later unconstrained override never wins over setUp's).
	 */
	private bool $can_edit_posts = true;

	private bool $nonce_is_valid = true;

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Source_Registry::reset_for_tests();
		Outpost_Source_Detector::reset_cache_for_tests();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->redirects           = array();
		$this->can_edit_posts      = true;
		$this->nonce_is_valid      = true;
		Outpost_Shortcut_Controller::set_redirect_callback_for_tests(
			function ( string $url, int $status ): void {
				$this->redirects[] = array( $url, $status );
			}
		);

		CoreSanitizerMocks::register();
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::userFunction( 'current_user_can' )->andReturnUsing(
			function ( $capability ) {
				$this->assertSame( 'edit_posts', $capability, 'is_authenticated() must check the edit_posts capability.' );
				return $this->can_edit_posts;
			}
		);
		WP_Mock::userFunction( 'wp_verify_nonce' )->andReturnUsing(
			function ( $nonce, $action ) {
				$this->assertSame( 'outpost_shortcut', $action, 'is_authenticated() must verify the outpost_shortcut nonce action.' );
				return $this->nonce_is_valid;
			}
		);
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		WP_Mock::userFunction( 'set_transient' )->andReturn( true );
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://site.test' );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( static fn( $url ) => parse_url( $url ) );
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( static fn( $hook, $value ) => $value );
	}

	public function tearDown(): void {
		Outpost_Shortcut_Controller::set_redirect_callback_for_tests( null );
		Outpost_Shortcut_Controller::set_payload_source_for_tests( null );
		Outpost_Source_Registry::reset_for_tests();
		Outpost_Source_Detector::reset_cache_for_tests();
		unset( $_SERVER['REQUEST_METHOD'] );
		WP_Mock::tearDown();
	}

	/**
	 * @param array<string, mixed> $payload Shortcut JSON body.
	 */
	private function post_json( array $payload ): void {
		$body = (string) json_encode( $payload );
		Outpost_Shortcut_Controller::set_payload_source_for_tests( static fn(): string => $body );
		Outpost_Shortcut_Controller::handle_request();
	}

	private function redirected_url_param(): ?string {
		$this->assertCount( 1, $this->redirects, 'Expected exactly one redirect.' );
		$this->assertSame( 303, $this->redirects[0][1] );
		parse_str( (string) parse_url( $this->redirects[0][0], PHP_URL_QUERY ), $query );
		return isset( $query['url'] ) && is_string( $query['url'] ) ? $query['url'] : null;
	}

	/**
	 * @return array<string, array{0:array<string,string>, 1:string}> JSON body, URL the composer must receive
	 */
	public function encoded_link_payloads(): array {
		return array(
			'url: bare link'                       => array(
				array( 'url' => 'https://example.com/a%20b/caf%C3%A9?q=hello%20world&x=1' ),
				'https://example.com/a%20b/caf%C3%A9?q=hello%20world&x=1',
			),
			// Shortcut authors wire Shortcut Input into `url`, so Kindle and
			// Books shares land a quote plus the link there.
			'url: text blob with an encoded link'  => array(
				array( 'url' => "\"A good line.\"\n\n— Some Book\nhttps://example.com/a%20b/caf%C3%A9" ),
				'https://example.com/a%20b/caf%C3%A9',
			),
			'shared_text: link inside a sentence'  => array(
				array( 'shared_text' => 'Check this out https://example.com/a%20b/caf%C3%A9?q=hello%20world' ),
				'https://example.com/a%20b/caf%C3%A9?q=hello%20world',
			),
			'shared_text: unclosed less-than first' => array(
				array( 'shared_text' => 'I <3 this https://example.com/a%20b?x=1&y=2' ),
				'https://example.com/a%20b?x=1&y=2',
			),
			'title: bare link'                     => array(
				array( 'title' => 'https://example.com/caf%C3%A9' ),
				'https://example.com/caf%C3%A9',
			),
		);
	}

	/**
	 * @dataProvider encoded_link_payloads
	 * @param array<string,string> $payload  Shortcut JSON body.
	 * @param string               $expected URL the composer must receive.
	 */
	public function test_shared_link_reaches_the_composer_byte_for_byte( array $payload, string $expected ): void {
		$this->post_json( $payload );

		$this->assertSame( $expected, $this->redirected_url_param() );
	}

	/**
	 * @return array<string, array{0:array<string,mixed>}>
	 */
	public function non_string_payloads(): array {
		return array(
			'array url'         => array( array( 'url' => array( 'https://example.com/a%20b' ) ) ),
			'array shared_text' => array( array( 'shared_text' => array( 'https://example.com/a%20b' ) ) ),
			'array title'       => array( array( 'title' => array( 'https://example.com/a%20b' ) ) ),
			'every field'       => array(
				array(
					'url'         => array( 'https://example.com/a%20b' ),
					'shared_text' => array( 'x' => 'https://example.com/a%20b' ),
					'title'       => 12345,
				),
			),
			'object and null'   => array(
				array(
					'url'         => array( 'nested' => array( 'deep' ) ),
					'shared_text' => null,
				),
			),
		);
	}

	/**
	 * wp_kses() and esc_url_raw() are a fatal TypeError on an array; the
	 * CoreSanitizerMocks models throw the same way, so a missing guard fails
	 * here instead of on a live site.
	 *
	 * @dataProvider non_string_payloads
	 * @param array<string,mixed> $payload Shortcut JSON body.
	 */
	public function test_non_string_fields_carry_no_link( array $payload ): void {
		$this->post_json( $payload );

		$this->assertSame( array(), $this->redirects, 'A non-string field is not share data.' );
	}

	public function test_a_valid_field_still_routes_when_a_sibling_field_is_an_array(): void {
		$this->post_json(
			array(
				'url'         => array( 'junk' ),
				'shared_text' => 'Read https://example.com/a%20b',
			)
		);

		$this->assertSame( 'https://example.com/a%20b', $this->redirected_url_param() );
	}

	// =====================================================================
	// H7: cookie route requires edit_posts + a valid outpost_shortcut nonce
	// =====================================================================

	public function test_cookie_post_without_nonce_is_blocked(): void {
		$this->nonce_is_valid = false;

		$this->post_json( array( 'url' => 'https://example.com/a' ) );

		$this->assertSame( array(), $this->redirects, 'A missing/invalid nonce must block dispatch.' );
	}

	public function test_cookie_post_without_edit_posts_capability_is_blocked(): void {
		$this->can_edit_posts = false;

		$this->post_json( array( 'url' => 'https://example.com/a' ) );

		$this->assertSame( array(), $this->redirects, 'A user lacking edit_posts must be blocked even with cookies.' );
	}

	public function test_cookie_post_with_capability_and_nonce_still_routes(): void {
		$this->post_json( array( 'url' => 'https://example.com/a' ) );

		$this->assertSame( 'https://example.com/a', $this->redirected_url_param() );
	}
}
