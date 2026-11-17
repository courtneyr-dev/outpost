<?php
/**
 * /preview binds a client-supplied source_id to the URL (T10-S3-03).
 *
 * The composer sends `source_id` along with the URL. A source chosen that way
 * decides which adapter runs and which service the server contacts (Spotify
 * oEmbed, the Notion API with the user's stored credential). The server now
 * honors `source_id` only when that source matches the submitted URL under
 * the same detection rules the share route uses. A mismatched, unregistered,
 * or catch-all (`unknown`) `source_id` falls back to URL detection, so it can
 * never pick an adapter or an outbound target the URL itself wouldn't.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Credentials_Store;
use Outpost_Preview_Endpoint;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class PreviewSourceBindingTest extends TestCase {

	private const SPOTIFY_URL = 'https://open.spotify.com/track/0000000000000000000000';
	private const NOTION_URL  = 'https://www.notion.so/T10-Fixture-0123456789abcdef0123456789abcdef';
	private const OTHER_URL   = 'https://example.org/page-0123456789abcdef0123456789abcdef';

	private int $editor_id = 0;

	/** @var string[] Outbound request URLs, in order. */
	private array $outbound = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Preview_Endpoint' ) || ! class_exists( 'Outpost_Source_Registry' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap. Run via `npm run test:integration` inside wp-env tests-cli.' );
		}
		$this->editor_id = (int) wp_insert_user(
			array(
				'user_login' => 'binding_editor_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'binding_editor_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		wp_set_current_user( $this->editor_id );
		$this->outbound = array();
		add_filter( 'pre_http_request', array( $this, 'record_and_stub' ), 0, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'record_and_stub' ), 0 );
		if ( $this->editor_id > 0 ) {
			Outpost_Credentials_Store::delete( 'notion', $this->editor_id );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->editor_id );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Record every outbound request and answer it in-process, so nothing
	 * leaves the test container.
	 *
	 * @param false|array|\WP_Error $pre  Short-circuit value.
	 * @param array                 $args Request args.
	 * @param string                $url  Request URL.
	 * @return array
	 */
	public function record_and_stub( $pre, $args, $url ): array {
		unset( $pre, $args );
		$this->outbound[] = (string) $url;
		return array(
			'headers'  => array( 'content-type' => 'text/html; charset=utf-8' ),
			'body'     => '<html><head><title>stub</title></head><body>stub</body></html>',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** Which source /preview resolves for this URL and source_id. */
	private static function resolved_id( string $url, ?string $source_id ): string {
		$request = new WP_REST_Request( 'POST', '/outpost/v1/preview' );
		$request->set_param( 'url', $url );
		if ( null !== $source_id ) {
			$request->set_param( 'source_id', $source_id );
		}
		$resolve = new \ReflectionMethod( Outpost_Preview_Endpoint::class, 'resolve_source' );
		$source  = $resolve->invoke( null, $request, $url );
		$id_of   = new \ReflectionMethod( Outpost_Preview_Endpoint::class, 'source_id_of' );
		return null === $source ? '' : (string) $id_of->invoke( null, $source );
	}

	/** @return string[] Hosts contacted. */
	private function hosts(): array {
		return array_values( array_unique( array_map( static fn( string $u ): string => (string) wp_parse_url( $u, PHP_URL_HOST ), $this->outbound ) ) );
	}

	public function test_matching_source_id_is_honored(): void {
		$this->assertSame( 'spotify', self::resolved_id( self::SPOTIFY_URL, 'spotify' ) );
		$this->assertSame( 'notion', self::resolved_id( self::NOTION_URL, 'notion' ) );
	}

	public function test_absent_source_id_uses_url_detection(): void {
		$this->assertSame( 'spotify', self::resolved_id( self::SPOTIFY_URL, null ) );
	}

	public function test_mismatched_source_id_falls_back_to_url_detection(): void {
		$this->assertSame( 'unknown', self::resolved_id( self::OTHER_URL, 'spotify' ), 'A Spotify source_id must not pick the Spotify adapter for a non-Spotify URL.' );
		$this->assertSame( 'unknown', self::resolved_id( self::OTHER_URL, 'notion' ), 'A Notion source_id must not pick the Notion adapter for a non-Notion URL.' );
		$this->assertSame( 'spotify', self::resolved_id( self::SPOTIFY_URL, 'notion' ) );
	}

	public function test_unregistered_or_catch_all_source_id_falls_back_to_url_detection(): void {
		$this->assertSame( 'spotify', self::resolved_id( self::SPOTIFY_URL, 'no-such-source' ) );
		$this->assertSame( 'spotify', self::resolved_id( self::SPOTIFY_URL, 'unknown' ), 'The catch-all source never overrides a URL-specific one.' );
	}

	public function test_mismatched_spotify_source_id_never_contacts_spotify(): void {
		$request = new WP_REST_Request( 'POST', '/outpost/v1/preview' );
		$request->set_param( 'url', self::OTHER_URL );
		$request->set_param( 'source_id', 'spotify' );

		rest_get_server()->dispatch( $request );

		$this->assertNotContains( 'open.spotify.com', $this->hosts(), 'The client-chosen adapter must not send the URL to Spotify.' );
	}

	public function test_mismatched_notion_source_id_never_uses_the_stored_notion_credential(): void {
		Outpost_Credentials_Store::set( 'notion', array( 'access_token' => 'synthetic-not-a-real-token' ), $this->editor_id );
		$request = new WP_REST_Request( 'POST', '/outpost/v1/preview' );
		$request->set_param( 'url', self::OTHER_URL );
		$request->set_param( 'source_id', 'notion' );

		rest_get_server()->dispatch( $request );

		$this->assertNotContains( 'api.notion.com', $this->hosts(), 'A non-Notion URL must not reach the Notion API with the stored credential.' );
	}
}
