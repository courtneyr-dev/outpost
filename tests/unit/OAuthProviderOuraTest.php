<?php
/**
 * Outpost_OAuth_Provider_Oura unit tests (G11a).
 *
 * Covers static metadata, scope set, membership-gate detection, and
 * the verify_connection() path against mocked HTTP. OAuth flow itself
 * (state generation, code exchange, token persistence) is covered by
 * the existing G3.5a tests which exercise the inherited base methods.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Credentials_Store;
use Outpost_Encryption_Key_Resolver;
use Outpost_OAuth_Provider_Oura;
use ReflectionClass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class OAuthProviderOuraTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Encryption_Key_Resolver::reset_for_tests();
		// Reset WP_Mock filter registry per CLAUDE.md A2 #8.
		$ref  = new ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Encryption_Key_Resolver::reset_for_tests();
	}

	// --- Static metadata --------------------------------------------------

	public function test_static_metadata(): void {
		$p = new Outpost_OAuth_Provider_Oura();
		$this->assertSame( 'oura', $p->id() );
		$this->assertSame( 'https://cloud.ouraring.com/oauth/authorize', $p->authorize_url() );
		$this->assertSame( 'https://api.ouraring.com/oauth/token', $p->token_url() );
		$this->assertSame( 'https://api.ouraring.com/oauth/revoke', $p->revocation_endpoint() );
	}

	public function test_scopes_full_read_only_set(): void {
		$scopes = ( new Outpost_OAuth_Provider_Oura() )->scopes();
		$this->assertSame(
			array( 'email', 'personal', 'daily', 'heartrate', 'workout', 'tag', 'session', 'ring_configuration' ),
			$scopes
		);
	}

	// --- Membership-gate detection ---------------------------------------

	public function test_membership_gate_detection_known_phrasings(): void {
		$known = array(
			'{"detail":"expired_oura_membership"}',
			'{"detail":"membership_required"}',
			'{"error":"Your Oura Membership has expired."}',
			'{"error":"oura membership has lapsed"}',
			'subscription required',
		);
		foreach ( $known as $body ) {
			$this->assertTrue(
				Outpost_OAuth_Provider_Oura::is_membership_gate_response( $body ),
				"Should detect membership gate in: {$body}"
			);
		}
	}

	public function test_membership_gate_detection_rejects_other_errors(): void {
		$other = array(
			'',
			'{"detail":"invalid_token"}',
			'{"detail":"token_expired"}',
			'{"error":"Internal server error"}',
		);
		foreach ( $other as $body ) {
			$this->assertFalse(
				Outpost_OAuth_Provider_Oura::is_membership_gate_response( $body ),
				"Should NOT flag membership gate for: {$body}"
			);
		}
	}

	// --- verify_connection() ---------------------------------------------

	public function test_verify_connection_returns_no_credentials_when_unconnected(): void {
		$this->stub_meta_store( array() );

		$result = ( new Outpost_OAuth_Provider_Oura() )->verify_connection( 7 );
		$this->assertSame( false, $result['ok'] );
		$this->assertSame( 'no_credentials', $result['reason'] );
	}

	public function test_verify_connection_returns_ok_with_email_on_200(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'live-token-abc' ) );
		$this->stub_remote_get_response( 200, '{"email":"alice@example.com","age":30}' );

		$result = ( new Outpost_OAuth_Provider_Oura() )->verify_connection( 7 );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'alice@example.com', $result['email'] );
	}

	public function test_verify_connection_returns_membership_required_on_401_with_signature(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'live-token-abc' ) );
		$this->stub_remote_get_response( 401, '{"detail":"expired_oura_membership"}' );

		$result = ( new Outpost_OAuth_Provider_Oura() )->verify_connection( 7 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'membership_required', $result['reason'] );
	}

	public function test_verify_connection_returns_auth_failed_on_401_without_membership_signature(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'live-token-abc' ) );
		$this->stub_remote_get_response( 401, '{"detail":"invalid_token"}' );

		$result = ( new Outpost_OAuth_Provider_Oura() )->verify_connection( 7 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'auth_failed', $result['reason'] );
		$this->assertSame( 401, $result['status'] );
	}

	public function test_verify_connection_returns_transport_failed_on_wp_error(): void {
		$this->stub_meta_store_with_creds( array( 'access_token' => 'live-token-abc' ) );
		// Bootstrap defines is_wp_error as a real `$thing instanceof WP_Error`
		// check — passing a real WP_Error instance is the way to trip it.
		$err = new \WP_Error( 'http_request_failed', 'connection refused' );
		WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $err );

		$result = ( new Outpost_OAuth_Provider_Oura() )->verify_connection( 7 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'transport_failed', $result['reason'] );
	}

	// --- Helpers ---------------------------------------------------------

	/**
	 * Wire a fully in-memory option + user_meta store so the encryption
	 * key resolver and credentials store roundtrip without touching the
	 * filesystem.
	 *
	 * @param array<string,string> $initial_user_meta Key→value seed.
	 */
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

	/**
	 * Set up the meta store, persist a real encrypted credential blob
	 * via Outpost_Credentials_Store::set so verify_connection's read
	 * path roundtrips through actual Sodium.
	 *
	 * @param array<string,mixed> $creds Credentials to encrypt + store.
	 */
	private function stub_meta_store_with_creds( array $creds ): void {
		$this->stub_meta_store( array() );
		Outpost_Credentials_Store::set( 'oura', $creds, 7 );
	}

	/**
	 * Stub wp_remote_get + the retrieval helpers for a single canned
	 * response.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $body   Response body.
	 */
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
