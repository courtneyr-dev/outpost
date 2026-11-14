<?php
/**
 * Unit tests for Outpost_IOS_Shortcut_Settings_Page.
 *
 * The page creates and revokes the person's Shortcut application password
 * through core. A new password is printed once in the response that created
 * it; Outpost stores nothing. The POST handler checks the capability, then
 * the nonce, and only then reads the requested action.
 *
 * @package Outpost\Tests\Admin
 */

declare(strict_types=1);

namespace Outpost\Tests\Admin;

use Outpost_IOS_Shortcut_App_Password;
use Outpost_IOS_Shortcut_Settings_Page;
use WP_Application_Passwords;
use WP_Mock;

require_once dirname( __DIR__ ) . '/auth/application-passwords-stub.php';

final class IosShortcutSettingsPageTest extends \WP_Mock\Tools\TestCase {

	private bool $can = true;

	private bool $available = true;

	/** @var string[] Order of guard calls. */
	private array $calls = array();

	public function setUp(): void {
		WP_Mock::setUp();
		WP_Application_Passwords::reset();
		Outpost_IOS_Shortcut_Settings_Page::reset_for_tests();
		$this->can       = true;
		$this->available = true;
		$this->calls     = array();
		$_POST           = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$user             = new \WP_User();
		$user->ID         = 7;
		$user->user_login = 'author';

		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		WP_Mock::userFunction( 'wp_get_current_user' )->andReturn( $user );
		WP_Mock::userFunction( 'get_userdata' )->andReturn( $user );
		WP_Mock::userFunction( 'wp_is_application_passwords_available_for_user' )->andReturnUsing( fn() => $this->available );
		WP_Mock::userFunction( 'current_user_can' )->andReturnUsing(
			function () {
				$this->calls[] = 'cap';
				return $this->can;
			}
		);
		WP_Mock::userFunction( 'check_admin_referer' )->andReturnUsing(
			function ( $action, $name ) {
				$this->calls[] = 'nonce';
				$this->assertSame( 'outpost_ios_shortcut_password', $action );
				$this->assertSame( 'outpost_ios_shortcut_nonce', $name );
				return 1;
			}
		);
		WP_Mock::userFunction( 'wp_die' )->andReturnUsing(
			static function ( $msg ) {
				throw new \RuntimeException( 'wp_die: ' . $msg );
			}
		);
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static fn( $s ) => preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $s ) ) );
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $s ) => trim( (string) $s ) );
		WP_Mock::userFunction( 'home_url' )->andReturn( 'https://example.com' );
		WP_Mock::userFunction( 'wp_nonce_field' )->andReturnUsing(
			static function () {
				echo '<input type="hidden" name="outpost_ios_shortcut_nonce" value="n">';
			}
		);
		WP_Mock::userFunction( 'wp_date' )->andReturnUsing( static fn( $fmt, $ts ) => gmdate( 'Y-m-d', (int) $ts ) );
		WP_Mock::userFunction( 'get_option' )->andReturnUsing( static fn( $k, $d = false ) => $d );
		WP_Mock::passthruFunction( '__' );
		WP_Mock::passthruFunction( 'esc_html__' );
		WP_Mock::passthruFunction( 'esc_attr' );
		WP_Mock::passthruFunction( 'esc_url' );
		WP_Mock::passthruFunction( 'esc_html' );
		WP_Mock::userFunction( 'esc_html_e' )->andReturnUsing(
			static function ( $s ) {
				echo $s; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
			}
		);
	}

	public function tearDown(): void {
		$_POST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		WP_Mock::tearDown();
	}

	private function render(): string {
		ob_start();
		Outpost_IOS_Shortcut_Settings_Page::render();
		return (string) ob_get_clean();
	}

	public function test_get_request_changes_nothing(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST[ Outpost_IOS_Shortcut_Settings_Page::ACTION_FIELD ] = 'create';
		Outpost_IOS_Shortcut_Settings_Page::handle_post();
		$this->assertSame( array(), WP_Application_Passwords::$store[7] ?? array() );
		$this->assertSame( array(), $this->calls, 'A GET must not reach the guards or the handler.' );
	}

	public function test_capability_is_checked_before_the_nonce_and_before_input(): void {
		$this->can = false;
		$_POST[ Outpost_IOS_Shortcut_Settings_Page::ACTION_FIELD ] = 'create';
		try {
			Outpost_IOS_Shortcut_Settings_Page::handle_post();
			$this->fail( 'Expected wp_die for a user without manage_options.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'wp_die', $e->getMessage() );
		}
		$this->assertSame( array( 'cap' ), $this->calls, 'Nothing may run after the capability check fails.' );
		$this->assertSame( array(), WP_Application_Passwords::$store[7] ?? array(), 'No password may be created.' );
	}

	public function test_create_runs_after_cap_and_nonce_and_shows_the_password_once(): void {
		$_POST[ Outpost_IOS_Shortcut_Settings_Page::ACTION_FIELD ] = 'create';
		Outpost_IOS_Shortcut_Settings_Page::handle_post();

		$this->assertSame( array( 'cap', 'nonce' ), array_slice( $this->calls, 0, 2 ), 'Capability, then nonce.' );
		$this->assertSame( Outpost_IOS_Shortcut_App_Password::APP_ID, WP_Application_Passwords::$store[7][0]['app_id'] ?? null );

		$html = $this->render();
		$this->assertStringContainsString( 'plain-text-password', $html );
		$this->assertStringContainsString( 'It will not be shown again', $html );

		Outpost_IOS_Shortcut_Settings_Page::reset_for_tests();
		$this->assertStringNotContainsString( 'plain-text-password', $this->render(), 'A later page load must not show the password again.' );
	}

	public function test_revoke_deletes_the_outpost_password(): void {
		WP_Application_Passwords::$store[7] = array(
			array(
				'uuid'      => 'x',
				'app_id'    => Outpost_IOS_Shortcut_App_Password::APP_ID,
				'created'   => 1700000000,
				'last_used' => null,
			),
		);
		$_POST[ Outpost_IOS_Shortcut_Settings_Page::ACTION_FIELD ] = 'revoke';
		Outpost_IOS_Shortcut_Settings_Page::handle_post();
		$this->assertSame( array(), WP_Application_Passwords::$store[7] );
		$this->assertStringContainsString( 'Shortcut password revoked', $this->render() );
	}

	public function test_page_reports_created_and_last_used_dates(): void {
		WP_Application_Passwords::$store[7] = array(
			array(
				'uuid'      => 'x',
				'app_id'    => Outpost_IOS_Shortcut_App_Password::APP_ID,
				'created'   => 1700000000,
				'last_used' => 1700086400,
			),
		);
		$html = $this->render();
		$this->assertStringContainsString( 'Created 2023-11-14.', $html );
		$this->assertStringContainsString( 'Last used 2023-11-15.', $html );
		$this->assertStringContainsString( 'Replace Shortcut password', $html );
		$this->assertStringContainsString( 'author', $html, 'The page names the user the Shortcut signs in as.' );
	}

	public function test_page_explains_when_application_passwords_are_unavailable(): void {
		$this->available = false;
		$html            = $this->render();
		$this->assertStringContainsString( 'application passwords are not available', $html );
		$this->assertStringNotContainsString( 'Create Shortcut password', $html, 'No create button when core has application passwords off.' );
	}
}
