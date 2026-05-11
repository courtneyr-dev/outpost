<?php
/**
 * G5 — integration test: Beehiiv POSSE destination dispatch.
 *
 * Direct dispatch-level coverage against WireMock. The G3.5b
 * dispatcher's cron/retry logic is the dispatcher's own concern; this
 * test verifies what the Beehiiv adapter does with a given post and a
 * given API response.
 *
 * Pipeline being verified:
 *
 *     $adapter->dispatch( $post_id )
 *       -> read_settings (decrypts via Outpost_Settings_Handler)
 *       -> wp_safe_remote_post to api.beehiiv.com (rewritten to mock)
 *       -> parse status + JSON body
 *       -> return normalized array{success, syndication_url, error, retryable}
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Encryption;
use Outpost_Mock_Server;
use Outpost_POSSE_Destination_Beehiiv;
use Outpost_Settings_Registry;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class BeehiivPosseDestinationTest extends TestCase {

	private const PUB_ID    = 'pub_000000000000000000000000';
	private const API_PATH  = '/v2/publications/pub_000000000000000000000000/posts';
	private const API_KEY   = 'beehiiv_test_secret_aaaaaaaaaaaa';
	private const WEB_URL   = 'https://example-pub.beehiiv.com/p/test-slug';

	private int $user_id = 0;
	private int $post_id = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! $this->integration_environment_ready() ) {
			$this->markTestSkipped(
				'Skipped under unit bootstrap or without OUTPOST_TEST_MOCK_SERVER_URL. '
				. 'Run via `npm run test:integration` inside wp-env tests-cli + WireMock sidecar.'
			);
		}
		Outpost_Mock_Server::reset();
		delete_option( Outpost_Settings_Registry::option_name_for_tab( 'api_keys' ) );

		$this->user_id = (int) wp_insert_user(
			array(
				'user_login' => 'beehiiv_test_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'beehiiv_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		$this->assertGreaterThan( 0, $this->user_id );
		wp_set_current_user( $this->user_id );

		$this->post_id = (int) wp_insert_post(
			array(
				'post_title'   => 'Test Post Title',
				'post_content' => '<p>Body content for the syndicated copy.</p>',
				'post_status'  => 'publish',
				'post_author'  => $this->user_id,
			)
		);
		$this->assertGreaterThan( 0, $this->post_id );
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
		return class_exists( 'Outpost_POSSE_Destination_Beehiiv' )
			&& class_exists( 'Outpost_Mock_Server' )
			&& class_exists( 'Outpost_Encryption' )
			&& defined( 'OUTPOST_TEST_MOCK_SERVER_URL' );
	}

	/**
	 * @param array{enabled?:bool, api_key?:string, publication_id?:string} $overrides
	 */
	private function configure_beehiiv( array $overrides = array() ): void {
		$defaults = array(
			'enabled'        => true,
			'api_key'        => self::API_KEY,
			'publication_id' => self::PUB_ID,
		);
		$config = array_merge( $defaults, $overrides );

		$option = array(
			'beehiiv_enabled'        => (bool) $config['enabled'],
			'beehiiv_api_key'        => '' === $config['api_key']
				? array( 'encrypted' => '' )
				: array( 'encrypted' => Outpost_Encryption::encrypt( (string) $config['api_key'] ) ),
			'beehiiv_publication_id' => (string) $config['publication_id'],
		);
		update_option( Outpost_Settings_Registry::option_name_for_tab( 'api_keys' ), $option );
	}

	/** @test */
	public function dispatch_succeeds_and_sends_canonical_link_in_body(): void {
		$this->configure_beehiiv();
		Outpost_Mock_Server::stub(
			'POST',
			self::API_PATH,
			array(
				'status'  => 201,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'data' => array(
							'id'      => 'post_test_id',
							'web_url' => self::WEB_URL,
						),
					)
				),
			)
		);

		$result = ( new Outpost_POSSE_Destination_Beehiiv() )->dispatch( $this->post_id );

		$this->assertTrue( $result['success'], 'Beehiiv dispatch should succeed on 201.' );
		$this->assertSame( self::WEB_URL, $result['syndication_url'] );
		$this->assertNull( $result['error'] );
		$this->assertFalse( $result['retryable'] );

		$requests = Outpost_Mock_Server::received_requests( 'POST', self::API_PATH );
		$this->assertCount( 1, $requests, 'Adapter should call the Beehiiv posts endpoint exactly once.' );
		$body = json_decode( (string) ( $requests[0]['body'] ?? '' ), true );
		$this->assertIsArray( $body );
		$this->assertSame( 'Test Post Title', $body['subject'] ?? null );
		$this->assertSame( 'confirmed', $body['status'] ?? null );
		$this->assertStringContainsString( 'This post originally appeared on', (string) ( $body['body_content'] ?? '' ) );
		$this->assertStringContainsString( (string) get_permalink( $this->post_id ), (string) ( $body['body_content'] ?? '' ) );

		$auth = (string) ( $requests[0]['headers']['Authorization'] ?? '' );
		$this->assertSame( 'Bearer ' . self::API_KEY, $auth, 'Auth header must use Bearer scheme with the API key.' );
	}

	/** @test */
	public function dispatch_returns_non_retryable_on_401_auth_failure(): void {
		$this->configure_beehiiv();
		Outpost_Mock_Server::stub(
			'POST',
			self::API_PATH,
			array(
				'status'  => 401,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( array( 'error' => 'Unauthorized' ) ),
			)
		);

		$result = ( new Outpost_POSSE_Destination_Beehiiv() )->dispatch( $this->post_id );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['retryable'], '401 must be non-retryable (auth fix needed).' );
		$this->assertStringContainsString( '401', (string) $result['error'] );
	}

	/** @test */
	public function dispatch_returns_retryable_on_503_transient_failure(): void {
		$this->configure_beehiiv();
		Outpost_Mock_Server::stub(
			'POST',
			self::API_PATH,
			array(
				'status'  => 503,
				'headers' => array( 'Content-Type' => 'text/plain' ),
				'body'    => 'Service Unavailable',
			)
		);

		$result = ( new Outpost_POSSE_Destination_Beehiiv() )->dispatch( $this->post_id );

		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['retryable'], '503 must be retryable.' );
		$this->assertStringContainsString( '503', (string) $result['error'] );
	}

	/** @test */
	public function dispatch_skips_api_call_when_disabled(): void {
		$this->configure_beehiiv( array( 'enabled' => false ) );

		$result = ( new Outpost_POSSE_Destination_Beehiiv() )->dispatch( $this->post_id );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['retryable'] );
		$this->assertCount(
			0,
			Outpost_Mock_Server::received_requests( 'POST', self::API_PATH ),
			'Disabled destination must not make API calls.'
		);
	}

	/** @test */
	public function dispatch_returns_non_retryable_when_api_key_missing(): void {
		$this->configure_beehiiv( array( 'api_key' => '' ) );

		$result = ( new Outpost_POSSE_Destination_Beehiiv() )->dispatch( $this->post_id );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['retryable'] );
		$this->assertCount(
			0,
			Outpost_Mock_Server::received_requests( 'POST', self::API_PATH ),
			'Missing credentials must short-circuit before HTTP.'
		);
	}
}
