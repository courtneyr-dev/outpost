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
		wp_delete_user( $this->editor_id );
		wp_delete_user( $this->author_id );
		parent::tearDown();
	}

	private function user( string $role ): int {
		return (int) wp_insert_user(
			array(
				'user_login' => 'f501_' . $role . '_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'f501_' . uniqid() . '@example.test',
				'role'       => $role,
			)
		);
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
	 * An update-scoped token can edit another author's published post without edit_posts; share status still needs edit_posts.
	 */
	public function test_share_status_needs_edit_posts_like_the_route(): void {
		$this->arrive_with_token( array( 'update' ) );

		$this->assertTrue( current_user_can( 'edit_post', $this->post_id ), 'Precondition: IndieAuth grants edit_post on this post.' );
		$this->assertFalse( current_user_can( 'edit_posts' ), 'Precondition: but not edit_posts.' );
		$this->assertGreaterThanOrEqual( 401, $this->route_status( '/outpost/v1/manual-share/status/' . $this->post_id ), 'Control: the route refuses.' );
		$this->assertFalse( Outpost_Abilities::can_get_share_status( array( 'post_id' => $this->post_id ) ) );
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
