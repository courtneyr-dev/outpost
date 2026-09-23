<?php
/**
 * Integration test: bearer-token scope enforcement (H6/H7).
 *
 * Companion to ComposerConfigPermissionTest.php's bearer-in-body coverage,
 * focused on the scope gate H6 added and H7's fix-round-1 corrected: an
 * IndieAuth-authenticated bearer request must carry a token scoped for
 * `create`/`update` (or, on a read-only route, also `read`) — an
 * under-scoped token is refused even though the underlying user can
 * `edit_posts`.
 *
 * Runs against `/wp-json/outpost/v1/composer-config` (read-only; accepts
 * `create`, `update`, or `read`), the same route ComposerConfigPermissionTest
 * exercises. Two transports, matching the PWA's own belt-and-suspenders
 * behavior and IndieAuth's own token-detection order: the Authorization
 * header, and the Micropub-spec form-encoded `access_token` body param
 * IndieAuth's `get_provided_token()` also reads directly (both of which,
 * on a real IndieAuth-active site, are typically already resolved —
 * `is_user_logged_in()` already true — by the time this route's
 * permission_callback runs, since `determine_current_user` fires globally
 * at priority 15 during WordPress's normal early current-user resolution).
 *
 * Like ComposerConfigPermissionTest::body_token_authenticates_when_the_header_is_stripped(),
 * this stands in for IndieAuth's `determine_current_user` validator and its
 * `indieauth_scopes` filter with test doubles rather than a live IndieAuth
 * token exchange — dispatch() doesn't run the plugin's OAuth flow, and a
 * live token would need a running IndieAuth authorization server. It still
 * exercises the real REST dispatch, the real `Outpost_Bearer_Auth` trait, and
 * the real `indieauth_scopes` filter tag, which is what H6/H7 actually gate on.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * @coversNothing
 */
final class BearerScopePermissionTest extends TestCase {

	private int $editor_id = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! $this->integration_environment_ready() ) {
			$this->markTestSkipped(
				'Skipped under unit bootstrap. Run via `npm run test:integration` inside wp-env tests-cli.'
			);
		}

		$this->editor_id = (int) wp_insert_user(
			array(
				'user_login' => 'scope_ed_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'scope_ed_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
	}

	protected function tearDown(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( $this->editor_id > 0 ) {
			delete_transient( 'outpost_config_rl_u_' . $this->editor_id );
			wp_delete_user( $this->editor_id );
		}
		$this->editor_id = 0;
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function integration_environment_ready(): bool {
		return function_exists( 'wp_insert_user' )
			&& class_exists( 'Outpost_Composer_Config_Endpoint' );
	}

	/**
	 * Stand in for IndieAuth's determine_current_user validator (reads the
	 * Authorization header, exactly as class-authorize.php's
	 * get_provided_token() does after Outpost_Bearer_Auth restores a
	 * stripped/body-carried token onto it) plus its indieauth_scopes filter
	 * (Authorize::get_indieauth_scopes(), the source Outpost_Bearer_Auth::
	 * bearer_has_scope() reads).
	 *
	 * @param string[] $scopes Scopes the stand-in token carries.
	 */
	private function install_indieauth_stand_in( array $scopes ): callable {
		$editor    = $this->editor_id;
		$validator = static function ( $user ) use ( $editor ) {
			if ( $user ) {
				return $user;
			}
			$auth = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : '';
			return ( false !== stripos( $auth, 'Bearer scoped-editor-token' ) ) ? $editor : $user;
		};
		add_filter( 'determine_current_user', $validator, 15 );
		add_filter(
			'indieauth_scopes',
			static function ( $existing ) use ( $scopes ) {
				return $existing ? $existing : $scopes;
			},
			9
		);
		return $validator;
	}

	private function composer_config_header_request(): WP_REST_Request {
		$request                       = new WP_REST_Request( 'GET', '/outpost/v1/composer-config' );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer scoped-editor-token'; // outpost-lint:fixture-credential
		return $request;
	}

	private function composer_config_form_token_request(): WP_REST_Request {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$request = new WP_REST_Request( 'POST', '/outpost/v1/composer-config' );
		$request->set_body_params( array( 'access_token' => 'scoped-editor-token' ) ); // outpost-lint:fixture-credential
		return $request;
	}

	/**
	 * @test
	 */
	public function header_token_with_insufficient_scope_is_refused(): void {
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$validator = $this->install_indieauth_stand_in( array( 'profile' ) );

		try {
			$response = rest_get_server()->dispatch( $this->composer_config_header_request() );
		} finally {
			remove_filter( 'determine_current_user', $validator, 15 );
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
			wp_set_current_user( 0 );
		}

		$this->assertSame(
			403,
			$response->get_status(),
			'A validated bearer user without create/update/read scope is authenticated but forbidden.'
		);
	}

	/**
	 * @test
	 */
	public function header_token_with_sufficient_scope_is_allowed(): void {
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		delete_transient( 'outpost_config_rl_u_' . $this->editor_id );
		$validator = $this->install_indieauth_stand_in( array( 'read' ) );

		try {
			$response = rest_get_server()->dispatch( $this->composer_config_header_request() );
		} finally {
			remove_filter( 'determine_current_user', $validator, 15 );
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
			wp_set_current_user( 0 );
		}

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'companions', (array) $response->get_data() );
	}

	/**
	 * @test
	 */
	public function form_encoded_token_with_insufficient_scope_is_refused(): void {
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$validator = $this->install_indieauth_stand_in( array( 'profile' ) );

		try {
			$response = rest_get_server()->dispatch( $this->composer_config_form_token_request() );
		} finally {
			remove_filter( 'determine_current_user', $validator, 15 );
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
			wp_set_current_user( 0 );
		}

		$this->assertSame(
			403,
			$response->get_status(),
			'A form-encoded token variant is refused the same way as the header variant.'
		);
	}

	/**
	 * @test
	 */
	public function form_encoded_token_with_sufficient_scope_is_allowed(): void {
		wp_set_current_user( 0 );
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		delete_transient( 'outpost_config_rl_u_' . $this->editor_id );
		$validator = $this->install_indieauth_stand_in( array( 'create' ) );

		try {
			$response = rest_get_server()->dispatch( $this->composer_config_form_token_request() );
		} finally {
			remove_filter( 'determine_current_user', $validator, 15 );
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
			wp_set_current_user( 0 );
		}

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'companions', (array) $response->get_data() );
	}
}
