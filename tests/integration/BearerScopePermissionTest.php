<?php
/**
 * Integration test: bearer-token scope enforcement (H6).
 *
 * Companion to ComposerConfigPermissionTest.php's bearer-in-body coverage,
 * focused on the scope gate H6 added: an IndieAuth-authenticated bearer
 * request must carry a token scoped for `create`/`update` (or, on a
 * read-only route, also `read`). An under-scoped token is refused even
 * though the user can `edit_posts`.
 *
 * Runs against `/wp-json/outpost/v1/composer-config` (read-only; accepts
 * `create`, `update` or `read`), the route ComposerConfigPermissionTest
 * exercises. Tokens come from IndieAuthTokenFixture: real IndieAuth tokens
 * when IndieAuth is loaded, a stand-in otherwise.
 *
 * Scope choice: under real IndieAuth, `Scopes::map_meta_cap()` grants
 * `edit_posts` only to `create` and `draft` tokens. The refused cases use
 * `draft` so the 403 comes from Outpost's scope gate, not from the
 * capability check; the allowed cases use `create`, because a real
 * `read`-only token never passes `edit_posts`.
 *
 * Two request shapes:
 *
 *   - Unresolved: the current user is 0 when the route runs, so the
 *     trait's `authenticate_bearer_token()` resolves the token itself.
 *   - Pre-resolved: the current user is already set the way IndieAuth's
 *     `determine_current_user` callback leaves it during WordPress's early
 *     user resolution, so `authenticate_bearer_token()` returns early. This
 *     is the usual production path for a header token, and the path the
 *     `Authorization: X Bearer <token>` bypass used.
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

	use IndieAuthTokenFixture;

	private int $editor_id = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! $this->integration_environment_ready() ) {
			$this->markTestSkipped(
				'Skipped under unit bootstrap. Run via `npm run test:integration` inside wp-env tests-cli.'
			);
		}

		$this->reset_indieauth_fixture();
		wp_set_current_user( 0 );
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
		$this->reset_indieauth_fixture();
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

	private function dispatch_with_header( string $authorization ): \WP_REST_Response {
		$_SERVER['HTTP_AUTHORIZATION'] = $authorization;
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/outpost/v1/composer-config' ) );
	}

	private function dispatch_with_form_token( string $token ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/outpost/v1/composer-config' );
		$request->set_body_params( array( 'access_token' => $token ) );
		return rest_get_server()->dispatch( $request );
	}

	private function assert_resolved_before_dispatch( string $authorization ): void {
		$_SERVER['HTTP_AUTHORIZATION'] = $authorization;
		$this->assertSame(
			$this->editor_id,
			$this->resolve_current_user_like_core(),
			'Precondition: the token resolves the editor before the route runs.'
		);
	}

	/**
	 * @test
	 */
	public function header_token_with_draft_scope_is_refused(): void {
		$token = $this->issue_indieauth_token( $this->editor_id, array( 'draft' ) );

		$response = $this->dispatch_with_header( 'Bearer ' . $token );

		$this->assertSame(
			403,
			$response->get_status(),
			'A resolved bearer user whose token lacks create/update/read is authenticated (not 401) but forbidden.'
		);
	}

	/**
	 * @test
	 */
	public function header_token_with_create_scope_is_allowed(): void {
		$token = $this->issue_indieauth_token( $this->editor_id, array( 'create' ) );

		$response = $this->dispatch_with_header( 'Bearer ' . $token );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'companions', (array) $response->get_data() );
	}

	/**
	 * @test
	 */
	public function form_encoded_token_with_draft_scope_is_refused(): void {
		$token = $this->issue_indieauth_token( $this->editor_id, array( 'draft' ) );

		$response = $this->dispatch_with_form_token( $token );

		$this->assertSame( 403, $response->get_status(), 'A form-encoded token is gated the same way as a header token.' );
	}

	/**
	 * @test
	 */
	public function form_encoded_token_with_create_scope_is_allowed(): void {
		$token = $this->issue_indieauth_token( $this->editor_id, array( 'create' ) );

		$response = $this->dispatch_with_form_token( $token );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'companions', (array) $response->get_data() );
	}

	/**
	 * @test
	 */
	public function pre_resolved_header_token_with_draft_scope_is_refused(): void {
		$token = $this->issue_indieauth_token( $this->editor_id, array( 'draft' ) );
		$this->assert_resolved_before_dispatch( 'Bearer ' . $token );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/outpost/v1/composer-config' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * The Critical 1 bypass from the H6 re-review: IndieAuth reads a token
	 * after any prefix, so `X Bearer <token>` authenticates the user.
	 *
	 * @test
	 */
	public function pre_resolved_prefixed_bearer_header_with_draft_scope_is_refused(): void {
		$token = $this->issue_indieauth_token( $this->editor_id, array( 'draft' ) );
		$this->assert_resolved_before_dispatch( 'X Bearer ' . $token );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/outpost/v1/composer-config' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Positive control for the case above: the same header shape with a
	 * sufficient scope is allowed, so the 403 is the scope gate.
	 *
	 * @test
	 */
	public function pre_resolved_prefixed_bearer_header_with_create_scope_is_allowed(): void {
		$token = $this->issue_indieauth_token( $this->editor_id, array( 'create' ) );
		$this->assert_resolved_before_dispatch( 'X Bearer ' . $token );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/outpost/v1/composer-config' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'companions', (array) $response->get_data() );
	}
}
