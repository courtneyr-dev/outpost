<?php
/**
 * Outpost_OAuth_Provider_Ridewithgps unit tests (G12a).
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Credentials_Store;
use Outpost_Encryption_Key_Resolver;
use Outpost_OAuth_Provider_Ridewithgps;
use ReflectionClass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class OAuthProviderRidewithgpsTest extends TestCase {

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
		$p = new Outpost_OAuth_Provider_Ridewithgps();
		$this->assertSame( 'ridewithgps', $p->id() );
		$this->assertSame( 'https://ridewithgps.com/oauth/authorize', $p->authorize_url() );
		$this->assertSame( 'https://ridewithgps.com/oauth/token.json', $p->token_url() );
		$this->assertNull( $p->revocation_endpoint() );
	}

	public function test_default_scopes_read_only(): void {
		$this->assertSame( array( 'read' ), ( new Outpost_OAuth_Provider_Ridewithgps() )->scopes() );
	}

	public function test_verify_connection_returns_no_credentials_when_unconnected(): void {
		$this->stub_meta_store( array() );
		$result = ( new Outpost_OAuth_Provider_Ridewithgps() )->verify_connection( 7 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'no_credentials', $result['reason'] );
	}

	public function test_verify_connection_returns_user_identity_on_200_nested(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'live-rwg-token' ) );
		$body = '{"user":{"id":12345,"name":"Alice Cyclist"}}';
		$this->stub_remote_get_response( 200, $body );

		$result = ( new Outpost_OAuth_Provider_Ridewithgps() )->verify_connection( 7 );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Alice Cyclist', $result['name'] );
		$this->assertSame( 12345, $result['id'] );
	}

	public function test_verify_connection_tolerates_flat_user_shape(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'live-rwg-token' ) );
		$body = '{"id":67890,"name":"Bob Cyclist"}';
		$this->stub_remote_get_response( 200, $body );

		$result = ( new Outpost_OAuth_Provider_Ridewithgps() )->verify_connection( 7 );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Bob Cyclist', $result['name'] );
		$this->assertSame( 67890, $result['id'] );
	}

	public function test_verify_connection_returns_auth_failed_on_401(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'expired-token' ) );
		$this->stub_remote_get_response( 401, '{"error":"unauthorized"}' );

		$result = ( new Outpost_OAuth_Provider_Ridewithgps() )->verify_connection( 7 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'auth_failed', $result['reason'] );
		$this->assertSame( 401, $result['status'] );
	}

	public function test_verify_connection_returns_transport_failed_on_wp_error(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'live-rwg-token' ) );
		$err = new \WP_Error( 'http_request_failed', 'connection refused' );
		WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $err );

		$result = ( new Outpost_OAuth_Provider_Ridewithgps() )->verify_connection( 7 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'transport_failed', $result['reason'] );
	}

	public function test_missing_refresh_token_handled_gracefully(): void {
		// Some RWG OAuth apps issue long-lived tokens without
		// refresh_token. is_expired() should return false for creds
		// without expires_in, so refresh_access_token isn't auto-fired.
		$this->stub_meta_store_with_creds(
			array(
				'access_token' => 'long-lived-token',
				// No refresh_token, no expires_in.
			)
		);
		$p = new Outpost_OAuth_Provider_Ridewithgps();
		$this->assertFalse( $p->is_expired( 7 ) );

		// If something does call refresh_access_token, it should return
		// a meaningful WP_Error rather than crash.
		$result = $p->refresh_access_token( 7 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'outpost_oauth_no_refresh_token', $result->get_error_code() );
	}

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
		WP_Mock::userFunction( 'delete_user_meta' )->andReturn( true );
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
	}

	private function stub_meta_store_with_creds( array $creds ): void {
		$this->stub_meta_store( array() );
		Outpost_Credentials_Store::set( 'ridewithgps', $creds, 7 );
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
}
