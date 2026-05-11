<?php
/**
 * G5 — integration test: Buttondown POSSE destination dispatch.
 *
 * Unique to Buttondown vs the other three G5 destinations:
 *  - Auth scheme is `Authorization: Token …` (not Bearer).
 *  - Canonical link is carried in the dedicated `canonical_url` field
 *    on the request body — no appended paragraph in `body`.
 *  - Body is markdown (transformed from Gutenberg HTML).
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Encryption;
use Outpost_Mock_Server;
use Outpost_POSSE_Destination_Buttondown;
use Outpost_Settings_Registry;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ButtondownPosseDestinationTest extends TestCase {

	private const API_PATH = '/v1/emails';
	private const API_KEY  = 'buttondown_test_secret_aaaaaaaa';
	private const WEB_URL  = 'https://buttondown.email/test-newsletter/archive/test-slug';

	private int $user_id = 0;
	private int $post_id = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! $this->integration_environment_ready() ) {
			$this->markTestSkipped( 'Requires wp-env + WireMock + OUTPOST_TEST_MOCK_SERVER_URL.' );
		}
		Outpost_Mock_Server::reset();
		delete_option( Outpost_Settings_Registry::option_name_for_tab( 'api_keys' ) );

		$this->user_id = (int) wp_insert_user(
			array(
				'user_login' => 'buttondown_test_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'buttondown_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		wp_set_current_user( $this->user_id );
		$this->post_id = (int) wp_insert_post(
			array(
				'post_title'   => 'Test Post Title',
				'post_content' => '<p>Body content for the syndicated copy.</p>',
				'post_status'  => 'publish',
				'post_author'  => $this->user_id,
			)
		);
	}

	protected function tearDown(): void {
		if ( $this->post_id > 0 ) {
			wp_delete_post( $this->post_id, true );
		}
		if ( $this->user_id > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->user_id );
		}
		wp_set_current_user( 0 );
		delete_option( Outpost_Settings_Registry::option_name_for_tab( 'api_keys' ) );
		parent::tearDown();
	}

	private function integration_environment_ready(): bool {
		return class_exists( 'Outpost_POSSE_Destination_Buttondown' )
			&& class_exists( 'Outpost_Mock_Server' )
			&& class_exists( 'Outpost_Encryption' )
			&& defined( 'OUTPOST_TEST_MOCK_SERVER_URL' );
	}

	/**
	 * @param array{enabled?:bool, api_key?:string, send_as_draft?:bool} $overrides
	 */
	private function configure( array $overrides = array() ): void {
		$config = array_merge(
			array(
				'enabled'       => true,
				'api_key'       => self::API_KEY,
				'send_as_draft' => false,
			),
			$overrides
		);
		$option = array(
			'buttondown_enabled'        => (bool) $config['enabled'],
			'buttondown_api_key'        => '' === $config['api_key']
				? array( 'encrypted' => '' )
				: array( 'encrypted' => Outpost_Encryption::encrypt( (string) $config['api_key'] ) ),
			'buttondown_send_as_draft'  => (bool) $config['send_as_draft'],
		);
		update_option( Outpost_Settings_Registry::option_name_for_tab( 'api_keys' ), $option );
	}

	/** @test */
	public function dispatch_succeeds_with_canonical_url_field_set_to_wp_permalink(): void {
		$this->configure();
		Outpost_Mock_Server::stub(
			'POST',
			self::API_PATH,
			array(
				'status'  => 201,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( array( 'web_url' => self::WEB_URL ) ),
			)
		);

		$result = ( new Outpost_POSSE_Destination_Buttondown() )->dispatch( $this->post_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( self::WEB_URL, $result['syndication_url'] );
		$this->assertNull( $result['error'] );

		$requests = Outpost_Mock_Server::received_requests( 'POST', self::API_PATH );
		$this->assertCount( 1, $requests );
		$body = json_decode( (string) ( $requests[0]['body'] ?? '' ), true );
		$this->assertIsArray( $body );

		// F1 tautological-assertion lesson — assert exact values, not just presence.
		$this->assertSame( 'Test Post Title', $body['subject'] ?? null );
		$this->assertSame( 'about_to_send', $body['status'] ?? null );
		$this->assertSame( (string) get_permalink( $this->post_id ), $body['canonical_url'] ?? null );
		$this->assertStringNotContainsString(
			'This post originally appeared on',
			(string) ( $body['body'] ?? '' ),
			'Buttondown carries canonical info in the canonical_url field; body must NOT also contain the append paragraph.'
		);

		$this->assertSame( 'Token ' . self::API_KEY, (string) ( $requests[0]['headers']['Authorization'] ?? '' ) );
	}

	/** @test */
	public function dispatch_sends_status_draft_when_send_as_draft_setting_enabled(): void {
		$this->configure( array( 'send_as_draft' => true ) );
		Outpost_Mock_Server::stub(
			'POST',
			self::API_PATH,
			array(
				'status'  => 201,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( array( 'web_url' => self::WEB_URL ) ),
			)
		);

		( new Outpost_POSSE_Destination_Buttondown() )->dispatch( $this->post_id );

		$requests = Outpost_Mock_Server::received_requests( 'POST', self::API_PATH );
		$body     = json_decode( (string) ( $requests[0]['body'] ?? '' ), true );
		$this->assertSame( 'draft', $body['status'] ?? null );
	}

	/** @test */
	public function dispatch_returns_non_retryable_on_401(): void {
		$this->configure();
		Outpost_Mock_Server::stub(
			'POST',
			self::API_PATH,
			array(
				'status'  => 401,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( array( 'detail' => 'Authentication credentials invalid.' ) ),
			)
		);

		$result = ( new Outpost_POSSE_Destination_Buttondown() )->dispatch( $this->post_id );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['retryable'] );
		$this->assertStringContainsString( '401', (string) $result['error'] );
	}

	/** @test */
	public function dispatch_returns_retryable_on_503(): void {
		$this->configure();
		Outpost_Mock_Server::stub(
			'POST',
			self::API_PATH,
			array(
				'status'  => 503,
				'headers' => array( 'Content-Type' => 'text/plain' ),
				'body'    => 'Service Unavailable',
			)
		);

		$result = ( new Outpost_POSSE_Destination_Buttondown() )->dispatch( $this->post_id );

		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['retryable'] );
	}

	/** @test */
	public function dispatch_short_circuits_when_disabled(): void {
		$this->configure( array( 'enabled' => false ) );

		$result = ( new Outpost_POSSE_Destination_Buttondown() )->dispatch( $this->post_id );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['retryable'] );
		$this->assertCount( 0, Outpost_Mock_Server::received_requests( 'POST', self::API_PATH ) );
	}

	/** @test */
	public function dispatch_short_circuits_when_api_key_missing(): void {
		$this->configure( array( 'api_key' => '' ) );

		$result = ( new Outpost_POSSE_Destination_Buttondown() )->dispatch( $this->post_id );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['retryable'] );
		$this->assertCount( 0, Outpost_Mock_Server::received_requests( 'POST', self::API_PATH ) );
	}
}
