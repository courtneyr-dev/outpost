<?php
/**
 * Unit tests for the G3.5a foundation layers:
 * Encryption + key resolver + credentials store + OAuth state +
 * Notion provider + Notion source URL detection + blocks converter.
 *
 * Encryption + key resolver are real (no mocking) — Sodium ships with
 * PHP 8.2+ and the resolve path uses get_option/update_option which
 * we mock via WP_Mock. Credentials store wraps both, also real.
 * OAuth state is real (uses random_bytes + WP_Mock'd user-meta).
 * Notion provider tests cover token-shape transformation.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Encryption;
use Outpost_Encryption_Exception;
use Outpost_Encryption_Key_Resolver;
use Outpost_Credentials_Store;
use Outpost_OAuth_State;
use Outpost_OAuth_Provider_Notion;
use Outpost_Source_Notion;
use Outpost_Notion_Blocks_Converter;
use WP_Mock;

final class G35aFoundationTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Encryption_Key_Resolver::reset_for_tests();
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Encryption_Key_Resolver::reset_for_tests();
	}

	// --- Encryption (real Sodium, mocked option storage) ----------------

	private function with_in_memory_option_store( ?string $initial = null ): void {
		// Simulate get_option/update_option backed by a single in-memory string.
		$state = array( 'value' => $initial );
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static function ( $key, $default = null ) use ( &$state ) {
				if ( 'outpost_encryption_key' === $key ) {
					return $state['value'] ?? false;
				}
				return $default;
			}
		);
		WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			static function ( $key, $value, $autoload = null ) use ( &$state ) {
				if ( 'outpost_encryption_key' === $key ) {
					$state['value'] = (string) $value;
				}
				return true;
			}
		);
	}

	public function test_encrypt_decrypt_roundtrip(): void {
		$this->with_in_memory_option_store();
		$plaintext = 'hello world — token: abc123';
		$cipher    = Outpost_Encryption::encrypt( $plaintext );
		$this->assertNotSame( $plaintext, $cipher );
		$this->assertSame( $plaintext, Outpost_Encryption::decrypt( $cipher ) );
	}

	public function test_decrypt_throws_on_corrupt_envelope(): void {
		$this->with_in_memory_option_store();
		$this->expectException( Outpost_Encryption_Exception::class );
		Outpost_Encryption::decrypt( 'this is not a valid envelope' );
	}

	public function test_decrypt_throws_on_too_short_envelope(): void {
		$this->with_in_memory_option_store();
		$this->expectException( Outpost_Encryption_Exception::class );
		Outpost_Encryption::decrypt( base64_encode( 'short' ) );
	}

	public function test_encrypt_twice_produces_distinct_ciphertext(): void {
		$this->with_in_memory_option_store();
		// Random nonce per call; same plaintext encrypts to different envelopes.
		$a = Outpost_Encryption::encrypt( 'same plaintext' );
		$b = Outpost_Encryption::encrypt( 'same plaintext' );
		$this->assertNotSame( $a, $b );
	}

	// --- Key resolver ---------------------------------------------------

	public function test_resolver_generates_and_persists_when_neither_set(): void {
		$this->with_in_memory_option_store();
		$key1 = Outpost_Encryption_Key_Resolver::resolve();
		$this->assertSame( 32, strlen( $key1 ) );
		$this->assertTrue( Outpost_Encryption_Key_Resolver::used_fallback_on_last_resolve() );

		// Second resolve returns the same persisted key.
		$key2 = Outpost_Encryption_Key_Resolver::resolve();
		$this->assertSame( $key1, $key2 );
	}

	// --- Credentials store ---------------------------------------------

	public function test_credentials_set_and_get_roundtrip_per_user(): void {
		$this->with_in_memory_option_store();

		// Per-user storage: encrypted blob in user-meta.
		$user_meta = array();
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing(
			static function ( $s ) {
				return strtolower( preg_replace( '~[^a-z0-9_-]~i', '', (string) $s ) );
			}
		);
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing(
			static function ( $data ) {
				return json_encode( $data );
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

		$creds = array(
			'access_token'   => 'secret-abcdef',
			'workspace_id'   => 'ws-1',
			'workspace_name' => 'Test Workspace',
		);
		$this->assertTrue( Outpost_Credentials_Store::set( 'notion', $creds, 42 ) );

		// is_configured cheap check: presence-only, no decrypt.
		$this->assertTrue( Outpost_Credentials_Store::is_configured( 'notion', 42 ) );

		// get() roundtrips through encrypt → store → fetch → decrypt.
		$out = Outpost_Credentials_Store::get( 'notion', 42 );
		$this->assertIsArray( $out );
		$this->assertSame( 'secret-abcdef', $out['access_token'] );
		$this->assertSame( 'ws-1', $out['workspace_id'] );
	}

	public function test_credentials_user_isolation(): void {
		$this->with_in_memory_option_store();
		$user_meta = array();
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( static function ( $d ) { return json_encode( $d ); } );
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

		Outpost_Credentials_Store::set( 'notion', array( 'token' => 'A' ), 1 );

		// User 2 should see no creds.
		$this->assertFalse( Outpost_Credentials_Store::is_configured( 'notion', 2 ) );
		$this->assertNull( Outpost_Credentials_Store::get( 'notion', 2 ) );
	}

	public function test_credentials_returns_null_on_decryption_failure(): void {
		$this->with_in_memory_option_store();
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
		WP_Mock::userFunction( 'get_user_meta' )->andReturn( 'not-a-valid-cipher-envelope' );
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );

		// Presence check still says yes (raw value exists)…
		$this->assertTrue( Outpost_Credentials_Store::is_configured( 'notion', 42 ) );
		// …but decryption fails and get() returns null cleanly.
		$this->assertNull( Outpost_Credentials_Store::get( 'notion', 42 ) );
	}

	// --- OAuth state ----------------------------------------------------

	public function test_oauth_state_generation_persists_with_ttl(): void {
		$captured = array();
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
		WP_Mock::userFunction( 'set_transient' )->andReturnUsing(
			static function ( $key, $value, $ttl ) use ( &$captured ) {
				$captured = array(
					'key'   => $key,
					'value' => $value,
					'ttl'   => $ttl,
				);
				return true;
			}
		);

		$state = Outpost_OAuth_State::generate( 'notion', 7 );
		$this->assertNotEmpty( $state );
		$this->assertStringStartsWith( 'outpost_oauth_state_', $captured['key'] );
		$this->assertSame( 7, $captured['value']['user_id'] );
		$this->assertSame( 'notion', $captured['value']['provider'] );
		$this->assertSame( 600, $captured['ttl'] );
	}

	public function test_oauth_state_validation_returns_user_id_then_clears(): void {
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
		WP_Mock::userFunction( 'get_transient' )->andReturn(
			array(
				'user_id'  => 7,
				'provider' => 'notion',
			)
		);
		$deleted = false;
		WP_Mock::userFunction( 'delete_transient' )->andReturnUsing(
			static function () use ( &$deleted ) {
				$deleted = true;
				return true;
			}
		);

		$this->assertSame( 7, Outpost_OAuth_State::validate( 'notion', 'some-state-value' ) );
		$this->assertTrue( $deleted, 'state must clear on validation' );
	}

	public function test_oauth_state_validation_rejects_unknown_state(): void {
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		WP_Mock::userFunction( 'delete_transient' )->andReturn( true );

		$this->assertNull( Outpost_OAuth_State::validate( 'notion', 'unknown-state' ) );
	}

	public function test_oauth_state_validation_rejects_provider_mismatch(): void {
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
		WP_Mock::userFunction( 'get_transient' )->andReturn(
			array(
				'user_id'  => 7,
				'provider' => 'github',
			)
		);
		WP_Mock::userFunction( 'delete_transient' )->andReturn( true );

		$this->assertNull( Outpost_OAuth_State::validate( 'notion', 'state-for-other-provider' ) );
	}

	public function test_oauth_state_validation_rejects_empty_candidate(): void {
		$this->assertNull( Outpost_OAuth_State::validate( 'notion', '' ) );
	}

	// --- Notion OAuth provider ------------------------------------------

	public function test_notion_provider_static_metadata(): void {
		$p = new Outpost_OAuth_Provider_Notion();
		$this->assertSame( 'notion', $p->id() );
		$this->assertSame( 'https://api.notion.com/v1/oauth/authorize', $p->authorize_url() );
		$this->assertSame( 'https://api.notion.com/v1/oauth/token', $p->token_url() );
		$this->assertNull( $p->revocation_endpoint() );
		$this->assertSame( array(), $p->scopes() );
	}

	public function test_notion_provider_shape_credentials_preserves_workspace(): void {
		$p     = new Outpost_OAuth_Provider_Notion();
		$creds = $p->shape_credentials(
			array(
				'access_token'   => 'abc',
				'workspace_id'   => 'ws',
				'workspace_name' => 'WS',
				'bot_id'         => 'bot',
				'owner'          => array( 'type' => 'user' ),
			)
		);
		$this->assertSame( 'abc', $creds['access_token'] );
		$this->assertSame( 'ws', $creds['workspace_id'] );
		$this->assertSame( 'WS', $creds['workspace_name'] );
		$this->assertSame( 'bot', $creds['bot_id'] );
		$this->assertSame( array( 'type' => 'user' ), $creds['owner'] );
		$this->assertGreaterThan( 0, $creds['obtained_at'] );
	}

	public function test_notion_provider_shape_handles_missing_optional_fields(): void {
		$p     = new Outpost_OAuth_Provider_Notion();
		$creds = $p->shape_credentials( array( 'access_token' => 'only-token' ) );
		$this->assertSame( 'only-token', $creds['access_token'] );
		$this->assertSame( '', $creds['workspace_id'] );
		$this->assertSame( array(), $creds['owner'] );
	}

	// --- Notion source URL detection ------------------------------------

	public function test_notion_source_capabilities_shape(): void {
		$caps = ( new Outpost_Source_Notion() )->capabilities();
		$this->assertSame( 'notion', $caps['id'] );
		$this->assertSame( 'bookmark', $caps['mode'] );
		$this->assertTrue( (bool) $caps['auth_required'] );
	}

	public function test_notion_source_extracts_dashless_id_from_canonical_url(): void {
		$url = 'https://www.notion.so/My-Page-abcdef0123456789abcdef0123456789';
		$id  = Outpost_Source_Notion::extract_page_id( $url );
		$this->assertSame( 'abcdef0123456789abcdef0123456789', $id );
	}

	public function test_notion_source_extracts_dashed_uuid(): void {
		$url = 'https://www.notion.so/My-Page-abcdef01-2345-6789-abcd-ef0123456789';
		$id  = Outpost_Source_Notion::extract_page_id( $url );
		$this->assertSame( 'abcdef0123456789abcdef0123456789', $id );
	}

	public function test_notion_source_matches_workspace_subdomain(): void {
		$source = new Outpost_Source_Notion();
		$this->assertTrue( $source->matches_url( 'https://example-ws.notion.site/My-Page-abcdef0123456789abcdef0123456789' ) );
	}

	public function test_notion_source_does_not_match_login_page(): void {
		$source = new Outpost_Source_Notion();
		$this->assertFalse( $source->matches_url( 'https://www.notion.so/login' ) );
	}

	public function test_notion_source_does_not_match_other_hosts(): void {
		$source = new Outpost_Source_Notion();
		$this->assertFalse( $source->matches_url( 'https://example.com/page-abcdef0123456789abcdef0123456789' ) );
	}

	// --- Notion blocks converter ----------------------------------------

	public function test_blocks_converter_paragraph_wp_to_notion(): void {
		WP_Mock::userFunction( 'wp_strip_all_tags' )->andReturnUsing(
			static function ( $s ) {
				return preg_replace( '~<[^>]+>~', '', (string) $s );
			}
		);
		WP_Mock::userFunction( 'parse_blocks' )->andReturnUsing(
			static function ( $s ) {
				return array(
					array(
						'blockName' => 'core/paragraph',
						'innerHTML' => '<p>Hello.</p>',
						'attrs'     => array(),
					),
				);
			}
		);
		$out = Outpost_Notion_Blocks_Converter::wp_to_notion( '<p>Hello.</p>' );
		$this->assertCount( 1, $out );
		$this->assertSame( 'paragraph', $out[0]['type'] );
		$this->assertSame( 'Hello.', $out[0]['paragraph']['rich_text'][0]['text']['content'] );
	}

	public function test_blocks_converter_heading_levels_collapse(): void {
		WP_Mock::userFunction( 'wp_strip_all_tags' )->andReturnUsing(
			static function ( $s ) {
				return preg_replace( '~<[^>]+>~', '', (string) $s );
			}
		);
		WP_Mock::userFunction( 'parse_blocks' )->andReturnUsing(
			static function () {
				return array(
					array( 'blockName' => 'core/heading', 'innerHTML' => '<h1>One</h1>', 'attrs' => array( 'level' => 1 ) ),
					array( 'blockName' => 'core/heading', 'innerHTML' => '<h2>Two</h2>', 'attrs' => array( 'level' => 2 ) ),
					array( 'blockName' => 'core/heading', 'innerHTML' => '<h3>Three</h3>', 'attrs' => array( 'level' => 3 ) ),
					array( 'blockName' => 'core/heading', 'innerHTML' => '<h5>Five</h5>', 'attrs' => array( 'level' => 5 ) ),
				);
			}
		);
		$out = Outpost_Notion_Blocks_Converter::wp_to_notion( '...' );
		$this->assertSame( 'heading_1', $out[0]['type'] );
		$this->assertSame( 'heading_2', $out[1]['type'] );
		$this->assertSame( 'heading_3', $out[2]['type'] );
		// h5 collapses to heading_3.
		$this->assertSame( 'heading_3', $out[3]['type'] );
	}

	public function test_blocks_converter_list_expands_into_multiple_items(): void {
		WP_Mock::userFunction( 'wp_strip_all_tags' )->andReturnUsing(
			static function ( $s ) {
				return preg_replace( '~<[^>]+>~', '', (string) $s );
			}
		);
		WP_Mock::userFunction( 'parse_blocks' )->andReturnUsing(
			static function () {
				return array(
					array(
						'blockName' => 'core/list',
						'innerHTML' => '<ul><li>One</li><li>Two</li><li>Three</li></ul>',
						'attrs'     => array(),
					),
				);
			}
		);
		$out = Outpost_Notion_Blocks_Converter::wp_to_notion( '...' );
		$this->assertCount( 3, $out );
		foreach ( $out as $b ) {
			$this->assertSame( 'bulleted_list_item', $b['type'] );
		}
	}

	public function test_blocks_converter_notion_paragraph_to_wp(): void {
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing( static function ( $s ) { return $s; } );
		WP_Mock::userFunction( 'esc_url' )->andReturnUsing( static function ( $s ) { return $s; } );
		$notion = array(
			array(
				'object'    => 'block',
				'type'      => 'paragraph',
				'paragraph' => array(
					'rich_text' => array(
						array( 'plain_text' => 'Hello world.' ),
					),
				),
			),
		);
		$out = Outpost_Notion_Blocks_Converter::notion_to_wp( $notion );
		$this->assertStringContainsString( '<p>Hello world.</p>', $out );
		$this->assertStringContainsString( 'wp:paragraph', $out );
	}
}
