<?php
/**
 * Unit tests for Telegraph access-token storage (encrypted store + legacy
 * plaintext migration) in Outpost_Telegraph_Adapter::ensure_access_token().
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Credentials_Store;
use Outpost_Encryption_Key_Resolver;
use Outpost_Telegraph_Adapter;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class TelegraphTokenStoreTest extends TestCase {

	private const LEGACY_KEY = 'outpost_telegraph_access_token_user_7';

	/**
	 * Live copy of the stubbed user-meta store, shared with the closures
	 * registered in stub_meta_store().
	 *
	 * @var array<string,mixed>
	 */
	private array $user_meta = array();

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Encryption_Key_Resolver::reset_for_tests();
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			static function ( $hook, $value ) {
				return $value;
			}
		);
		WP_Mock::userFunction( '__' )->andReturnUsing( static function ( $s ) { return $s; } );
	}

	public function tearDown(): void {
		Outpost_Encryption_Key_Resolver::reset_for_tests();
		WP_Mock::tearDown();
	}

	public function test_returns_token_from_encrypted_store(): void {
		$this->stub_meta_store( array() );
		Outpost_Credentials_Store::set( 'telegraph', array( 'access_token' => 'enc-token-1' ), 7 );

		$token = Outpost_Telegraph_Adapter::ensure_access_token( 7 );

		$this->assertSame( 'enc-token-1', $token );
	}

	public function test_migrates_legacy_plaintext_token_into_encrypted_store(): void {
		$this->stub_meta_store( array( '7|' . self::LEGACY_KEY => 'legacy-token' ) );

		$token = Outpost_Telegraph_Adapter::ensure_access_token( 7 );

		$this->assertSame( 'legacy-token', $token, 'legacy token must keep working during migration' );
		$this->assertArrayNotHasKey(
			'7|' . self::LEGACY_KEY,
			$this->user_meta,
			'plaintext copy must be deleted after the encrypted write'
		);
		$this->assertSame(
			array( 'access_token' => 'legacy-token' ),
			Outpost_Credentials_Store::get( 'telegraph', 7 ),
			'token must be readable from the encrypted store after migration'
		);
	}

	public function test_migrated_value_is_not_stored_in_plaintext(): void {
		$this->stub_meta_store( array( '7|' . self::LEGACY_KEY => 'legacy-token' ) );

		Outpost_Telegraph_Adapter::ensure_access_token( 7 );

		foreach ( $this->user_meta as $key => $value ) {
			$this->assertStringNotContainsString(
				'legacy-token',
				(string) $value,
				"stored meta {$key} must not contain the plaintext token"
			);
		}
	}

	// --- helpers --------------------------------------------------------

	private function stub_meta_store( array $initial_user_meta ): void {
		$this->user_meta = $initial_user_meta;
		$user_meta       = &$this->user_meta;
		$option          = array( 'value' => null );
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
}
