<?php
/**
 * The read abilities apply the same credential gate as their REST routes
 * (Phase 5b review finding F5-01).
 *
 * Core's Abilities REST controller runs an ability after its permission
 * callback alone. IndieAuth maps token scopes onto capabilities, so a token
 * scoped `draft` still has `edit_posts`, and an `update` token can pass
 * `edit_post` on another author's published post without `edit_posts`. The
 * routes refuse both, and a cookie session without a valid REST nonce. The
 * abilities must refuse them too, while a plain PHP caller (no cookie
 * session, no bearer token) keeps the capability check alone.
 *
 * WordPress 6.7 in wp-env has no Abilities API, so these tests resolve the
 * credential the way core does before dispatch and then call the permission
 * callbacks core would call.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Abilities;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class AbilitiesCredentialGateTest extends TestCase {

	use IndieAuthTokenFixture;

	private int $editor_id = 0;

	private int $author_id = 0;

	private int $post_id = 0;

	/** @var array<int, int> Every user a test created. */
	private array $created = array();

	/** How many times the stand-in route's callback ran. */
	private int $stand_in_calls = 0;

	/** @var mixed */
	private $saved_auth_cookie = null;

	/** @var array<string, mixed> */
	private array $saved_server = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Abilities' ) || ! function_exists( 'wp_insert_user' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap.' );
		}
		$this->saved_auth_cookie = $GLOBALS['wp_rest_auth_cookie'] ?? null;
		$this->saved_server      = $_SERVER;
		$this->reset_indieauth_fixture();
		wp_set_current_user( 0 );
		$this->editor_id = $this->user( 'editor' );
		$this->author_id = $this->user( 'author' );
		$this->post_id   = (int) wp_insert_post(
			array(
				'post_title'  => 'F5-01 share status',
				'post_status' => 'publish',
				'post_author' => $this->author_id,
			)
		);
	}

	protected function tearDown(): void {
		$this->reset_indieauth_fixture();
		$GLOBALS['wp_rest_auth_cookie'] = $this->saved_auth_cookie;
		$_SERVER                        = $this->saved_server;
		wp_set_current_user( 0 );
		wp_delete_post( $this->post_id, true );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		delete_transient( 'outpost_config_rl_u_' . $this->editor_id );
		foreach ( $this->created as $id ) {
			wp_delete_user( $id );
		}
		parent::tearDown();
	}

	private function user( string $role ): int {
		$id              = (int) wp_insert_user(
			array(
				'user_login' => 'f501_' . $role . '_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'f501_' . uniqid() . '@example.test',
				'role'       => $role,
			)
		);
		$this->created[] = $id;
		return $id;
	}

	/** Resolve a bearer token for the editor the way core does before dispatch. */
	private function arrive_with_token( array $scopes ): void {
		$token                         = $this->issue_indieauth_token( $this->editor_id, $scopes );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$this->assertSame( $this->editor_id, $this->resolve_current_user_like_core(), 'Precondition: the token resolves the editor.' );
	}

	private function arrive_with_cookie( ?string $nonce ): void {
		wp_set_current_user( $this->editor_id );
		$GLOBALS['wp_rest_auth_cookie'] = true;
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'], $_SERVER['HTTP_AUTHORIZATION'] );
		if ( null !== $nonce ) {
			$_SERVER['HTTP_X_WP_NONCE'] = $nonce;
		}
	}

	private function route_status( string $route ): int {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) )->get_status();
	}

	/**
	 * A draft-scoped token: the routes refuse it, so the read abilities do too.
	 */
	public function test_a_draft_scoped_token_is_refused_like_the_routes(): void {
		$this->arrive_with_token( array( 'draft' ) );

		$this->assertSame( 403, $this->route_status( '/outpost/v1/composer-config' ), 'Control: the route refuses the draft scope.' );
		$this->assertFalse( Outpost_Abilities::can_read_outpost(), 'The composer-config and syndicate-targets abilities refuse it too.' );
	}

	/**
	 * A create-scoped token passes both.
	 */
	public function test_a_create_scoped_token_passes_like_the_routes(): void {
		$this->arrive_with_token( array( 'create' ) );

		$this->assertSame( 200, $this->route_status( '/outpost/v1/composer-config' ) );
		$this->assertTrue( Outpost_Abilities::can_read_outpost() );
		$this->assertTrue( Outpost_Abilities::can_get_share_status( array( 'post_id' => $this->post_id ) ) );
	}

	/**
	 * A user who can edit this post but lacks edit_posts can't read its share status, like the route.
	 *
	 * Over HTTP an IndieAuth `update` token is such a user (its scope
	 * carries edit_others_posts and edit_published_posts, not edit_posts);
	 * the in-process fixture doesn't reproduce IndieAuth's REST-time
	 * capability map, so a role with the same capabilities stands in.
	 */
	public function test_share_status_needs_edit_posts_like_the_route(): void {
		add_role( 'f501_reviser', 'F5-01 reviser', array( 'read' => true, 'edit_others_posts' => true, 'edit_published_posts' => true ) );
		try {
			wp_set_current_user( $this->user( 'f501_reviser' ) );

			$this->assertTrue( current_user_can( 'edit_post', $this->post_id ), 'Precondition: the user can edit this post.' );
			$this->assertFalse( current_user_can( 'edit_posts' ), 'Precondition: but lacks edit_posts.' );
			$refused = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/outpost/v1/manual-share/status/' . $this->post_id ) );
			$this->assertSame( 401, $refused->get_status(), 'Control: the route refuses a user without edit_posts.' );
			$this->assertSame( 'rest_forbidden', $refused->get_data()['code'] ?? null );
			$this->assertFalse( Outpost_Abilities::can_get_share_status( array( 'post_id' => $this->post_id ) ) );
		} finally {
			remove_role( 'f501_reviser' );
		}
	}

	/** @return array<string, array{0: ?string}> */
	public static function bad_nonces(): array {
		return array(
			'missing nonce' => array( null ),
			'invalid nonce' => array( 'forged0000' ),
		);
	}

	/**
	 * A cookie session without a valid REST nonce is refused.
	 *
	 * @dataProvider bad_nonces
	 *
	 * @param ?string $nonce Nonce to send.
	 */
	public function test_a_cookie_session_without_a_valid_nonce_is_refused( ?string $nonce ): void {
		$this->arrive_with_cookie( $nonce );

		$this->assertFalse( Outpost_Abilities::can_read_outpost() );
		$this->assertFalse( Outpost_Abilities::can_get_share_status( array( 'post_id' => $this->post_id ) ) );
	}

	/**
	 * A cookie session with a valid REST nonce passes.
	 */
	public function test_a_cookie_session_with_a_valid_nonce_passes(): void {
		wp_set_current_user( $this->editor_id );
		$this->arrive_with_cookie( wp_create_nonce( 'wp_rest' ) );

		$this->assertTrue( Outpost_Abilities::can_read_outpost() );
		$this->assertTrue( Outpost_Abilities::can_get_share_status( array( 'post_id' => $this->post_id ) ) );
	}

	/**
	 * Core's Abilities REST controller calls the permission callback while it
	 * dispatches its own route; a test route with the same callback stands in.
	 */
	private function dispatch_ability_stand_in( array $body ): \WP_REST_Response {
		register_rest_route(
			'outpost-test/v1',
			'/ability-gate',
			array(
				'methods'             => 'POST',
				'callback'            => function () {
					++$this->stand_in_calls;
					return array( 'read' => true );
				},
				'permission_callback' => array( Outpost_Abilities::class, 'can_read_outpost' ),
			)
		);
		$request = new WP_REST_Request( 'POST', '/outpost-test/v1/ability-gate' );
		$request->set_body_params( $body );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * An unverified token in the request body is refused, like the route refuses it (F5-01 residual).
	 */
	public function test_an_unverified_body_token_is_refused_like_the_route(): void {
		wp_set_current_user( $this->editor_id );
		$route = new WP_REST_Request( 'POST', '/outpost/v1/composer-config' );
		$route->set_body_params( array( 'access_token' => 'unverified-0000' ) );
		$refused = rest_get_server()->dispatch( $route );
		$this->assertSame( 403, $refused->get_status(), 'Control: the route sees the body token and refuses.' );

		$this->assertSame( 403, $this->dispatch_ability_stand_in( array( 'access_token' => 'unverified-0000' ) )->get_status() );
		$this->assertSame( 0, $this->stand_in_calls, 'The refused request never reaches the protected callback.' );
		$this->assertSame( 200, $this->dispatch_ability_stand_in( array() )->get_status(), 'Without a token the same user passes.' );
		$this->assertSame( 1, $this->stand_in_calls );
		$this->assertTrue( Outpost_Abilities::can_read_outpost(), 'After the dispatch, a plain PHP call sees no leftover request.' );
	}

	/**
	 * Core's Allow header rechecks the permission after dispatch; it sees the same body token (R-02).
	 */
	public function test_the_allow_header_recheck_sees_the_body_token(): void {
		wp_set_current_user( $this->editor_id );
		$request = new WP_REST_Request( 'POST', '/outpost-test/v1/ability-gate' );
		$request->set_body_params( array( 'access_token' => 'unverified-0000' ) );
		$response = $this->dispatch_ability_stand_in( array( 'access_token' => 'unverified-0000' ) );
		$this->assertSame( 403, $response->get_status() );

		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), rest_get_server(), $request );

		$this->assertStringNotContainsString( 'POST', (string) ( $response->get_headers()['Allow'] ?? '' ), 'The refused method is not advertised as allowed.' );
	}

	/**
	 * A nested request that throws, caught by its caller, leaves nothing behind (R-01).
	 */
	public function test_a_caught_nested_throw_leaves_no_request_behind(): void {
		wp_set_current_user( $this->editor_id );
		register_rest_route(
			'outpost-test/v1',
			'/throws',
			array(
				'methods'             => 'POST',
				'callback'            => static function () {
					throw new \RuntimeException( 'inner handler failed' );
				},
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'outpost-test/v1',
			'/outer',
			array(
				'methods'             => 'GET',
				'callback'            => static function () {
					$inner = new WP_REST_Request( 'POST', '/outpost-test/v1/throws' );
					$inner->set_body_params( array( 'access_token' => 'unverified-0000' ) );
					try {
						rest_get_server()->dispatch( $inner );
					} catch ( \RuntimeException $caught ) {
						return array( 'caught' => $caught->getMessage() );
					}
					return array( 'caught' => '' );
				},
				'permission_callback' => '__return_true',
			)
		);

		$outer = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/outpost-test/v1/outer' ) );
		$this->assertSame( 'inner handler failed', $outer->get_data()['caught'] ?? null, 'Precondition: the inner handler threw and was caught.' );

		$this->assertTrue( Outpost_Abilities::can_read_outpost(), 'A plain PHP call afterwards sees no leftover body token.' );
	}

	/**
	 * Dispatch one request object to a route whose handler dispatches the same object again.
	 *
	 * @param bool $inner_throws Whether the inner (second) handling throws; the outer handler catches it.
	 * @return array{inner_left: ?bool, caught: string}
	 */
	private function reentrant_dispatch( bool $inner_throws ): array {
		$depth = 0;
		$seen  = array(
			'inner_left' => null,
			'caught'     => '',
		);
		$request = new WP_REST_Request( 'POST', '/outpost-test/v1/reentrant' );
		$request->set_body_params( array( 'access_token' => 'unverified-0000' ) );
		rest_get_server(); // Fires rest_api_init before the test route registers.
		register_rest_route(
			'outpost-test/v1',
			'/reentrant',
			array(
				'methods'             => 'POST',
				'callback'            => static function ( WP_REST_Request $handled ) use ( &$depth, &$seen, $inner_throws ) {
					++$depth;
					if ( $depth > 1 ) {
						if ( $inner_throws ) {
							throw new \RuntimeException( 'inner handling failed' );
						}
						return array();
					}
					try {
						rest_get_server()->dispatch( $handled );
					} catch ( \RuntimeException $caught ) {
						$seen['caught'] = $caught->getMessage();
					}
					// Still inside the outer handling: its frame must carry the body token.
					$seen['inner_left'] = Outpost_Abilities::can_read_outpost();
					return array();
				},
				'permission_callback' => '__return_true',
			),
			true
		);
		rest_get_server()->dispatch( $request );
		return $seen;
	}

	/**
	 * A request handled twice (re-entrant dispatch) keeps its outer frame after the inner handling ends (Q-01).
	 */
	public function test_a_reentrant_request_keeps_its_outer_frame(): void {
		wp_set_current_user( $this->editor_id );

		$seen = $this->reentrant_dispatch( false );

		$this->assertFalse( $seen['inner_left'], 'After the inner handling, the outer check still sees the body token.' );
		$this->assertTrue( Outpost_Abilities::can_read_outpost(), 'After the dispatch, a plain PHP call sees no leftover request.' );
	}

	/**
	 * A re-entrant inner handling that throws, caught by the outer handler, leaves nothing behind (Q-01 follow-up).
	 */
	public function test_a_reentrant_inner_throw_leaves_no_request_behind(): void {
		wp_set_current_user( $this->editor_id );

		$seen = $this->reentrant_dispatch( true );

		$this->assertSame( 'inner handling failed', $seen['caught'], 'Precondition: the inner handling threw and was caught.' );
		$this->assertFalse( $seen['inner_left'], 'The outer check still sees the body token.' );
		$this->assertTrue( Outpost_Abilities::can_read_outpost(), 'After the dispatch, a plain PHP call sees no leftover request.' );
	}

	/**
	 * A plain PHP caller (no cookie session, no token) keeps the capability check alone.
	 */
	public function test_a_plain_php_caller_keeps_the_capability_check(): void {
		wp_set_current_user( $this->editor_id );

		$this->assertTrue( Outpost_Abilities::can_read_outpost() );
		$this->assertTrue( Outpost_Abilities::can_get_share_status( array( 'post_id' => $this->post_id ) ) );

		wp_set_current_user( $this->user( 'subscriber' ) );
		$this->assertFalse( Outpost_Abilities::can_read_outpost() );
	}
}
