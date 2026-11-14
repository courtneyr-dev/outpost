<?php
/**
 * No `outpost_*_permission` filter can waive a cookie session's nonce (F-42).
 *
 * T10 asked that nonce checks fail early instead of sitting among other
 * conditions. Every Outpost REST permission callback checks the credential
 * first (a cookie session needs a valid REST nonce; a token needs an accepted
 * scope) and returns a refusal right there, before the capability check and
 * before any site filter runs. The filters decide the capability question
 * only, so a site that returns true from one of them never admits a cookie
 * request whose nonce is missing or forged.
 *
 * The sweep walks every registered `/outpost/` route handler, so a route
 * added later is covered without editing this test. A third-party
 * `rest_authentication_errors` callback returns true first, which makes core's
 * own `rest_cookie_check_errors()` skip its nonce check and leaves the logged
 * in user in place: the case where only Outpost's own check stands.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class PermissionFilterNonceTest extends TestCase {

	/** Every filter an Outpost permission callback applies. */
	private const PERMISSION_FILTERS = array(
		'outpost_composer_config_permission',
		'outpost_geocode_permission',
		'outpost_manual_share_intent_permission',
		'outpost_manual_share_status_permission',
		'outpost_media_lookup_permission',
		'outpost_preview_permission',
		'outpost_syndicate_targets_permission',
		'outpost_syndication_capture_permission',
	);

	private int $admin_id = 0;

	/** @var array<string, mixed> */
	private array $saved_server = array();

	/** @var array<string, mixed> */
	private array $saved_request = array();

	/** @var mixed */
	private $saved_auth_cookie = null;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_insert_user' ) || ! class_exists( 'Outpost_Composer_Config_Endpoint' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap. Run via `npm run test:integration` inside wp-env tests-cli.' );
		}
		$this->saved_server      = $_SERVER;
		$this->saved_request     = $_REQUEST;
		$this->saved_auth_cookie = $GLOBALS['wp_rest_auth_cookie'] ?? null;

		$this->admin_id = (int) wp_insert_user(
			array(
				'user_login' => 'filter_admin_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'filter_admin_' . uniqid() . '@example.test',
				'role'       => 'administrator',
			)
		);
		add_filter( 'rest_authentication_errors', '__return_true', 5 );
		foreach ( self::PERMISSION_FILTERS as $filter ) {
			add_filter( $filter, '__return_true' );
		}
	}

	protected function tearDown(): void {
		remove_filter( 'rest_authentication_errors', '__return_true', 5 );
		foreach ( self::PERMISSION_FILTERS as $filter ) {
			remove_filter( $filter, '__return_true' );
		}
		$_SERVER                        = $this->saved_server;
		$_REQUEST                       = $this->saved_request;
		$GLOBALS['wp_rest_auth_cookie'] = $this->saved_auth_cookie;
		wp_set_current_user( 0 );
		if ( $this->admin_id > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->admin_id );
		}
		parent::tearDown();
	}

	/**
	 * A cookie-authenticated admin request after a third-party gate made core
	 * skip its nonce check. `$nonce` null sends none.
	 */
	private function arrive_as_cookie_admin( ?string $nonce ): void {
		wp_set_current_user( $this->admin_id );
		$GLOBALS['wp_rest_auth_cookie'] = true;
		unset( $_REQUEST['_wpnonce'], $_SERVER['HTTP_X_WP_NONCE'], $_SERVER['HTTP_AUTHORIZATION'] );
		if ( null !== $nonce ) {
			$_SERVER['HTTP_X_WP_NONCE'] = $nonce;
		}
		$this->assertTrue( rest_get_server()->check_authentication(), 'Precondition: the third-party gate made core skip its nonce check.' );
	}

	/**
	 * Every Outpost route handler whose permission callback checks a credential.
	 *
	 * @return array<string, array{method: string, path: string, callback: callable}>
	 */
	private function credentialed_handlers(): array {
		$handlers = array();
		foreach ( rest_get_server()->get_routes( 'outpost/v1' ) as $route => $endpoints ) {
			foreach ( $endpoints as $endpoint ) {
				$callback = $endpoint['permission_callback'] ?? null;
				// The namespace index has none; the OAuth provider callback is
				// public by design and checks its signed state instead.
				if ( null === $callback || '__return_true' === $callback ) {
					continue;
				}
				$path = (string) preg_replace( '/\(\?P<[a-z_]+>[^)]*\)/', '1', $route );
				foreach ( array_keys( $endpoint['methods'] ) as $method ) {
					$handlers[ $method . ' ' . $route ] = array(
						'method'   => $method,
						'path'     => $path,
						'callback' => $callback,
					);
				}
			}
		}
		return $handlers;
	}

	/**
	 * @param array{method: string, path: string, callback: callable} $handler Handler.
	 * @return mixed
	 */
	private function decide( array $handler ) {
		return call_user_func( $handler['callback'], new WP_REST_Request( $handler['method'], $handler['path'] ) );
	}

	/**
	 * @return array<string, array{0: ?string}>
	 */
	public static function bad_nonces(): array {
		return array(
			'missing nonce' => array( null ),
			'invalid nonce' => array( 'forged0000' ),
		);
	}

	/**
	 * @dataProvider bad_nonces
	 *
	 * @param ?string $nonce Nonce to send.
	 */
	public function test_no_permission_filter_admits_a_cookie_session_without_a_valid_nonce( ?string $nonce ): void {
		$this->arrive_as_cookie_admin( $nonce );

		$handlers = $this->credentialed_handlers();
		$this->assertGreaterThanOrEqual( 22, count( $handlers ), 'The sweep must see every credentialed Outpost route.' );

		$admitted = array();
		foreach ( $handlers as $label => $handler ) {
			$decision = $this->decide( $handler );
			if ( true === $decision ) {
				$admitted[] = $label;
			}
		}
		$this->assertSame( array(), $admitted, 'A permission filter overrode a failed nonce check.' );
	}

	/**
	 * Calibration: the same requests with a valid nonce pass, so the refusals
	 * above come from the nonce, not from the sweep failing to reach a route.
	 */
	public function test_a_valid_nonce_still_passes_every_credentialed_route(): void {
		foreach ( self::PERMISSION_FILTERS as $filter ) {
			remove_filter( $filter, '__return_true' );
		}
		wp_set_current_user( $this->admin_id );
		$this->arrive_as_cookie_admin( wp_create_nonce( 'wp_rest' ) );

		$refused = array();
		foreach ( $this->credentialed_handlers() as $label => $handler ) {
			if ( true !== $this->decide( $handler ) ) {
				$refused[] = $label;
			}
		}
		$this->assertSame( array(), $refused );
	}
}
