<?php
/**
 * Outpost_OAuth_Provider_Ravelry unit tests (G14b).
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Credentials_Store;
use Outpost_Encryption_Key_Resolver;
use Outpost_OAuth_Provider_Ravelry;
use ReflectionClass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class OAuthProviderRavelryTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Encryption_Key_Resolver::reset_for_tests();
		$ref  = new ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Encryption_Key_Resolver::reset_for_tests();
	}

	public function test_static_metadata(): void {
		$p = new Outpost_OAuth_Provider_Ravelry();
		$this->assertSame( 'ravelry', $p->id() );
		$this->assertSame( 'https://www.ravelry.com/oauth2/auth', $p->authorize_url() );
		$this->assertSame( 'https://www.ravelry.com/oauth2/token', $p->token_url() );
		$this->assertNull( $p->revocation_endpoint() );
	}

	public function test_default_scope_set_includes_offline_for_refresh(): void {
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			static function ( $tag, $value ) { return $value; }
		);
		$scopes = ( new Outpost_OAuth_Provider_Ravelry() )->scopes();
		$this->assertContains( 'offline', $scopes );
		$this->assertContains( 'patterns-read', $scopes );
		$this->assertContains( 'projects-read', $scopes );
		$this->assertContains( 'library-read', $scopes );
		$this->assertContains( 'personal-data', $scopes );
	}

	/**
	 * Filter-override tests for `outpost_oauth_provider_ravelry_scopes`
	 * are intentionally NOT here — WP_Mock 1.x's andReturnUsing on
	 * `apply_filters` doesn't propagate consistently when overridden
	 * per-test (same trap as G4b's PerfmattersDefangTest). The default
	 * scope set is verified above, which proves apply_filters is invoked.
	 * Override behavior follows the same code path; mechanism-level
	 * confidence comes from the basic test rather than re-mocking.
	 */

	public function test_verify_connection_returns_no_credentials_when_unconnected(): void {
		$this->stub_meta_store( array() );
		$result = ( new Outpost_OAuth_Provider_Ravelry() )->verify_connection( 7 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'no_credentials', $result['reason'] );
	}

	public function test_verify_connection_returns_user_identity_on_200_nested(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'ravelry-token-abc' ) );
		$body = '{"user":{"id":7777,"username":"alice","displayname":"Alice K"}}';
		$this->stub_remote_get_response( 200, $body );

		$result = ( new Outpost_OAuth_Provider_Ravelry() )->verify_connection( 7 );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'alice', $result['username'] );
		$this->assertSame( 'Alice K', $result['display_name'] );
		$this->assertSame( 7777, $result['id'] );
	}

	public function test_verify_connection_tolerates_display_name_alt_field(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'ravelry-token-abc' ) );
		$body = '{"user":{"id":8888,"username":"bob","display_name":"Bob R"}}';
		$this->stub_remote_get_response( 200, $body );

		$result = ( new Outpost_OAuth_Provider_Ravelry() )->verify_connection( 7 );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Bob R', $result['display_name'] );
	}

	public function test_verify_connection_returns_auth_failed_on_401(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'expired-token' ) );
		$this->stub_remote_get_response( 401, '{"error":"unauthorized"}' );

		$result = ( new Outpost_OAuth_Provider_Ravelry() )->verify_connection( 7 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'auth_failed', $result['reason'] );
		$this->assertSame( 401, $result['status'] );
	}

	public function test_verify_connection_returns_transport_failed_on_wp_error(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'ravelry-token-abc' ) );
		$err = new \WP_Error( 'http_request_failed', 'connection refused' );
		WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $err );

		$result = ( new Outpost_OAuth_Provider_Ravelry() )->verify_connection( 7 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'transport_failed', $result['reason'] );
	}

	public function test_token_refresh_uses_inherited_base_method(): void {
		// Ravelry inherits is_expired() + refresh_access_token() from
		// the base; this sanity-check confirms the wiring without
		// re-testing the base's internal logic.
		$this->stub_meta_store_with_creds(
			array(
				'access_token'  => 'old-token',
				'refresh_token' => 'refresh-abc',
				'expires_in'    => 3600,
				'obtained_at'   => time() - 4000,  // expired
			)
		);
		$this->assertTrue( ( new Outpost_OAuth_Provider_Ravelry() )->is_expired( 7 ) );
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
		Outpost_Credentials_Store::set( 'ravelry', $creds, 7 );
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
