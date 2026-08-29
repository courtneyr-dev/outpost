<?php
/**
 * Outpost_OAuth_Provider_Whoop unit tests (G11b).
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Credentials_Store;
use Outpost_Encryption_Key_Resolver;
use Outpost_OAuth_Provider_Whoop;
use ReflectionClass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class OAuthProviderWhoopTest extends TestCase {

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
		$p = new Outpost_OAuth_Provider_Whoop();
		$this->assertSame( 'whoop', $p->id() );
		$this->assertSame( 'https://api.prod.whoop.com/oauth/oauth2/auth', $p->authorize_url() );
		$this->assertSame( 'https://api.prod.whoop.com/oauth/oauth2/token', $p->token_url() );
		// WHOOP doesn't expose RFC 7009; null endpoint, custom DELETE
		// path lives in disconnect() override.
		$this->assertNull( $p->revocation_endpoint() );
	}

	public function test_scopes_full_read_only_set(): void {
		$this->assertSame(
			array( 'read:profile', 'read:sleep', 'read:recovery', 'read:cycles', 'read:workout' ),
			( new Outpost_OAuth_Provider_Whoop() )->scopes()
		);
	}

	public function test_verify_connection_returns_no_credentials_when_unconnected(): void {
		$this->stub_meta_store( array() );
		$result = ( new Outpost_OAuth_Provider_Whoop() )->verify_connection( 7 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'no_credentials', $result['reason'] );
	}

	public function test_verify_connection_returns_user_identity_on_200(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'whoop-token-abc' ) );
		$body = '{"user_id":12345,"first_name":"Alice","last_name":"R","email":"alice@example.com"}';
		$this->stub_remote_get_response( 200, $body );

		$result = ( new Outpost_OAuth_Provider_Whoop() )->verify_connection( 7 );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Alice', $result['first_name'] );
		$this->assertSame( 12345, $result['user_id'] );
	}

	public function test_verify_connection_returns_auth_failed_on_401(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'expired-token' ) );
		$this->stub_remote_get_response( 401, '{"error":"unauthorized"}' );

		$result = ( new Outpost_OAuth_Provider_Whoop() )->verify_connection( 7 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'auth_failed', $result['reason'] );
		$this->assertSame( 401, $result['status'] );
	}

	public function test_verify_connection_returns_transport_failed_on_wp_error(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'whoop-token' ) );
		$err = new \WP_Error( 'http_request_failed', 'connection refused' );
		WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $err );

		$result = ( new Outpost_OAuth_Provider_Whoop() )->verify_connection( 7 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'transport_failed', $result['reason'] );
	}

	public function test_disconnect_calls_whoop_delete_endpoint_then_local_delete(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'whoop-token-xyz' ) );
		$captured = array();
		WP_Mock::userFunction( 'wp_remote_request' )->andReturnUsing(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = array(
					'url'  => $url,
					'args' => $args,
				);
				return array(
					'response' => array( 'code' => 204 ),
					'body'     => '',
				);
			}
		);

		$ok = ( new Outpost_OAuth_Provider_Whoop() )->disconnect( 7 );
		$this->assertTrue( $ok );
		$this->assertSame( 'https://api.prod.whoop.com/developer/v2/user/access', $captured['url'] );
		$this->assertSame( 'DELETE', $captured['args']['method'] );
		$this->assertSame( 'Bearer whoop-token-xyz', $captured['args']['headers']['Authorization'] );
		// Local delete should have run regardless.
		$this->assertFalse( Outpost_Credentials_Store::is_configured( 'whoop', 7 ) );
	}

	public function test_disconnect_local_delete_runs_even_on_remote_failure(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'whoop-token-xyz' ) );
		$err = new \WP_Error( 'http_request_failed', 'connection refused' );
		WP_Mock::userFunction( 'wp_remote_request' )->andReturn( $err );

		$ok = ( new Outpost_OAuth_Provider_Whoop() )->disconnect( 7 );
		$this->assertTrue( $ok );
		$this->assertFalse( Outpost_Credentials_Store::is_configured( 'whoop', 7 ) );
	}

	public function test_disconnect_no_credentials_short_circuits_local_delete(): void {
		$this->stub_meta_store( array() );
		// wp_remote_request should NOT be called when there's no token.
		// We avoid registering it; if the code calls it, the test fails.

		$ok = ( new Outpost_OAuth_Provider_Whoop() )->disconnect( 7 );
		$this->assertTrue( $ok );
	}

	public function test_token_refresh_uses_inherited_base_method(): void {
		$this->stub_meta_store_with_creds(
			array(
				'access_token'  => 'old-token',
				'refresh_token' => 'refresh-abc',
				'expires_in'    => 3600,
				'obtained_at'   => time() - 4000, // expired
			)
		);
		$this->assertTrue( ( new Outpost_OAuth_Provider_Whoop() )->is_expired( 7 ) );
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
		Outpost_Credentials_Store::set( 'whoop', $creds, 7 );
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
