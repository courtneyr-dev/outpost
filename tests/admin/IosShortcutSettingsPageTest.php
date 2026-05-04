<?php
/**
 * Unit tests for Outpost_IOS_Shortcut_Settings_Page (FX).
 *
 * Tests the admin-post action handlers (regenerate / revoke) and
 * the page renderer's data flow. Page HTML output is exercised via
 * output buffering for the key state branches: never-issued token,
 * issued-not-yet-connected, issued-and-connected.
 *
 * @package Outpost\Tests\Admin
 */

declare(strict_types=1);

namespace Outpost\Tests\Admin;

use Outpost_IOS_Shortcut_Settings_Page;
use Outpost_IOS_Shortcut_Token;
use WP_Mock;

final class IosShortcutSettingsPageTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $user_meta = array();

	/** @var array<string, int> */
	private array $token_index = array();

	private int $current_user_id = 42;

	private bool $current_user_can = true;

	public function setUp(): void {
		WP_Mock::setUp();
		$this->user_meta        = array();
		$this->token_index      = array();
		$this->current_user_id  = 42;
		$this->current_user_can = true;
		$_GET                   = array();
		$_POST                  = array();
		if ( ! defined( 'OUTPOST_TESTING_PWA_SHELL' ) ) {
			define( 'OUTPOST_TESTING_PWA_SHELL', true );
		}
		if ( ! defined( 'OUTPOST_PLUGIN_DIR' ) ) {
			define( 'OUTPOST_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
		}

		WP_Mock::userFunction( 'wp_generate_password' )->andReturnUsing(
			static fn ( int $length, bool $special, bool $extra ): string => substr(
				str_replace( array( '+', '/', '=' ), 'A', base64_encode( random_bytes( $length ) ) ),
				0,
				$length
			)
		);
		WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key, bool $single ) {
				$value = $this->user_meta[ $user_id ][ $key ] ?? '';
				return $single ? $value : array( $value );
			}
		);
		WP_Mock::userFunction( 'update_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key, $value ): bool {
				if ( Outpost_IOS_Shortcut_Token::META_KEY === $key ) {
					$this->token_index[ (string) $value ] = $user_id;
				}
				$this->user_meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'delete_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key ): bool {
				unset( $this->user_meta[ $user_id ][ $key ] );
				return true;
			}
		);
		WP_Mock::userFunction( 'get_current_user_id' )->andReturnUsing(
			fn () => $this->current_user_id
		);
		WP_Mock::userFunction( 'current_user_can' )->andReturnUsing(
			fn ( string $cap ) => $this->current_user_can
		);
		WP_Mock::userFunction( 'wp_nonce_field' )->andReturnUsing(
			static fn ( string $action, string $name ): string => "<input name=\"$name\" type=\"hidden\" value=\"nonce-stub\">"
		);
		WP_Mock::userFunction( 'wp_verify_nonce' )->andReturnUsing(
			static fn ( string $nonce, string $action ) => 'nonce-stub' === $nonce ? 1 : false
		);
		WP_Mock::userFunction( 'admin_url' )->andReturnUsing(
			static fn ( string $path = '' ) => 'https://example.com/wp-admin/' . $path
		);
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://example.com' );
		WP_Mock::userFunction( 'add_query_arg' )->andReturnUsing(
			static function ( $key, $value = null, $url = null ) {
				if ( is_array( $key ) ) {
					$query = http_build_query( $key );
					return ( null !== $value ? $value : 'https://example.com/wp-admin/options-general.php' ) . '?' . $query;
				}
				return 'https://example.com/wp-admin/?' . urlencode( (string) $key ) . '=' . urlencode( (string) $value );
			}
		);
		WP_Mock::userFunction( 'wp_safe_redirect' )->andReturn( true );
		WP_Mock::userFunction( 'wp_die' )->andReturnUsing(
			static function ( string $msg ) {
				throw new \RuntimeException( 'wp_die: ' . $msg );
			}
		);
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing(
			static fn ( string $s ) => preg_replace( '/[^a-z0-9_]/', '', strtolower( $s ) )
		);
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static fn ( string $s ) => trim( $s )
		);
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn ( $v ) => $v );
		WP_Mock::userFunction( 'wp_date' )->andReturnUsing(
			static fn ( string $fmt, int $ts ) => gmdate( $fmt, $ts )
		);
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static function ( string $key, $default = false ) {
				if ( 'date_format' === $key ) {
					return 'F j, Y';
				}
				if ( 'time_format' === $key ) {
					return 'g:i a';
				}
				return $default;
			}
		);

		Outpost_IOS_Shortcut_Token::set_resolver_for_tests(
			fn ( string $presented ): ?int => $this->token_index[ $presented ] ?? null
		);
	}

	public function tearDown(): void {
		Outpost_IOS_Shortcut_Token::set_resolver_for_tests( null );
		WP_Mock::tearDown();
		$_GET  = array();
		$_POST = array();
	}

	private function render(): string {
		ob_start();
		Outpost_IOS_Shortcut_Settings_Page::render();
		return (string) ob_get_clean();
	}

	// --- render branches -------------------------------------------------

	public function test_render_emits_settings_page_heading(): void {
		$html = $this->render();
		$this->assertStringContainsString( '<h1>', $html );
		$this->assertStringContainsString( 'iOS Shortcut Bridge', $html );
	}

	public function test_render_shows_no_token_state_initially(): void {
		$html = $this->render();
		$this->assertStringContainsString( 'No token issued yet.', $html );
		$this->assertStringContainsString( 'Generate token', $html );
	}

	public function test_render_shows_existing_token(): void {
		$token = Outpost_IOS_Shortcut_Token::regenerate( $this->current_user_id );

		$html = $this->render();

		$this->assertStringContainsString( $token, $html );
		$this->assertStringContainsString( 'Regenerate token', $html );
	}

	public function test_render_shows_not_connected_when_no_first_seen(): void {
		Outpost_IOS_Shortcut_Token::regenerate( $this->current_user_id );

		$html = $this->render();

		$this->assertStringContainsString( 'Not connected yet.', $html );
	}

	public function test_render_shows_connected_when_first_seen_set(): void {
		Outpost_IOS_Shortcut_Token::regenerate( $this->current_user_id );
		$this->user_meta[ $this->current_user_id ][ Outpost_IOS_Shortcut_Token::FIRST_SEEN_META_KEY ] = '2026-05-04T12:00:00+00:00';

		$html = $this->render();

		$this->assertStringContainsString( 'Connected.', $html );
		$this->assertStringContainsString( 'First successful share', $html );
	}

	public function test_render_includes_site_url(): void {
		$html = $this->render();
		$this->assertStringContainsString( 'https://example.com', $html );
		$this->assertStringContainsString( '/wp-json/outpost/v1/shortcut', $html );
	}

	public function test_render_handles_placeholder_icloud_link(): void {
		// The shipped link file is a placeholder until the Shortcut is published.
		$html = $this->render();
		$this->assertStringContainsString( 'iCloud Shortcut link is not yet published', $html );
	}

	public function test_render_emits_revoke_form_when_token_present(): void {
		Outpost_IOS_Shortcut_Token::regenerate( $this->current_user_id );

		$html = $this->render();

		$this->assertStringContainsString( 'Revoke token', $html );
	}

	public function test_render_omits_revoke_form_when_no_token(): void {
		$html = $this->render();
		$this->assertStringNotContainsString( 'Revoke token', $html );
	}

	// --- regenerate handler ---------------------------------------------

	public function test_handle_regenerate_creates_token_with_valid_nonce(): void {
		$_POST[ Outpost_IOS_Shortcut_Settings_Page::NONCE_NAME ] = 'nonce-stub';

		Outpost_IOS_Shortcut_Settings_Page::handle_regenerate();

		$this->assertNotNull( Outpost_IOS_Shortcut_Token::get_token( $this->current_user_id ) );
	}

	public function test_handle_regenerate_dies_on_invalid_nonce(): void {
		$_POST[ Outpost_IOS_Shortcut_Settings_Page::NONCE_NAME ] = 'bad-nonce';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Security check failed' );
		Outpost_IOS_Shortcut_Settings_Page::handle_regenerate();
	}

	public function test_handle_regenerate_dies_when_capability_missing(): void {
		$_POST[ Outpost_IOS_Shortcut_Settings_Page::NONCE_NAME ] = 'nonce-stub';
		$this->current_user_can                                  = false;

		$this->expectException( \RuntimeException::class );
		Outpost_IOS_Shortcut_Settings_Page::handle_regenerate();
	}

	public function test_handle_revoke_clears_token(): void {
		Outpost_IOS_Shortcut_Token::regenerate( $this->current_user_id );
		$_POST[ Outpost_IOS_Shortcut_Settings_Page::NONCE_NAME ] = 'nonce-stub';

		Outpost_IOS_Shortcut_Settings_Page::handle_revoke();

		$this->assertNull( Outpost_IOS_Shortcut_Token::get_token( $this->current_user_id ) );
	}

	public function test_render_dies_for_user_without_capability(): void {
		$this->current_user_can = false;

		$this->expectException( \RuntimeException::class );
		Outpost_IOS_Shortcut_Settings_Page::render();
	}

	// --- notice branches -------------------------------------------------

	public function test_render_emits_regenerated_notice(): void {
		$_GET['notice'] = 'regenerated';

		$html = $this->render();

		$this->assertStringContainsString( 'Token regenerated', $html );
	}

	public function test_render_emits_revoked_notice(): void {
		$_GET['notice'] = 'revoked';

		$html = $this->render();

		$this->assertStringContainsString( 'Token revoked', $html );
	}

	public function test_render_ignores_unknown_notice(): void {
		$_GET['notice'] = 'malicious-script-injection';

		$html = $this->render();

		$this->assertStringNotContainsString( 'malicious-script-injection', $html );
	}
}
