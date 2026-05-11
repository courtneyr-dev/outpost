<?php
/**
 * G5 — integration test: Kit POSSE destination dispatch.
 *
 * Unique to Kit vs the other three G5 destinations:
 *  - Auth is carried as `api_secret` in the JSON body (v3 contract),
 *    NOT in an Authorization header.
 *  - Body field is `content` (HTML), with canonical paragraph appended
 *    via the shared content transformer.
 *  - Response wraps the broadcast in a `broadcast` object; the public
 *    URL falls back to a stable kit.com archive URL when the broadcast
 *    is created as a draft (Kit doesn't return a final URL until send).
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Encryption;
use Outpost_Mock_Server;
use Outpost_POSSE_Destination_Kit;
use Outpost_Settings_Registry;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class KitPosseDestinationTest extends TestCase {

	private const API_PATH   = '/v3/broadcasts';
	private const API_SECRET = 'kit_test_secret_aaaaaaaaaaaa';

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
				'user_login' => 'kit_test_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'kit_' . uniqid() . '@example.test',
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
		return class_exists( 'Outpost_POSSE_Destination_Kit' )
			&& class_exists( 'Outpost_Mock_Server' )
			&& class_exists( 'Outpost_Encryption' )
			&& defined( 'OUTPOST_TEST_MOCK_SERVER_URL' );
	}

	/**
	 * @param array{enabled?:bool, api_secret?:string} $overrides
	 */
	private function configure( array $overrides = array() ): void {
		$config = array_merge(
			array(
				'enabled'    => true,
				'api_secret' => self::API_SECRET,
			),
			$overrides
		);
		$option = array(
			'kit_enabled'    => (bool) $config['enabled'],
			'kit_api_secret' => '' === $config['api_secret']
				? array( 'encrypted' => '' )
				: array( 'encrypted' => Outpost_Encryption::encrypt( (string) $config['api_secret'] ) ),
		);
		update_option( Outpost_Settings_Registry::option_name_for_tab( 'api_keys' ), $option );
	}

	/** @test */
	public function dispatch_succeeds_and_sends_api_secret_in_body_with_canonical_paragraph(): void {
		$this->configure();
		Outpost_Mock_Server::stub(
			'POST',
			self::API_PATH,
			array(
				'status'  => 201,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'broadcast' => array(
							'id'         => 4242,
							'public_url' => 'https://kit.com/p/test-broadcast-public',
						),
					)
				),
			)
		);

		$result = ( new Outpost_POSSE_Destination_Kit() )->dispatch( $this->post_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'https://kit.com/p/test-broadcast-public', $result['syndication_url'] );

		$requests = Outpost_Mock_Server::received_requests( 'POST', self::API_PATH );
		$this->assertCount( 1, $requests );
		$body = json_decode( (string) ( $requests[0]['body'] ?? '' ), true );
		$this->assertIsArray( $body );

		// Kit v3 carries auth in the body, NOT in a header. Verify both.
		$this->assertSame( self::API_SECRET, $body['api_secret'] ?? null, 'api_secret must be in JSON body for Kit v3.' );
		$this->assertArrayNotHasKey( 'Authorization', (array) ( $requests[0]['headers'] ?? array() ) );

		$this->assertSame( 'Test Post Title', $body['subject'] ?? null );
		$this->assertStringContainsString( 'This post originally appeared on', (string) ( $body['content'] ?? '' ) );
		$this->assertStringContainsString( (string) get_permalink( $this->post_id ), (string) ( $body['content'] ?? '' ) );
	}

	/** @test */
	public function dispatch_falls_back_to_kit_app_url_when_public_url_missing(): void {
		$this->configure();
		Outpost_Mock_Server::stub(
			'POST',
			self::API_PATH,
			array(
				'status'  => 201,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( array( 'broadcast' => array( 'id' => 9999 ) ) ),
			)
		);

		$result = ( new Outpost_POSSE_Destination_Kit() )->dispatch( $this->post_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'https://app.kit.com/broadcasts/9999', $result['syndication_url'] );
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
				'body'    => (string) wp_json_encode( array( 'error' => 'invalid api_secret' ) ),
			)
		);

		$result = ( new Outpost_POSSE_Destination_Kit() )->dispatch( $this->post_id );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['retryable'] );
	}

	/** @test */
	public function dispatch_returns_retryable_on_429(): void {
		$this->configure();
		Outpost_Mock_Server::stub(
			'POST',
			self::API_PATH,
			array(
				'status'  => 429,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( array( 'error' => 'Rate limit hit' ) ),
			)
		);

		$result = ( new Outpost_POSSE_Destination_Kit() )->dispatch( $this->post_id );

		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['retryable'], '429 must be retryable.' );
	}

	/** @test */
	public function dispatch_short_circuits_when_disabled_or_missing_secret(): void {
		$this->configure( array( 'enabled' => false ) );
		$result_a = ( new Outpost_POSSE_Destination_Kit() )->dispatch( $this->post_id );
		$this->assertFalse( $result_a['success'] );

		$this->configure( array( 'api_secret' => '' ) );
		$result_b = ( new Outpost_POSSE_Destination_Kit() )->dispatch( $this->post_id );
		$this->assertFalse( $result_b['success'] );

		$this->assertCount( 0, Outpost_Mock_Server::received_requests( 'POST', self::API_PATH ) );
	}
}
