<?php
/**
 * The #321 read abilities get the composer payload without loosening the
 * composer-config REST route (T10-S5-01).
 *
 * - The composer-config ability returns the same payload the route builds,
 *   for a user with edit_posts, and spends no rate-limit budget.
 * - A user without edit_posts can't read it, and share status needs
 *   edit_post on that post.
 * - Loading the abilities adds no rest_authentication_errors callback, and
 *   the route still refuses a cookie session with a missing or invalid nonce,
 *   even when another plugin's authentication filter answers first.
 *
 * WordPress 6.7 in wp-env predates the core Abilities API, so the abilities
 * aren't registered here; the tests call the callbacks the API would call.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Abilities;
use Outpost_Composer_Config_Endpoint;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class AbilitiesPayloadAuthTest extends TestCase {

	/** @var array<int, int> */
	private array $users = array();

	private int $post_id = 0;

	/** @var array<string, mixed> */
	private array $saved_server = array();

	/** @var array<string, mixed> */
	private array $saved_request = array();

	/** @var mixed */
	private $saved_auth_cookie = null;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Abilities' ) || ! function_exists( 'wp_insert_user' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap.' );
		}
		$this->saved_server      = $_SERVER;
		$this->saved_request     = $_REQUEST;
		$this->saved_auth_cookie = $GLOBALS['wp_rest_auth_cookie'] ?? null;
	}

	protected function tearDown(): void {
		remove_filter( 'rest_authentication_errors', '__return_true', 5 );
		$_SERVER                        = $this->saved_server;
		$_REQUEST                       = $this->saved_request;
		$GLOBALS['wp_rest_auth_cookie'] = $this->saved_auth_cookie;
		wp_set_current_user( 0 );
		if ( $this->post_id > 0 ) {
			wp_delete_post( $this->post_id, true );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->users as $id ) {
			wp_delete_user( $id );
		}
		parent::tearDown();
	}

	private function user( string $role ): int {
		$id            = (int) wp_insert_user(
			array(
				'user_login' => 's501_' . $role . '_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 's501_' . uniqid() . '@example.test',
				'role'       => $role,
			)
		);
		$this->users[] = $id;
		return $id;
	}

	private function config_rate_limit_rows(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_outpost_config_rl_%'" );
	}

	/** @return array<string, array{0: ?string}> */
	public static function bad_nonces(): array {
		return array(
			'missing nonce' => array( null ),
			'invalid nonce' => array( 'forged0000' ),
		);
	}

	/**
	 * The composer-config ability returns the route's payload without spending rate-limit budget.
	 */
	public function test_the_composer_config_ability_returns_the_route_payload_without_spending_rate_limit(): void {
		wp_set_current_user( $this->user( 'editor' ) );
		$rows_before = $this->config_rate_limit_rows();

		$this->assertTrue( Outpost_Abilities::can_read_outpost() );
		$payload = Outpost_Abilities::execute_get_composer_config( array() );

		$this->assertSame( Outpost_Composer_Config_Endpoint::build_payload(), $payload );
		$this->assertArrayHasKey( 'companions', $payload );
		$this->assertArrayHasKey( 'siteSettings', $payload );
		$this->assertSame( $rows_before, $this->config_rate_limit_rows() );
	}

	/**
	 * A subscriber can't read the abilities, and share status needs edit_post on that post.
	 */
	public function test_the_abilities_keep_their_capability_checks(): void {
		$author        = $this->user( 'author' );
		$this->post_id = (int) wp_insert_post(
			array(
				'post_title'  => 'S5-01 share status',
				'post_status' => 'publish',
				'post_author' => $author,
			)
		);

		wp_set_current_user( $this->user( 'subscriber' ) );
		$this->assertFalse( Outpost_Abilities::can_read_outpost(), 'A subscriber has no edit_posts.' );
		$this->assertFalse( Outpost_Abilities::can_get_share_status( array( 'post_id' => $this->post_id ) ) );

		wp_set_current_user( $this->user( 'author' ) );
		$this->assertFalse( Outpost_Abilities::can_get_share_status( array( 'post_id' => $this->post_id ) ), "Another author can't edit this post." );

		wp_set_current_user( $author );
		$this->assertTrue( Outpost_Abilities::can_get_share_status( array( 'post_id' => $this->post_id ) ) );
	}

	/**
	 * Loading the abilities registers no rest_authentication_errors callback from Outpost.
	 */
	public function test_loading_the_abilities_adds_no_authentication_override(): void {
		Outpost_Abilities::register();
		global $wp_filter;
		$outpost = array();
		if ( isset( $wp_filter['rest_authentication_errors'] ) ) {
			foreach ( $wp_filter['rest_authentication_errors']->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn   = $callback['function'];
					$name = is_array( $fn ) ? ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) : ( is_string( $fn ) ? $fn : 'closure' );
					if ( false !== stripos( $name, 'outpost' ) ) {
						$outpost[] = $name;
					}
				}
			}
		}
		$this->assertSame( array(), $outpost );
		$this->assertFalse( method_exists( Outpost_Composer_Config_Endpoint::class, 'allow_anonymous_for_self' ) );
	}

	/**
	 * With the abilities loaded, the route still refuses a bad cookie nonce behind a third-party gate.
	 *
	 * @dataProvider bad_nonces
	 *
	 * @param ?string $nonce Nonce to send.
	 */
	public function test_the_route_still_fails_closed_after_the_ability_served_the_same_user( ?string $nonce ): void {
		$editor = $this->user( 'editor' );
		wp_set_current_user( $editor );
		$this->assertArrayHasKey( 'companions', Outpost_Abilities::execute_get_composer_config( array() ) );

		add_filter( 'rest_authentication_errors', '__return_true', 5 );
		$GLOBALS['wp_rest_auth_cookie'] = true;
		unset( $_REQUEST['_wpnonce'], $_SERVER['HTTP_X_WP_NONCE'], $_SERVER['HTTP_AUTHORIZATION'] );
		if ( null !== $nonce ) {
			$_SERVER['HTTP_X_WP_NONCE'] = $nonce;
		}
		$rows_before = $this->config_rate_limit_rows();

		$server = rest_get_server();
		$auth   = $server->check_authentication();
		$this->assertNotInstanceOf( \WP_Error::class, $auth, 'The third-party filter answered first.' );
		$response = $server->dispatch( new WP_REST_Request( 'GET', '/outpost/v1/composer-config' ) );

		$this->assertGreaterThanOrEqual( 401, $response->get_status() );
		$this->assertArrayNotHasKey( 'companions', (array) $response->get_data() );
		$this->assertSame( $rows_before, $this->config_rate_limit_rows() );
	}
}
