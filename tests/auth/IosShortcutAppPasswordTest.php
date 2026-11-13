<?php
/**
 * Unit tests for Outpost_IOS_Shortcut_App_Password.
 *
 * The iOS Shortcut signs in with a core WordPress Application Password.
 * Outpost never maps a credential to a user itself; it only limits its own
 * password to the Shortcut route through core's
 * `wp_authenticate_application_password_errors` hook.
 *
 * @package Outpost\Tests\Auth
 */

declare(strict_types=1);

namespace Outpost\Tests\Auth;

use Outpost_IOS_Shortcut_App_Password;
use WP_Application_Passwords;
use WP_Mock;

require_once __DIR__ . '/application-passwords-stub.php';

final class IosShortcutAppPasswordTest extends \WP_Mock\Tools\TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var string[] */
	private array $deleted_meta = array();

	public function setUp(): void {
		WP_Mock::setUp();
		WP_Application_Passwords::reset();
		$this->options      = array();
		$this->deleted_meta = array();
		$GLOBALS['wp']      = (object) array( 'query_vars' => array() );
		WP_Mock::passthruFunction( '__' );
	}

	public function tearDown(): void {
		unset( $GLOBALS['wp'] );
		WP_Mock::tearDown();
	}

	private function resolve_route( string $route ): void {
		$GLOBALS['wp']->query_vars['rest_route'] = $route;
	}

	private function outpost_item(): array {
		return array(
			'uuid'   => 'u1',
			'app_id' => Outpost_IOS_Shortcut_App_Password::APP_ID,
		);
	}

	private function given_available( bool $available ): void {
		$user             = new \WP_User();
		$user->ID         = 7;
		$user->user_login = 'author';
		WP_Mock::userFunction( 'get_userdata' )->andReturn( $user );
		WP_Mock::userFunction( 'wp_is_application_passwords_available_for_user' )->andReturn( $available );
	}

	public function test_registers_on_core_application_password_hook_and_not_determine_current_user(): void {
		outpost_test_reset_hooks();
		Outpost_IOS_Shortcut_App_Password::register();
		$this->assertSame(
			array( array( Outpost_IOS_Shortcut_App_Password::class, 'restrict_to_shortcut_route' ) ),
			array_column( outpost_test_actions_for( 'wp_authenticate_application_password_errors' ), 'callback' )
		);
		$this->assertSame(
			array( array( Outpost_IOS_Shortcut_App_Password::class, 'purge_legacy_tokens' ) ),
			array_column( outpost_test_actions_for( 'admin_init' ), 'callback' )
		);
		$this->assertFalse(
			method_exists( Outpost_IOS_Shortcut_App_Password::class, 'determine_current_user' ),
			'Outpost must not resolve users itself.'
		);
		$this->assertFalse( class_exists( 'Outpost_IOS_Shortcut_Token_Authenticator', false ), 'The custom token authenticator must stay removed.' );
	}

	/**
	 * @dataProvider provide_other_routes
	 */
	public function test_outpost_password_is_refused_off_the_shortcut_route( ?string $route ): void {
		if ( null !== $route ) {
			$this->resolve_route( $route );
		}
		$error = new \WP_Error();
		Outpost_IOS_Shortcut_App_Password::restrict_to_shortcut_route( $error, new \WP_User(), $this->outpost_item() );
		$this->assertSame( array( 'outpost_shortcut_password_out_of_scope' ), $error->get_error_codes() );
		$this->assertSame( 401, $error->get_error_data( 'outpost_shortcut_password_out_of_scope' )['status'] ?? null );
	}

	/**
	 * @return array<string, array{0: string|null}>
	 */
	public static function provide_other_routes(): array {
		return array(
			'core users route'           => array( '/wp/v2/users/me' ),
			'another Outpost route'      => array( '/outpost/v1/composer-config' ),
			'shortcut prefix lookalike'  => array( '/outpost/v1/shortcut-extra' ),
			'no REST route (XML-RPC)'    => array( null ),
		);
	}

	public function test_outpost_password_is_accepted_on_the_shortcut_route(): void {
		$this->resolve_route( '/outpost/v1/shortcut' );
		$error = new \WP_Error();
		Outpost_IOS_Shortcut_App_Password::restrict_to_shortcut_route( $error, new \WP_User(), $this->outpost_item() );
		$this->assertFalse( $error->has_errors() );
	}

	public function test_other_application_passwords_are_untouched(): void {
		$this->resolve_route( '/wp/v2/users/me' );
		$error = new \WP_Error();
		Outpost_IOS_Shortcut_App_Password::restrict_to_shortcut_route(
			$error,
			new \WP_User(),
			array(
				'uuid'   => 'u2',
				'app_id' => '',
			)
		);
		$this->assertFalse( $error->has_errors(), 'A password the person made for another app must keep working everywhere.' );
	}

	public function test_create_replaces_only_outposts_password(): void {
		$this->given_available( true );
		WP_Application_Passwords::$store[7] = array(
			array(
				'uuid'   => 'old-outpost',
				'app_id' => Outpost_IOS_Shortcut_App_Password::APP_ID,
			),
			array(
				'uuid'   => 'mobile-app',
				'app_id' => '',
			),
		);
		$this->assertSame( 'plain-text-password', Outpost_IOS_Shortcut_App_Password::create( 7 ) );

		$uuids = array_column( WP_Application_Passwords::$store[7], 'uuid' );
		$this->assertNotContains( 'old-outpost', $uuids, 'The previous Outpost password must stop working.' );
		$this->assertContains( 'mobile-app', $uuids, 'Unrelated passwords must survive.' );
		$this->assertSame( Outpost_IOS_Shortcut_App_Password::APP_ID, Outpost_IOS_Shortcut_App_Password::find( 7 )['app_id'] ?? null );
	}

	public function test_create_refuses_when_core_has_application_passwords_off(): void {
		$this->given_available( false );
		$result = Outpost_IOS_Shortcut_App_Password::create( 7 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), WP_Application_Passwords::$store[7] ?? array(), 'No password may be created.' );
	}

	public function test_revoke_deletes_every_outpost_password_and_nothing_else(): void {
		WP_Application_Passwords::$store[7] = array(
			array(
				'uuid'   => 'a',
				'app_id' => Outpost_IOS_Shortcut_App_Password::APP_ID,
			),
			array(
				'uuid'   => 'b',
				'app_id' => Outpost_IOS_Shortcut_App_Password::APP_ID,
			),
			array(
				'uuid'   => 'c',
				'app_id' => 'someone-else',
			),
		);
		Outpost_IOS_Shortcut_App_Password::revoke( 7 );
		$this->assertSame( array( 'c' ), array_column( WP_Application_Passwords::$store[7], 'uuid' ) );
		$this->assertNull( Outpost_IOS_Shortcut_App_Password::find( 7 ) );
	}

	public function test_purge_legacy_tokens_runs_once(): void {
		WP_Mock::userFunction( 'get_option' )->andReturnUsing( fn( $name ) => $this->options[ $name ] ?? false );
		WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'delete_metadata' )->andReturnUsing(
			function ( $type, $object_id, $key, $value, $all ) {
				$this->assertSame( 'user', $type );
				$this->assertTrue( $all, 'Legacy tokens must be deleted for every user.' );
				$this->deleted_meta[] = $key;
				return true;
			}
		);

		Outpost_IOS_Shortcut_App_Password::purge_legacy_tokens();
		Outpost_IOS_Shortcut_App_Password::purge_legacy_tokens();

		$this->assertSame( array( 'outpost_ios_shortcut_token', 'outpost_ios_shortcut_first_seen' ), $this->deleted_meta, 'Purge runs once and covers both legacy keys.' );
	}

	public function test_uninstaller_deletes_the_same_app_id(): void {
		$const = new \ReflectionClassConstant( \Outpost_Uninstaller::class, 'SHORTCUT_APP_ID' );
		$this->assertSame(
			Outpost_IOS_Shortcut_App_Password::APP_ID,
			$const->getValue(),
			'uninstall.php must delete exactly the passwords Outpost creates.'
		);
	}
}
