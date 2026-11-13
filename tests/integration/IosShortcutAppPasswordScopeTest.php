<?php
/**
 * Integration test: the iOS Shortcut application password is limited to the
 * Shortcut route, through core's own authentication.
 *
 * Creates a real WordPress Application Password with Outpost's app_id and runs
 * core's `wp_authenticate_application_password()` for different resolved REST
 * routes. Outpost adds an error only through
 * `wp_authenticate_application_password_errors`; it never resolves a user
 * itself. A password the person made for another app is the control: it must
 * keep working on every route.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class IosShortcutAppPasswordScopeTest extends TestCase {

	private int $user_id = 0;

	private string $login = '';

	/** @var mixed */
	private $saved_wp = null;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_insert_user' ) || ! class_exists( 'WP_Application_Passwords' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap. Run via `npm run test:integration` inside wp-env tests-cli.' );
		}
		$this->saved_wp = $GLOBALS['wp'] ?? null;
		$this->login    = 'shortcut_author_' . uniqid();
		$this->user_id  = (int) wp_insert_user(
			array(
				'user_login' => $this->login,
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => $this->login . '@example.test',
				'role'       => 'author',
			)
		);
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available_for_user', '__return_true' );
		add_filter( 'application_password_is_api_request', '__return_true' );
	}

	protected function tearDown(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'wp_is_application_passwords_available_for_user', '__return_true' );
		remove_filter( 'application_password_is_api_request', '__return_true' );
		// A successful application-password login makes core record the user
		// in these globals (rest_application_password_collect_status), and
		// core's priority-90 rest_authentication_errors check then returns
		// true for every later request in this process. Clear them so the
		// next test starts unauthenticated.
		$GLOBALS['wp_rest_application_password_status'] = null;
		$GLOBALS['wp_rest_application_password_uuid']   = null;
		$GLOBALS['wp'] = $this->saved_wp;
		if ( $this->user_id > 0 ) {
			wp_delete_user( $this->user_id );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function resolve( string $route ): void {
		$wp = new \WP();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test harness models the parsed request.
		$wp->query_vars = array( 'rest_route' => $route );
		$GLOBALS['wp']  = $wp;
	}

	/**
	 * @test
	 */
	public function outpost_password_authenticates_on_the_shortcut_route(): void {
		$password = \Outpost_IOS_Shortcut_App_Password::create( $this->user_id );
		$this->assertIsString( $password );

		$this->resolve( '/outpost/v1/shortcut' );
		$user = wp_authenticate_application_password( null, $this->login, $password );

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( $this->user_id, $user->ID );
	}

	/**
	 * @test
	 */
	public function outpost_password_is_refused_on_any_other_route(): void {
		$password = \Outpost_IOS_Shortcut_App_Password::create( $this->user_id );

		foreach ( array( '/wp/v2/users/me', '/wp/v2/posts', '/outpost/v1/composer-config', '/micropub/1.0/media' ) as $route ) {
			$this->resolve( $route );
			$result = wp_authenticate_application_password( null, $this->login, $password );
			$this->assertInstanceOf( \WP_Error::class, $result, "The Shortcut password must not authenticate {$route}." );
			$this->assertContains( 'outpost_shortcut_password_out_of_scope', $result->get_error_codes(), $route );
		}
	}

	/**
	 * @test
	 */
	public function other_application_passwords_keep_working_everywhere(): void {
		$created = \WP_Application_Passwords::create_new_application_password( $this->user_id, array( 'name' => 'Mobile app' ) );
		$this->assertIsArray( $created );

		$this->resolve( '/wp/v2/users/me' );
		$user = wp_authenticate_application_password( null, $this->login, $created[0] );

		$this->assertInstanceOf( \WP_User::class, $user, 'Outpost must not affect passwords made for other apps.' );
	}

	/**
	 * @test
	 */
	public function replacing_the_password_stops_the_old_one(): void {
		$old = \Outpost_IOS_Shortcut_App_Password::create( $this->user_id );
		$new = \Outpost_IOS_Shortcut_App_Password::create( $this->user_id );

		$this->resolve( '/outpost/v1/shortcut' );
		$this->assertInstanceOf( \WP_Error::class, wp_authenticate_application_password( null, $this->login, $old ) );
		$this->assertInstanceOf( \WP_User::class, wp_authenticate_application_password( null, $this->login, $new ) );
		$this->assertCount( 1, \WP_Application_Passwords::get_user_application_passwords( $this->user_id ) );
	}

	/**
	 * @test
	 */
	public function uninstall_deletes_only_the_outpost_password(): void {
		\Outpost_IOS_Shortcut_App_Password::create( $this->user_id );
		\WP_Application_Passwords::create_new_application_password( $this->user_id, array( 'name' => 'Mobile app' ) );

		\Outpost_Uninstaller::delete_shortcut_application_passwords();

		$left = \WP_Application_Passwords::get_user_application_passwords( $this->user_id );
		$this->assertSame( array( 'Mobile app' ), array_column( $left, 'name' ) );
	}
}
