<?php
/**
 * Outpost_OAuth_Provider_Polar unit tests (G11c).
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Credentials_Store;
use Outpost_Encryption_Key_Resolver;
use Outpost_OAuth_Provider_Polar;
use ReflectionClass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class OAuthProviderPolarTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Encryption_Key_Resolver::reset_for_tests();
		$ref  = new ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Encryption_Key_Resolver::reset_for_tests();
	}

	public function test_static_metadata(): void {
		$p = new Outpost_OAuth_Provider_Polar();
		$this->assertSame( 'polar', $p->id() );
		$this->assertSame( 'https://flow.polar.com/oauth2/authorization', $p->authorize_url() );
		$this->assertSame( 'https://polarremote.com/v2/oauth2/token', $p->token_url() );
		$this->assertSame( 'https://polarremote.com/v2/oauth2/revoke', $p->revocation_endpoint() );
	}

	public function test_scopes_single_accesslink_read_all(): void {
		$this->assertSame(
			array( 'accesslink.read_all' ),
			( new Outpost_OAuth_Provider_Polar() )->scopes()
		);
	}

	// --- after_token_exchange (POST /v3/users registration) -------------

	public function test_after_token_exchange_posts_to_v3_users_with_member_id(): void {
		$this->stub_meta_store( array() );
		$captured = array();
		$this->stub_remote_post( 200, '{"member-id":"7","first-name":"Alice"}', $captured );

		( new Outpost_OAuth_Provider_Polar() )->after_token_exchange(
			7,
			array( 'access_token' => 'polar-token-abc' )
		);

		$this->assertSame( 'https://www.polaraccesslink.com/v3/users', $captured['url'] );
		$this->assertSame( 'Bearer polar-token-abc', $captured['args']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $captured['args']['headers']['Content-Type'] );
		$this->assertSame( '{"member-id":"7"}', $captured['args']['body'] );
	}

	public function test_after_token_exchange_treats_409_as_success(): void {
		$this->stub_meta_store( array() );
		$this->stub_remote_post( 409, '{"error":"already exists"}' );
		( new Outpost_OAuth_Provider_Polar() )->after_token_exchange(
			7,
			array( 'access_token' => 'polar-token-abc' )
		);
		$this->assertTrue( true );
	}

	public function test_after_token_exchange_logs_4xx_other_than_409_does_not_abort(): void {
		$this->stub_meta_store( array() );
		$this->stub_remote_post( 400, '{"error":"bad request"}' );
		( new Outpost_OAuth_Provider_Polar() )->after_token_exchange(
			7,
			array( 'access_token' => 'polar-token-abc' )
		);
		$this->assertTrue( true );
	}

	public function test_after_token_exchange_no_op_when_token_missing(): void {
		// No wp_remote_post mock registered; if the code calls it, fails.
		( new Outpost_OAuth_Provider_Polar() )->after_token_exchange( 7, array() );
		$this->assertTrue( true );
	}

	// --- verify_connection ---------------------------------------------

	public function test_verify_connection_returns_no_credentials_when_unconnected(): void {
		$this->stub_meta_store( array() );
		$result = ( new Outpost_OAuth_Provider_Polar() )->verify_connection( 7 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'no_credentials', $result['reason'] );
	}

	public function test_verify_connection_returns_user_identity_on_200(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'polar-token' ) );
		$body = '{"polar-user-id":12345,"member-id":"7","first-name":"Alice"}';
		$this->stub_remote_get_response( 200, $body );

		$result = ( new Outpost_OAuth_Provider_Polar() )->verify_connection( 7 );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Alice', $result['first_name'] );
		$this->assertSame( '7', $result['member_id'] );
	}

	public function test_verify_connection_returns_auth_failed_on_401(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'expired-token' ) );
		$this->stub_remote_get_response( 401, '{"error":"unauthorized"}' );

		$result = ( new Outpost_OAuth_Provider_Polar() )->verify_connection( 7 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'auth_failed', $result['reason'] );
	}

	public function test_verify_connection_404_triggers_registration_then_retry_succeeds(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'polar-token' ) );
		// First GET → 404. Then POST /v3/users → 200. Then second GET → 200.
		$get_call = 0;
		WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing(
			static function () use ( &$get_call ) {
				++$get_call;
				if ( 1 === $get_call ) {
					return array(
						'response' => array( 'code' => 404 ),
						'body'     => '{"error":"not found"}',
					);
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '{"member-id":"7","first-name":"Alice"}',
				);
			}
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing(
			static function ( $response ) {
				return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
			}
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing(
			static function ( $response ) {
				return isset( $response['body'] ) ? (string) $response['body'] : '';
			}
		);
		WP_Mock::userFunction( 'wp_remote_post' )->andReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"member-id":"7"}',
			)
		);

		$result = ( new Outpost_OAuth_Provider_Polar() )->verify_connection( 7 );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Alice', $result['first_name'] );
	}

	public function test_verify_connection_404_with_failed_registration_returns_user_not_registered(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'polar-token' ) );
		// All GETs return 404. POST /v3/users also fails.
		$this->stub_remote_get_response( 404, '{"error":"not found"}' );
		WP_Mock::userFunction( 'wp_remote_post' )->andReturn(
			array(
				'response' => array( 'code' => 500 ),
				'body'     => '{"error":"internal"}',
			)
		);

		$result = ( new Outpost_OAuth_Provider_Polar() )->verify_connection( 7 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'user_not_registered_with_app', $result['reason'] );
	}

	public function test_verify_connection_returns_transport_failed_on_wp_error(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'polar-token' ) );
		$err = new \WP_Error( 'http_request_failed', 'connection refused' );
		WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $err );

		$result = ( new Outpost_OAuth_Provider_Polar() )->verify_connection( 7 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'transport_failed', $result['reason'] );
	}

	// --- helpers --------------------------------------------------------

	private function stub_meta_store( array $initial_user_meta ): void {
		$user_meta = $initial_user_meta;
		$option    = array( 'value' => null );
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( static function ( $d ) { return json_encode( $d ); } );
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static function ( $key, $default = null ) use ( &$option ) {
				if ( 'outpost_encryption_key' === $key ) {
					return $option['value'] ?? false;
				}
				return $default;
			}
		);
		WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			static function ( $key, $value, $autoload = null ) use ( &$option ) {
				if ( 'outpost_encryption_key' === $key ) {
					$option['value'] = (string) $value;
				}
				return true;
			}
		);
		WP_Mock::userFunction( 'update_user_meta' )->andReturnUsing(
			static function ( $uid, $key, $value ) use ( &$user_meta ) {
				$user_meta[ $uid . '|' . $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			static function ( $uid, $key, $single ) use ( &$user_meta ) {
				return $user_meta[ $uid . '|' . $key ] ?? '';
			}
		);
		WP_Mock::userFunction( 'delete_user_meta' )->andReturnUsing(
			static function ( $uid, $key ) use ( &$user_meta ) {
				unset( $user_meta[ $uid . '|' . $key ] );
				return true;
			}
		);
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
	}

	private function stub_meta_store_with_creds( array $creds ): void {
		$this->stub_meta_store( array() );
		Outpost_Credentials_Store::set( 'polar', $creds, 7 );
	}

	private function stub_remote_get_response( int $status, string $body ): void {
		$response = array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
		);
		WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $response );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( $status );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
	}

	/**
	 * @param array<string,mixed>|null &$captured Optional capture sink.
	 */
	private function stub_remote_post( int $status, string $body, &$captured = null ): void {
		$response = array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
		);
		if ( null !== $captured ) {
			WP_Mock::userFunction( 'wp_remote_post' )->andReturnUsing(
				static function ( $url, $args ) use ( &$captured, $response ) {
					$captured = array(
						'url'  => $url,
						'args' => $args,
					);
					return $response;
				}
			);
		} else {
			WP_Mock::userFunction( 'wp_remote_post' )->andReturn( $response );
		}
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( $status );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
	}
}
