<?php
/**
 * Input legibility for share intake, settings, and notices (T10-S3-13; F-11, F-14, F-15).
 *
 * - F-14: a shared `url` is validated as a URL-shaped scalar (string,
 *   length-capped, no control bytes or HTML entities, http(s) with a host)
 *   and then esc_url_raw()'d; wp_kses() is never the URL sanitizer. A text
 *   blob in the field is still handled as text so the link inside it can
 *   be found.
 * - F-15: the affected settings and notice handlers read query values as
 *   narrow, unslashed, sanitized scalars; array-shaped values are refused
 *   without a PHP warning.
 * - F-11: the Appearance error notice no longer reflects text from the URL.
 *   It shows a message the save handler stored for this user, escaped, on
 *   the Appearance screen only. (Escaped reflection: a legibility issue,
 *   not demonstrated XSS.)
 *
 * Every test turns PHP warnings and notices into failures.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Source_Detector;
use PHPUnit\Framework\TestCase;

final class InputLegibilityTest extends TestCase {

	private int $admin_id = 0;

	/** @var array<string, mixed> */
	private array $saved_get = array();

	/** @var array<string, mixed> */
	private array $saved_post = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Source_Detector' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap.' );
		}
		$this->saved_get  = $_GET;
		$this->saved_post = $_POST;
		$this->admin_id   = (int) wp_insert_user(
			array(
				'user_login' => 's313_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 's313_' . uniqid() . '@example.test',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $this->admin_id );
		set_error_handler(
			static function ( int $errno, string $message, string $file = '', int $line = 0 ): bool {
				throw new \ErrorException( $message, 0, $errno, $file, $line );
			},
			E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED
		);
	}

	protected function tearDown(): void {
		restore_error_handler();
		$_GET  = $this->saved_get;
		$_POST = $this->saved_post;
		if ( $this->admin_id > 0 ) {
			delete_transient( 'outpost_appearance_notice_' . $this->admin_id );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->admin_id );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// ----- F-14: shared URL ---------------------------------------------

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function valid_urls(): array {
		return array(
			'https'                => array( 'https://example.org/page', 'https://example.org/page' ),
			'http'                 => array( 'http://example.org/', 'http://example.org/' ),
			'query with ampersand' => array( 'https://example.org/a?b=1&c=2', 'https://example.org/a?b=1&c=2' ),
			'encoded space kept'   => array( 'https://example.org/a%20b', 'https://example.org/a%20b' ),
			'surrounding space'    => array( "  https://example.org/x  \n", 'https://example.org/x' ),
		);
	}

	/**
	 * @dataProvider valid_urls
	 */
	public function test_a_valid_http_url_is_kept( string $input, string $expected ): void {
		$this->assertSame( $expected, Outpost_Source_Detector::sanitize_shared_url( $input ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function denied_urls(): array {
		return array(
			'named entity'           => array( 'https://example.org/a?b=1&amp;c=2' ),
			'numeric entity'         => array( 'https://example.org/&#106;avascript' ),
			'hex entity'             => array( 'https://example.org/&#x6a;' ),
			'entity-encoded scheme'  => array( 'javascript&#58;alert(1)' ),
			'control byte'           => array( "https://example.org/a\x01b" ),
			'DEL byte'               => array( "https://example.org/a\x7Fb" ),
			'overlong'               => array( 'https://example.org/' . str_repeat( 'a', 2050 ) ),
			'javascript scheme'      => array( 'javascript:alert(1)' ),
			'data scheme'            => array( 'data:text/html,<script>alert(1)</script>' ),
			'ftp scheme'             => array( 'ftp://example.org/file' ),
			'file scheme'            => array( 'file:///etc/passwd' ),
			'no host'                => array( 'http://' ),
			'triple slash'           => array( 'https:///path' ),
			'missing colon'          => array( 'http//example.org' ),
			'schemeless'             => array( 'example.org/page' ),
			'angle bracket'          => array( 'https://example.org/<script>' ),
		);
	}

	/**
	 * @dataProvider denied_urls
	 */
	public function test_a_malformed_or_unsafe_url_is_denied( string $input ): void {
		$this->assertSame( '', Outpost_Source_Detector::sanitize_shared_url( $input ) );
	}

	public function test_array_and_object_values_are_denied_without_a_warning(): void {
		$this->assertSame( '', Outpost_Source_Detector::sanitize_shared_url( array( 'https://example.org/' ) ) );
		$this->assertSame( '', Outpost_Source_Detector::sanitize_shared_url( new \stdClass() ) );
		$this->assertSame( '', Outpost_Source_Detector::sanitize_shared_url( 42 ) );
	}

	// ----- S3-13 v1.1: URL-only sanitizer, separate legacy field adapter --

	public function test_sanitize_shared_url_never_returns_prose(): void {
		$this->assertSame( '', Outpost_Source_Detector::sanitize_shared_url( '"A quote from the article" https://example.org/story' ) );
		$this->assertSame( '', Outpost_Source_Detector::sanitize_shared_url( 'just some words' ) );
	}

	public function test_the_url_field_adapter_returns_a_url_legacy_prose_or_nothing(): void {
		$this->assertSame( 'https://example.org/a%20b', Outpost_Source_Detector::sanitize_shared_url_field( 'https://example.org/a%20b' ) );
		$prose = '"A quote from the article" https://example.org/story';
		$this->assertSame( $prose, Outpost_Source_Detector::sanitize_shared_url_field( $prose ) );
		$this->assertSame( '', Outpost_Source_Detector::sanitize_shared_url_field( 'https://example.org/a"><script>alert(1)</script>' ) );
		$this->assertSame( '', Outpost_Source_Detector::sanitize_shared_url_field( 'javascript:alert(1)' ) );
		$this->assertSame( '', Outpost_Source_Detector::sanitize_shared_url_field( array( $prose ) ) );
	}

	/**
	 * @return array<string, array{0: array<string, string>, 1: ?string}>
	 */
	public static function payloads(): array {
		return array(
			'clean url field'            => array( array( 'url' => 'https://example.org/a%20b' ), 'https://example.org/a%20b' ),
			'quote plus link in url'     => array( array( 'url' => '"A quote" https://example.org/story' ), 'https://example.org/story' ),
			'link in text'               => array( array( 'text' => 'Read this https://example.org/story today' ), 'https://example.org/story' ),
			'entity link in url prose'   => array( array( 'url' => 'see https://example.org/?a=1&amp;b=2' ), null ),
			'entity link in text'        => array( array( 'text' => 'see https://example.org/?a=1&amp;b=2' ), null ),
			'entity link as whole text'  => array( array( 'text' => 'https://example.org/&#106;avascript' ), null ),
			'entity link as title'       => array( array( 'title' => 'https://example.org/&#106;' ), null ),
			'non-http only'              => array( array( 'text' => 'ftp://example.org/file' ), null ),
			'entity runs on in prose'    => array( array( 'text' => 'see https://example.org/&#106;avascript now' ), null ),
			'markup appended in prose'   => array( array( 'text' => 'look https://example.org/a"><script>x</script>' ), null ),
			'quoted link in prose'       => array( array( 'text' => 'He said "https://example.org/story" today' ), 'https://example.org/story' ),
			'sentence-ending link'       => array( array( 'text' => 'Read https://example.org/story.' ), 'https://example.org/story' ),
		);
	}

	/**
	 * Every candidate extract_url_from_payload() returns has passed the
	 * URL-only validator, wherever in the payload it was found.
	 *
	 * @dataProvider payloads
	 *
	 * @param array<string, string> $payload  Payload fields.
	 * @param ?string               $expected The URL that may be used, or null.
	 */
	public function test_no_extracted_candidate_bypasses_the_url_only_validator( array $payload, ?string $expected ): void {
		$found = Outpost_Source_Detector::extract_url_from_payload( $payload + array( 'url' => '', 'text' => '', 'title' => '' ) );
		$this->assertSame( $expected, $found );
		if ( null !== $found ) {
			$this->assertSame( $found, Outpost_Source_Detector::sanitize_shared_url( $found ) );
		}
	}

	public function test_share_target_keeps_quote_plus_link_intake(): void {
		$redirects = array();
		\Outpost_Share_Target_Controller::set_redirect_callback_for_tests(
			static function ( string $url ) use ( &$redirects ): void {
				$redirects[] = $url;
			}
		);
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array( 'url' => '"A quote from the article" https://example.org/story' );
		try {
			\Outpost_Share_Target_Controller::handle_request();
		} finally {
			\Outpost_Share_Target_Controller::set_redirect_callback_for_tests( null );
		}
		$this->assertCount( 1, $redirects );
		parse_str( (string) wp_parse_url( $redirects[0], PHP_URL_QUERY ), $query );
		$this->assertContains( 'https://example.org/story', array_values( array_filter( $query, 'is_string' ) ) );
	}

	/** Run the Shortcut handler with its params sanitized by its registered args. */
	private function shortcut( string $url ): \WP_REST_Response|\WP_Error {
		$routes  = rest_get_server()->get_routes();
		$handler = $routes['/outpost/v1/shortcut'][0];
		$request = new \WP_REST_Request( 'POST', '/outpost/v1/shortcut' );
		$request->set_attributes( array( 'args' => $handler['args'] ) );
		$request->set_param( 'url', $url );
		$request->sanitize_params();
		return \Outpost_IOS_Shortcut_REST_Controller::handle_request( $request );
	}

	public function test_the_shortcut_sanitizes_its_url_with_the_field_adapter(): void {
		$routes = rest_get_server()->get_routes();
		$this->assertSame(
			array( 'Outpost_Source_Detector', 'sanitize_shared_url_field' ),
			$routes['/outpost/v1/shortcut'][0]['args']['url']['sanitize_callback']
		);
	}

	public function test_the_shortcut_keeps_quote_plus_link_intake(): void {
		$response = $this->shortcut( '"A quote from the article" https://example.org/story' );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		parse_str( (string) wp_parse_url( (string) $response->get_data()['redirect_url'], PHP_URL_QUERY ), $query );
		$this->assertContains( 'https://example.org/story', array_values( array_filter( $query, 'is_string' ) ) );
	}

	public function test_the_shortcut_refuses_prose_whose_only_link_fails_validation(): void {
		$response = $this->shortcut( 'see https://example.org/&#106;avascript now' );
		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'outpost_ios_shortcut_no_url', $response->get_error_code() );
	}

	public function test_share_intake_with_array_fields_carries_no_share_data_and_no_warning(): void {
		$redirects = array();
		\Outpost_Share_Target_Controller::set_redirect_callback_for_tests(
			static function ( string $url ) use ( &$redirects ): void {
				$redirects[] = $url;
			}
		);
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'url'   => array( 'https://example.org/x' ),
			'title' => array( 'x' ),
			'text'  => array( 'y' ),
		);
		try {
			\Outpost_Share_Target_Controller::handle_request();
		} finally {
			\Outpost_Share_Target_Controller::set_redirect_callback_for_tests( null );
		}
		// Array-valued fields aren't share data (ShareTargetDispatchTest): no redirect.
		$this->assertSame( array(), $redirects );
	}

	// ----- F-15: settings page and notice reads -------------------------

	private function settings_page(): string {
		ob_start();
		\Outpost_Settings_Page::render();
		return (string) ob_get_clean();
	}

	public function test_settings_saved_notice_follows_a_narrow_read(): void {
		$_GET = array( 'page' => \Outpost_Settings_Page::PAGE_SLUG );
		$this->assertStringNotContainsString( 'Settings saved.', $this->settings_page() );

		$_GET['settings-updated'] = 'true';
		$this->assertStringContainsString( 'Settings saved.', $this->settings_page() );

		$_GET['settings-updated'] = 'TRUE<script>';
		$this->assertStringNotContainsString( 'Settings saved.', $this->settings_page() );
	}

	public function test_array_shaped_settings_query_values_are_refused_without_a_warning(): void {
		$_GET = array(
			'page'             => \Outpost_Settings_Page::PAGE_SLUG,
			'settings-updated' => array( 'true' ),
			'tab'              => array( 'api_keys' ),
		);
		$html = $this->settings_page();
		$this->assertStringNotContainsString( 'Settings saved.', $html );
	}

	/**
	 * An array-shaped tab falls back to the first registered tab. A second,
	 * test-only tab makes the check able to fail: an implementation that read
	 * the array's first element would return 's313_second' instead.
	 */
	public function test_an_array_shaped_tab_falls_back_to_the_first_registered_tab(): void {
		$add = static function ( array $tabs ): array {
			$tabs['s313_second'] = array(
				'label'      => 'S3-13 second tab',
				'callback'   => '__return_null',
				'capability' => 'manage_options',
			);
			return $tabs;
		};
		add_filter( 'outpost_settings_tabs', $add );
		try {
			$_GET = array( 'tab' => 's313_second' );
			$this->assertSame( 's313_second', \Outpost_Settings_Page::resolve_active_tab(), 'Control: a string tab id is honored.' );

			$_GET = array( 'tab' => array( 's313_second' ) );
			$this->assertSame( 'api_keys', \Outpost_Settings_Page::resolve_active_tab() );
		} finally {
			remove_filter( 'outpost_settings_tabs', $add );
		}
	}

	public function test_an_array_shaped_post_query_is_refused_without_a_warning(): void {
		$_GET = array( 'post' => array( '1' ) );
		$read = new \ReflectionMethod( \Outpost_Pending_Syndication_Notice::class, 'current_post_id' );
		$this->assertSame( 0, (int) $read->invoke( null ) );
	}

	// ----- F-11: Appearance notice --------------------------------------

	private function appearance_page(): string {
		ob_start();
		\Outpost_Appearance_Settings_Page::render();
		return (string) ob_get_clean();
	}

	public function test_the_appearance_error_notice_does_not_reflect_text_from_the_url(): void {
		$_GET = array(
			'page'    => \Outpost_Appearance_Settings_Page::PAGE_SLUG,
			'notice'  => 'error',
			'message' => 'Attacker supplied wording <b>here</b>',
		);
		$html = $this->appearance_page();

		$this->assertStringNotContainsString( 'Attacker supplied wording', $html );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Save failed.', $html );
	}

	public function test_the_appearance_error_notice_shows_the_saved_message_escaped_once(): void {
		set_transient( 'outpost_appearance_notice_' . $this->admin_id, 'Contrast too low for <text> on night.', 60 );
		$_GET = array(
			'page'   => \Outpost_Appearance_Settings_Page::PAGE_SLUG,
			'notice' => 'error',
		);

		$html = $this->appearance_page();

		$this->assertStringContainsString( 'Contrast too low for &lt;text&gt; on night.', $html );
		$this->assertFalse( get_transient( 'outpost_appearance_notice_' . $this->admin_id ), 'Shown once.' );
	}

	public function test_an_array_shaped_notice_is_refused_without_a_warning(): void {
		$_GET = array(
			'page'   => \Outpost_Appearance_Settings_Page::PAGE_SLUG,
			'notice' => array( 'error' ),
		);
		$this->assertStringNotContainsString( 'notice-error', $this->appearance_page() );
	}

	public function test_the_appearance_notice_is_scoped_to_its_own_screen(): void {
		$this->assertFalse( has_action( 'admin_notices', array( \Outpost_Appearance_Settings_Page::class, 'render_admin_notice' ) ) );
	}
}
