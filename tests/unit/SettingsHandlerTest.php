<?php
/**
 * Outpost_Settings_Handler unit tests (G3.5d).
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Settings_Handler;
use Outpost_Settings_Registry;
use ReflectionClass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class SettingsHandlerTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		$ref  = new ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static function ( $s ) { return (string) $s; } );
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static function ( $s ) { return (string) $s; } );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_nonce_action_string(): void {
		$this->assertSame(
			'outpost_settings_save_api_keys',
			Outpost_Settings_Handler::nonce_action( 'api_keys' )
		);
	}

	public function test_sanitize_and_encrypt_passes_nonsensitive_plaintext(): void {
		$fields = array(
			'instance_url' => array(
				'label'     => 'Instance URL',
				'type'      => 'url',
				'sensitive' => false,
			),
			'enabled'      => array(
				'label'     => 'Enabled',
				'type'      => 'checkbox',
				'sensitive' => false,
			),
		);
		$saved = Outpost_Settings_Handler::sanitize_and_encrypt(
			$fields,
			array(
				'instance_url' => 'https://example.com/conference',
				'enabled'      => '1',
			)
		);

		$this->assertSame( 'https://example.com/conference', $saved['instance_url'] );
		$this->assertTrue( $saved['enabled'] );
	}

	public function test_sanitize_and_encrypt_wraps_sensitive_in_encrypted_envelope(): void {
		// Stub the encryption helper class so we don't depend on the
		// real key-resolver here (covered by its own tests). In practice
		// Outpost_Encryption is loaded via bootstrap; we override
		// `encrypt` via a test seam.
		$fields = array(
			'api_key' => array(
				'label'     => 'API key',
				'type'      => 'password',
				'sensitive' => true,
			),
		);
		// Use the real Outpost_Encryption — guard against the bootstrap not
		// having a key configured by setting one for the test.
		\Outpost_Encryption_Key_Resolver::reset_for_tests();
		WP_Mock::userFunction( 'get_option' )->andReturn( base64_encode( random_bytes( 32 ) ) );

		$saved = Outpost_Settings_Handler::sanitize_and_encrypt(
			$fields,
			array( 'api_key' => 'sk-test-' . str_repeat( 'a', 16 ) ) // outpost-lint:fixture-credential
		);

		$this->assertIsArray( $saved['api_key'] );
		$this->assertArrayHasKey( 'encrypted', $saved['api_key'] );
		$this->assertNotEquals( 'sk-test-' . str_repeat( 'a', 16 ), $saved['api_key']['encrypted'] );
	}

	public function test_sanitize_and_encrypt_skips_empty_sensitive(): void {
		$fields = array(
			'api_key' => array(
				'label'     => 'API key',
				'type'      => 'password',
				'sensitive' => true,
			),
		);

		$saved = Outpost_Settings_Handler::sanitize_and_encrypt(
			$fields,
			array( 'api_key' => '' )
		);

		// Empty sensitive field stays as the empty string (NOT wrapped in encrypted envelope).
		$this->assertSame( '', $saved['api_key'] );
	}

	public function test_decrypt_stored_returns_raw_value_for_nonsensitive(): void {
		$config = array(
			'label'     => 'Instance URL',
			'type'      => 'url',
			'sensitive' => false,
		);

		$this->assertSame(
			'https://example.com',
			Outpost_Settings_Handler::decrypt_stored( $config, 'https://example.com' )
		);
	}

	public function test_decrypt_stored_returns_default_when_sensitive_value_missing(): void {
		$config = array(
			'label'     => 'API key',
			'type'      => 'password',
			'sensitive' => true,
			'default'   => 'fallback-default',
		);

		$this->assertSame(
			'fallback-default',
			Outpost_Settings_Handler::decrypt_stored( $config, null )
		);
		$this->assertSame(
			'fallback-default',
			Outpost_Settings_Handler::decrypt_stored( $config, 'plaintext-not-an-envelope' )
		);
	}

	public function test_sensitive_field_round_trip_stores_then_decrypts(): void {
		// End-to-end check: encrypted store, then read back.
		\Outpost_Encryption_Key_Resolver::reset_for_tests();
		WP_Mock::userFunction( 'get_option' )->andReturn( base64_encode( random_bytes( 32 ) ) );

		$fields = array(
			'api_key' => array(
				'label'     => 'API key',
				'type'      => 'password',
				'sensitive' => true,
			),
		);

		$saved = Outpost_Settings_Handler::sanitize_and_encrypt(
			$fields,
			array( 'api_key' => 'sk-roundtrip-' . str_repeat( 'b', 12 ) ) // outpost-lint:fixture-credential
		);
		$decrypted = Outpost_Settings_Handler::decrypt_stored( $fields['api_key'], $saved['api_key'] );

		$this->assertSame( 'sk-roundtrip-' . str_repeat( 'b', 12 ), $decrypted );
	}
}
