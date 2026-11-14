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

	private bool $multisite = false;

	/** @var array<string, int> */
	private array $sitewide = array();

	/** @var array<string, mixed> */
	private array $site_options = array();

	public function setUp(): void {
		WP_Mock::setUp();
		WP_Application_Passwords::reset();
		$this->options      = array();
		$this->deleted_meta = array();
		$GLOBALS['wp']      = (object) array( 'query_vars' => array() );
		$this->multisite    = false;
		$this->sitewide     = array();
		WP_Mock::passthruFunction( '__' );
		WP_Mock::userFunction( 'is_multisite' )->andReturnUsing( fn (): bool => $this->multisite );
		$this->site_options = array();
		WP_Mock::userFunction( 'get_site_option' )->andReturnUsing(
			fn ( $name, $fallback = false ) => 'active_sitewide_plugins' === $name ? $this->sitewide : ( $this->site_options[ $name ] ?? $fallback )
		);
		WP_Mock::userFunction( 'update_site_option' )->andReturnUsing(
			function ( $name, $value ): bool {
				$this->site_options[ $name ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'get_users' )->andReturnUsing( static fn (): array => array_keys( WP_Application_Passwords::$store ) );
		if ( ! defined( 'OUTPOST_PLUGIN_BASENAME' ) ) {
			define( 'OUTPOST_PLUGIN_BASENAME', 'outpost-mobile-publishing/outpost.php' );
		}
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
		// T10-S4-13 (Phase 4 C-06): the one-time purge runs on the first
		// request after upgrade, not only after someone opens wp-admin.
		$this->assertSame(
			array(
				array( Outpost_IOS_Shortcut_App_Password::class, 'purge_legacy_tokens' ),
				array( Outpost_IOS_Shortcut_App_Password::class, 'sweep_unscoped' ),
			),
			array_column( outpost_test_actions_for( 'init' ), 'callback' )
		);
		$this->assertSame( array(), outpost_test_actions_for( 'admin_init' ), 'No admin-only purge path.' );
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

	/**
	 * T10-S4-20 (re-review R-03): on multisite the password is network-wide,
	 * but the rule that limits it runs only where Outpost is active. With
	 * per-site activation no deactivation choice is safe, so the password is
	 * offered only when Outpost is network-activated.
	 */
	public function test_per_site_activation_on_multisite_offers_no_password(): void {
		$this->given_available( true );
		$this->multisite = true;

		$this->assertFalse( Outpost_IOS_Shortcut_App_Password::is_available( 7 ) );
		$created = Outpost_IOS_Shortcut_App_Password::create( 7 );
		$this->assertInstanceOf( \WP_Error::class, $created );
		$this->assertSame( 'outpost_shortcut_password_network', $created->get_error_code() );
		$this->assertSame( array(), WP_Application_Passwords::$store[7] ?? array(), 'No password is created.' );
	}

	/**
	 * T10-S4-24 (re-review 2): a password created before the network guard
	 * existed would stay unscoped on a per-site-activated network. A one-time
	 * network-wide sweep removes Outpost's passwords there and leaves other
	 * application passwords alone.
	 */
	public function test_a_per_site_network_sweeps_existing_shortcut_passwords_once(): void {
		$this->multisite                    = true;
		WP_Application_Passwords::$store[7] = array( $this->outpost_item(), array( 'uuid' => 'mobile-app', 'app_id' => '' ) );
		WP_Application_Passwords::$store[8] = array( array( 'uuid' => 'u8', 'app_id' => Outpost_IOS_Shortcut_App_Password::APP_ID ) );

		Outpost_IOS_Shortcut_App_Password::sweep_unscoped();

		$this->assertSame( array( 'mobile-app' ), array_column( WP_Application_Passwords::$store[7], 'uuid' ) );
		$this->assertSame( array(), WP_Application_Passwords::$store[8] );

		WP_Application_Passwords::$store[9] = array( array( 'uuid' => 'u9', 'app_id' => Outpost_IOS_Shortcut_App_Password::APP_ID ) );
		Outpost_IOS_Shortcut_App_Password::sweep_unscoped();
		$this->assertCount( 1, WP_Application_Passwords::$store[9], 'The sweep runs once per network.' );
	}

	/**
	 * @return array<string, array{0: bool, 1: array<string, int>}>
	 */
	public function scoped_installs(): array {
		return array(
			'single site'          => array( false, array() ),
			'network-activated'    => array( true, array( 'outpost-mobile-publishing/outpost.php' => 1 ) ),
		);
	}

	/**
	 * @dataProvider scoped_installs
	 *
	 * @param array<string, int> $sitewide Network-active plugins.
	 */
	public function test_a_scoped_install_sweeps_nothing( bool $multisite, array $sitewide ): void {
		$this->multisite                    = $multisite;
		$this->sitewide                     = $sitewide;
		WP_Application_Passwords::$store[7] = array( $this->outpost_item() );

		Outpost_IOS_Shortcut_App_Password::sweep_unscoped();

		$this->assertCount( 1, WP_Application_Passwords::$store[7] );
	}

	public function test_network_activation_on_multisite_offers_the_password(): void {
		$this->given_available( true );
		$this->multisite = true;
		$this->sitewide  = array( OUTPOST_PLUGIN_BASENAME => 1 );

		$this->assertTrue( Outpost_IOS_Shortcut_App_Password::is_available( 7 ) );
	}

	public function test_a_single_site_offers_the_password(): void {
		$this->given_available( true );

		$this->assertTrue( Outpost_IOS_Shortcut_App_Password::is_available( 7 ) );
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
