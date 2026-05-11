<?php
/**
 * G5 — integration test: write.as POSSE destination dispatch.
 *
 * Unique to write.as vs the other three G5 destinations:
 *  - Auth scheme is `Authorization: Token …` (same shape as Buttondown,
 *    different value scope).
 *  - Body is markdown (transformed from Gutenberg HTML), with the
 *    canonical paragraph appended in markdown form.
 *  - Endpoint differs based on whether a collection alias is set:
 *    standalone post at /api/posts vs collection-scoped at
 *    /api/collections/{alias}/posts.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Encryption;
use Outpost_Mock_Server;
use Outpost_POSSE_Destination_WriteAs;
use Outpost_Settings_Registry;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class WriteAsPosseDestinationTest extends TestCase {

	private const API_PATH_STANDALONE = '/api/posts';
	private const API_PATH_COLLECTION = '/api/collections/test-blog/posts';
	private const API_TOKEN           = 'writeas_test_token_aaaaaaaaaaa';

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
				'user_login' => 'writeas_test_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'writeas_' . uniqid() . '@example.test',
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
		return class_exists( 'Outpost_POSSE_Destination_WriteAs' )
			&& class_exists( 'Outpost_Mock_Server' )
			&& class_exists( 'Outpost_Encryption' )
			&& defined( 'OUTPOST_TEST_MOCK_SERVER_URL' );
	}

	/**
	 * @param array{enabled?:bool, api_token?:string, collection?:string} $overrides
	 */
	private function configure( array $overrides = array() ): void {
		$config = array_merge(
			array(
				'enabled'    => true,
				'api_token'  => self::API_TOKEN,
				'collection' => '',
			),
			$overrides
		);
		$option = array(
			'write_as_enabled'    => (bool) $config['enabled'],
			'write_as_api_token'  => '' === $config['api_token']
				? array( 'encrypted' => '' )
				: array( 'encrypted' => Outpost_Encryption::encrypt( (string) $config['api_token'] ) ),
			'write_as_collection' => (string) $config['collection'],
		);
		update_option( Outpost_Settings_Registry::option_name_for_tab( 'api_keys' ), $option );
	}

	/** @test */
	public function dispatch_to_standalone_endpoint_succeeds_with_markdown_canonical_paragraph(): void {
		$this->configure();
		Outpost_Mock_Server::stub(
			'POST',
			self::API_PATH_STANDALONE,
			array(
				'status'  => 201,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'code' => 201,
						'data' => array(
							'id'   => 'aaaaaa',
							'slug' => 'test-slug',
							'url'  => 'https://write.as/aaaaaa/test-slug',
						),
					)
				),
			)
		);

		$result = ( new Outpost_POSSE_Destination_WriteAs() )->dispatch( $this->post_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'https://write.as/aaaaaa/test-slug', $result['syndication_url'] );

		$requests = Outpost_Mock_Server::received_requests( 'POST', self::API_PATH_STANDALONE );
		$this->assertCount( 1, $requests );
		$body = json_decode( (string) ( $requests[0]['body'] ?? '' ), true );
		$this->assertIsArray( $body );
		$this->assertSame( 'Test Post Title', $body['title'] ?? null );
		$this->assertStringContainsString( 'This post originally appeared on', (string) ( $body['body'] ?? '' ) );
		$this->assertStringContainsString( (string) get_permalink( $this->post_id ), (string) ( $body['body'] ?? '' ) );

		$this->assertSame( 'Token ' . self::API_TOKEN, (string) ( $requests[0]['headers']['Authorization'] ?? '' ) );
	}

	/** @test */
	public function dispatch_to_collection_endpoint_when_alias_configured(): void {
		$this->configure( array( 'collection' => 'test-blog' ) );
		Outpost_Mock_Server::stub(
			'POST',
			self::API_PATH_COLLECTION,
			array(
				'status'  => 201,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'data' => array(
							'id'   => 'bbbbbb',
							'slug' => 'collection-post',
							'url'  => 'https://write.as/test-blog/collection-post',
						),
					)
				),
			)
		);

		$result = ( new Outpost_POSSE_Destination_WriteAs() )->dispatch( $this->post_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'https://write.as/test-blog/collection-post', $result['syndication_url'] );
		$this->assertCount(
			1,
			Outpost_Mock_Server::received_requests( 'POST', self::API_PATH_COLLECTION ),
			'When collection alias is set, the adapter must hit the collection-scoped endpoint.'
		);
		$this->assertCount(
			0,
			Outpost_Mock_Server::received_requests( 'POST', self::API_PATH_STANDALONE ),
			'And must NOT also fire against the standalone endpoint.'
		);
	}

	/** @test */
	public function dispatch_returns_non_retryable_on_401(): void {
		$this->configure();
		Outpost_Mock_Server::stub(
			'POST',
			self::API_PATH_STANDALONE,
			array(
				'status'  => 401,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'code'      => 401,
						'error_msg' => 'Bad access token.',
					)
				),
			)
		);

		$result = ( new Outpost_POSSE_Destination_WriteAs() )->dispatch( $this->post_id );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['retryable'] );
	}

	/** @test */
	public function dispatch_returns_retryable_on_502(): void {
		$this->configure();
		Outpost_Mock_Server::stub(
			'POST',
			self::API_PATH_STANDALONE,
			array(
				'status'  => 502,
				'headers' => array( 'Content-Type' => 'text/plain' ),
				'body'    => 'Bad Gateway',
			)
		);

		$result = ( new Outpost_POSSE_Destination_WriteAs() )->dispatch( $this->post_id );

		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['retryable'] );
	}

	/** @test */
	public function dispatch_short_circuits_when_disabled_or_missing_token(): void {
		$this->configure( array( 'enabled' => false ) );
		$this->assertFalse( ( new Outpost_POSSE_Destination_WriteAs() )->dispatch( $this->post_id )['success'] );

		$this->configure( array( 'api_token' => '' ) );
		$this->assertFalse( ( new Outpost_POSSE_Destination_WriteAs() )->dispatch( $this->post_id )['success'] );

		$this->assertCount( 0, Outpost_Mock_Server::received_requests( 'POST', self::API_PATH_STANDALONE ) );
		$this->assertCount( 0, Outpost_Mock_Server::received_requests( 'POST', self::API_PATH_COLLECTION ) );
	}
}
