<?php
/**
 * The `outpost_*_permission` filters can only restrict access (F-47).
 *
 * Every Outpost REST permission callback checks the credential first
 * (F-42), then `current_user_can( 'edit_posts' )`, and only after both pass
 * applies its site filter, with `true` as the value. A filter can turn an
 * admitted request away; it can never admit one. A request with no
 * credential and no logged-in user is refused before any filter runs.
 *
 * Built from the T11 Phase 2 P2-C4 harness: the same 15 route-method pairs
 * and 8 filters, dispatched through the real REST server on both the core
 * authentication path and a path where a third-party
 * `rest_authentication_errors` callback makes core skip its nonce check.
 * External HTTP is blocked: a `pre_http_request` filter at PHP_INT_MIN
 * records the URL and answers locally, and an `http_api_curl` counter
 * proves no transport ran. Every refusal also asserts that the protected
 * work never happened (no fetch, no option, transient, meta or cron change)
 * and that the site filter was never called.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class PermissionFilterRestrictOnlyTest extends TestCase {

	use IndieAuthTokenFixture;

	private const PREVIEW_URL = 'http://93.184.215.14/outpost-f47-synthetic';
	private const ANDROID_UA  = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

	private int $editor_id     = 0;
	private int $subscriber_id = 0;
	private int $post_id       = 0;
	private string $seed       = '';
	private string $tok_create = '';

	/** @var string[] */
	private array $outbound = array();
	private int $transport  = 0;
	/** @var string[] */
	private array $admitted = array();
	/** @var array<int, array<int, mixed>> */
	private array $filter_calls = array();

	/** @var array<string, mixed> */
	private array $saved_server = array();
	/** @var array<string, mixed> */
	private array $saved_request = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_insert_user' ) || ! class_exists( 'Outpost_Preview_Endpoint' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap. Run via `npm run test:integration` inside wp-env tests-cli.' );
		}
		$this->saved_server  = $_SERVER;
		$this->saved_request = $_REQUEST;
		$this->reset_indieauth_fixture();
		wp_set_current_user( 0 );

		$this->editor_id     = $this->make_user( 'editor' );
		$this->subscriber_id = $this->make_user( 'subscriber' );
		$this->post_id       = (int) wp_insert_post(
			array(
				'post_title'  => 'F-47 synthetic post',
				'post_status' => 'publish',
				'post_author' => $this->editor_id,
			)
		);

		add_filter( 'option_active_plugins', array( $this, 'pretend_post_kinds_active' ) );
		add_filter( 'pre_http_request', array( $this, 'block_http' ), PHP_INT_MIN, 3 );
		add_action( 'http_api_curl', array( $this, 'count_transport' ), PHP_INT_MIN );
		add_filter( 'rest_dispatch_request', array( $this, 'probe_admitted' ), PHP_INT_MIN, 4 );

		$GLOBALS['wp_rest_server'] = null;
		add_action( 'rest_api_init', array( $this, 'register_post_kinds_stub' ) );
		rest_get_server();

		$this->tok_create = $this->issue_indieauth_token( $this->editor_id, array( 'create' ) );

		$this->assertFalse( user_can( 0, 'edit_posts' ), 'Precondition: anonymous lacks edit_posts.' );
		$this->assertFalse( user_can( $this->subscriber_id, 'edit_posts' ), 'Precondition: a subscriber lacks edit_posts.' );
		$this->assertTrue( user_can( $this->editor_id, 'edit_post', $this->post_id ), 'Precondition: the editor can edit the post.' );
	}

	protected function tearDown(): void {
		remove_filter( 'option_active_plugins', array( $this, 'pretend_post_kinds_active' ) );
		remove_filter( 'pre_http_request', array( $this, 'block_http' ), PHP_INT_MIN );
		remove_action( 'http_api_curl', array( $this, 'count_transport' ), PHP_INT_MIN );
		remove_filter( 'rest_dispatch_request', array( $this, 'probe_admitted' ), PHP_INT_MIN );
		remove_action( 'rest_api_init', array( $this, 'register_post_kinds_stub' ) );
		remove_filter( 'rest_authentication_errors', '__return_true', 5 );
		$this->reset_indieauth_fixture();
		$GLOBALS['wp_rest_server']      = null;
		$GLOBALS['wp_rest_auth_cookie'] = null;
		wp_set_current_user( 0 );
		$this->clear_outpost_transients();
		if ( $this->post_id > 0 ) {
			wp_delete_post( $this->post_id, true );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( array( $this->editor_id, $this->subscriber_id ) as $user_id ) {
			if ( $user_id > 0 ) {
				wp_delete_user( $user_id );
			}
		}
		$_SERVER  = $this->saved_server;
		$_REQUEST = $this->saved_request;
		parent::tearDown();
	}

	// ----- Seams and probes ------------------------------------------------

	/**
	 * @param mixed $plugins Active plugins.
	 * @return array<int, string>
	 */
	public function pretend_post_kinds_active( $plugins ): array {
		$plugins   = is_array( $plugins ) ? $plugins : array();
		$plugins[] = OUTPOST_POST_KINDS_PLUGIN_FILE;
		return array_values( array_unique( $plugins ) );
	}

	/**
	 * Answers every outbound request locally and records its URL.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param mixed  $args Request args.
	 * @param string $url  URL.
	 * @return array<string, mixed>
	 */
	public function block_http( $pre, $args, $url ): array {
		unset( $pre, $args );
		$this->outbound[] = (string) $url;
		$is_geocode       = false !== strpos( (string) $url, 'nominatim.openstreetmap.org' );
		return array(
			'headers'  => array( 'content-type' => $is_geocode ? 'application/json' : 'text/html; charset=utf-8' ),
			'body'     => $is_geocode ? '[]' : '<!doctype html><html><head><title>F-47 synthetic</title></head><body><p>synthetic</p></body></html>',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function count_transport(): void {
		++$this->transport;
	}

	/**
	 * Core applies this only after a route's permission callback returned true.
	 *
	 * @param mixed            $result  Dispatch result.
	 * @param \WP_REST_Request $request Request.
	 * @param string           $route   Route.
	 * @param array            $handler Handler.
	 * @return mixed
	 */
	public function probe_admitted( $result, $request, $route, $handler ) {
		unset( $handler );
		$this->admitted[] = $request->get_method() . ' ' . $route;
		return $result;
	}

	/**
	 * Stand-in for Post Kinds' lookup route, gated on `edit_posts` like the
	 * real one, so the media-lookup handler has somewhere to dispatch.
	 */
	public function register_post_kinds_stub(): void {
		register_rest_route(
			'post-kinds-indieweb/v1',
			'/lookup/(?P<category>[a-z]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => static function () {
					return new \WP_REST_Response( array(), 200 );
				},
			)
		);
	}

	// ----- Fixtures --------------------------------------------------------

	private function make_user( string $role ): int {
		$login = 'f47_' . $role . '_' . wp_generate_password( 6, false );
		return (int) wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => $login . '@example.test',
				'role'       => $role,
			)
		);
	}

	private function clear_outpost_transients(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_outpost\\_%' OR option_name LIKE '\\_transient\\_timeout\\_outpost\\_%'" );
		wp_cache_flush();
	}

	private function reseed(): void {
		delete_post_meta( $this->post_id, 'outpost_manual_share_log' );
		delete_post_meta( $this->post_id, 'outpost_syndication_links' );
		$entry      = \Outpost_Manual_Share_Audit_Log::add_entry( $this->post_id, 'x-twitter', \Outpost_Manual_Share_Audit_Log::STRATEGY_INTENT_URL );
		$this->seed = (string) $entry['id'];
	}

	/**
	 * The 15 route-method pairs the eight filters guard.
	 *
	 * @return array<string, array{filter: string, method: string, path: string, route: string}>
	 */
	private function routes(): array {
		$r = static function ( string $filter, string $method, string $path, ?string $route = null ): array {
			return array(
				'filter' => $filter,
				'method' => $method,
				'path'   => $path,
				'route'  => $route ?? $path,
			);
		};
		return array(
			'preview'           => $r( 'outpost_preview_permission', 'POST', '/outpost/v1/preview' ),
			'lookup'            => $r( 'outpost_media_lookup_permission', 'POST', '/outpost/v1/lookup' ),
			'geocode'           => $r( 'outpost_geocode_permission', 'POST', '/outpost/v1/geocode' ),
			'composer_get'      => $r( 'outpost_composer_config_permission', 'GET', '/outpost/v1/composer-config' ),
			'composer_post'     => $r( 'outpost_composer_config_permission', 'POST', '/outpost/v1/composer-config' ),
			'syndicate_targets' => $r( 'outpost_syndicate_targets_permission', 'GET', '/outpost/v1/syndicate-targets' ),
			'intent'            => $r( 'outpost_manual_share_intent_permission', 'POST', '/outpost/v1/manual-share/intent' ),
			'intent_log'        => $r( 'outpost_manual_share_intent_permission', 'POST', '/outpost/v1/manual-share/intent/log' ),
			'chips'             => $r( 'outpost_manual_share_intent_permission', 'GET', '/outpost/v1/manual-share-chips' ),
			'status'            => $r( 'outpost_manual_share_status_permission', 'GET', '/outpost/v1/manual-share/status/%d', '/outpost/v1/manual-share/status/(?P<post_id>\d+)' ),
			'pending_summary'   => $r( 'outpost_manual_share_status_permission', 'GET', '/outpost/v1/manual-share/pending-summary' ),
			'dismiss'           => $r( 'outpost_manual_share_status_permission', 'POST', '/outpost/v1/manual-share/dismiss-reminder' ),
			'snooze_all'        => $r( 'outpost_manual_share_status_permission', 'POST', '/outpost/v1/manual-share/snooze-all' ),
			'pending'           => $r( 'outpost_syndication_capture_permission', 'GET', '/outpost/v1/manual-share/pending' ),
			'capture'           => $r( 'outpost_syndication_capture_permission', 'POST', '/outpost/v1/manual-share/capture' ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function params_for( string $name ): array {
		switch ( $name ) {
			case 'preview':
				return array( 'url' => self::PREVIEW_URL );
			case 'lookup':
				return array(
					'kind' => 'listen',
					'q'    => 'F-47 synthetic query',
				);
			case 'geocode':
				return array( 'q' => 'F-47 Synthetic Town' );
			case 'intent':
				return array(
					'post_id'     => $this->post_id,
					'platform_id' => 'x-twitter',
				);
			case 'intent_log':
				return array(
					'post_id'      => $this->post_id,
					'audit_log_id' => $this->seed,
					'outcome'      => 'fired',
				);
			case 'dismiss':
				return array(
					'post_id'      => $this->post_id,
					'audit_log_id' => $this->seed,
					'until'        => 'P1D',
				);
			case 'snooze_all':
				return array( 'until' => 'P1D' );
			case 'capture':
				return array(
					'post_id'      => $this->post_id,
					'audit_log_id' => $this->seed,
					'silo_url'     => 'https://twitter.com/f47synthetic/status/1',
				);
			default:
				return array();
		}
	}

	// ----- Snapshots -------------------------------------------------------

	/**
	 * @return array<string, array<string, string>>
	 */
	private function snapshot(): array {
		global $wpdb;
		wp_cache_flush();
		$snap = array(
			'postmeta' => array(),
			'usermeta' => array(),
			'options'  => array(),
			'cron'     => array( 'cron' => md5( (string) wp_json_encode( _get_cron_array() ) ) ),
		);
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $this->post_id ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$snap['postmeta'][ (string) $row['meta_key'] ] = md5( (string) $row['meta_value'] );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id IN (0, %d, %d)", $this->editor_id, $this->subscriber_id ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$snap['usermeta'][ $row['user_id'] . ':' . $row['meta_key'] ] = md5( (string) $row['meta_value'] );
		}
		$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options}", ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$snap['options'][ (string) $row['option_name'] ] = md5( (string) $row['option_value'] );
		}
		return $snap;
	}

	/**
	 * @param array<string, array<string, string>> $before Before.
	 * @param array<string, array<string, string>> $after  After.
	 * @return array<string, string[]>
	 */
	private function diff( array $before, array $after ): array {
		$out = array();
		foreach ( $before as $section => $map ) {
			$changed = array();
			foreach ( $after[ $section ] as $key => $hash ) {
				if ( ! isset( $map[ $key ] ) ) {
					$changed[] = '+' . $key;
				} elseif ( $map[ $key ] !== $hash ) {
					$changed[] = '~' . $key;
				}
			}
			foreach ( $map as $key => $hash ) {
				if ( ! isset( $after[ $section ][ $key ] ) ) {
					$changed[] = '-' . $key;
				}
			}
			$out[ $section ] = $changed;
		}
		return $out;
	}

	// ----- Case runner -----------------------------------------------------

	private function reset_request(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );
		$this->reset_indieauth_request_state();
		$GLOBALS['wp_rest_auth_cookie'] = null;
		$_SERVER['HTTP_USER_AGENT']     = self::ANDROID_UA;
		$_SERVER['REMOTE_ADDR']         = '198.18.0.47';
		wp_set_current_user( 0 );
	}

	private function arrive( string $who ): void {
		switch ( $who ) {
			case 'none':
				break;
			case 'editor_cookie':
			case 'subscriber_cookie':
				wp_set_current_user( 'editor_cookie' === $who ? $this->editor_id : $this->subscriber_id );
				$GLOBALS['wp_rest_auth_cookie'] = true;
				$_SERVER['HTTP_X_WP_NONCE']     = wp_create_nonce( 'wp_rest' );
				break;
			case 'editor_bearer':
				$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->tok_create;
				$this->assertSame( $this->editor_id, $this->resolve_current_user_like_core(), 'Precondition: the create token resolves the editor.' );
				break;
		}
	}

	/**
	 * Install a counting callback on the route's filter. `default` passes the
	 * value through unchanged; `true` and `false` return that value.
	 */
	private function install_filter( string $filter, string $state ): callable {
		$callback = function ( ...$args ) use ( $state ) {
			$this->filter_calls[] = $args;
			if ( 'true' === $state ) {
				return true;
			}
			if ( 'false' === $state ) {
				return false;
			}
			return $args[0];
		};
		add_filter( $filter, $callback, 10, 5 );
		return $callback;
	}

	/**
	 * Dispatch one request the way serve_request() does and record what it did.
	 *
	 * @return array<string, mixed>
	 */
	private function run_case( string $name, string $who, string $path, string $state ): array {
		$spec = $this->routes()[ $name ];
		$this->clear_outpost_transients();
		$this->reseed();
		$this->reset_request();
		$callback = $this->install_filter( $spec['filter'], $state );
		if ( 'bypass' === $path ) {
			add_filter( 'rest_authentication_errors', '__return_true', 5 );
		}
		$this->arrive( $who );

		$request = new WP_REST_Request( $spec['method'], false !== strpos( $spec['path'], '%d' ) ? sprintf( $spec['path'], $this->post_id ) : $spec['path'] );
		$params  = $this->params_for( $name );
		if ( 'POST' === $spec['method'] ) {
			$request->set_header( 'content-type', 'application/x-www-form-urlencoded' );
			$request->set_body_params( $params );
		} else {
			$request->set_query_params( $params );
		}

		$before             = $this->snapshot();
		$this->outbound     = array();
		$this->transport    = 0;
		$this->admitted     = array();
		$this->filter_calls = array();

		$auth = rest_get_server()->check_authentication();
		$this->assertNotInstanceOf( \WP_Error::class, $auth, "Precondition ($name, $who, $path): core authentication passes." );
		$response = rest_get_server()->dispatch( $request );
		$after    = $this->snapshot();

		remove_filter( $spec['filter'], $callback, 10 );
		remove_filter( 'rest_authentication_errors', '__return_true', 5 );

		return array(
			'label'    => "$name ($who, $path, filter $state)",
			'status'   => $response->get_status(),
			'body'     => $response->get_data(),
			'admitted' => in_array( $spec['method'] . ' ' . $spec['route'], $this->admitted, true ),
			'calls'    => $this->filter_calls,
			'outbound' => $this->outbound,
			'diff'     => $this->diff( $before, $after ),
		);
	}

	/**
	 * A refused case did none of the route's protected work.
	 *
	 * @param array<string, mixed> $c Case.
	 */
	private function assert_refused_without_side_effects( array $c, int $status ): void {
		$label = $c['label'];
		$this->assertFalse( $c['admitted'], "$label: not admitted" );
		$this->assertSame( $status, $c['status'], "$label: refusal status" );
		$this->assertSame( array(), $c['outbound'], "$label: no outbound fetch" );
		$this->assertSame( array(), $c['diff']['postmeta'], "$label: no post meta change" );
		$this->assertSame( array(), $c['diff']['usermeta'], "$label: no user meta change" );
		$this->assertSame( array(), $c['diff']['options'], "$label: no option or transient change" );
		$this->assertSame( array(), $c['diff']['cron'], "$label: no scheduled-event change" );
		$this->assertSame( 0, $this->transport, "$label: no HTTP transport ran" );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function filter_states_and_paths(): array {
		$out = array();
		foreach ( array( 'default', 'true', 'false' ) as $state ) {
			foreach ( array( 'core', 'bypass' ) as $path ) {
				$out[ "filter $state, $path path" ] = array( $state, $path );
			}
		}
		return $out;
	}

	// ----- Tests -------------------------------------------------------------

	/**
	 * F-47: with no credential and no logged-in user, every route refuses
	 * with 401 before its filter runs, whatever the filter would return.
	 *
	 * @dataProvider filter_states_and_paths
	 */
	public function test_no_credential_is_refused_before_any_filter_runs( string $state, string $path ): void {
		$this->assertCount( 15, $this->routes() );
		foreach ( array_keys( $this->routes() ) as $name ) {
			$c = $this->run_case( $name, 'none', $path, $state );
			$this->assert_refused_without_side_effects( $c, 401 );
			$this->assertSame( array(), $c['calls'], "{$c['label']}: the site filter never runs for an anonymous request" );
		}
	}

	/**
	 * A filter can't grant the capability: a subscriber with a valid nonce
	 * stays refused when the filter returns true, and the filter never runs.
	 *
	 * @dataProvider filter_states_and_paths
	 */
	public function test_subscriber_with_valid_nonce_is_refused_whatever_the_filter_returns( string $state, string $path ): void {
		foreach ( array_keys( $this->routes() ) as $name ) {
			$c = $this->run_case( $name, 'subscriber_cookie', $path, $state );
			// The capability refusal is a 401 from Outpost's WP_Error, except
			// composer-config, whose callback returns false and core maps that
			// to rest_authorization_required_code(): 403 for a logged-in user.
			$this->assert_refused_without_side_effects( $c, 0 === strpos( $name, 'composer_' ) ? 403 : 401 );
			$this->assertSame( array(), $c['calls'], "{$c['label']}: the site filter never runs below edit_posts" );
		}
	}

	/**
	 * A filter can still restrict: an editor with a valid nonce, or with a
	 * create-scope token, is denied on every route when the filter returns
	 * false.
	 */
	public function test_editor_is_denied_when_the_filter_returns_false(): void {
		foreach ( array( 'editor_cookie', 'editor_bearer' ) as $who ) {
			foreach ( array( 'core', 'bypass' ) as $path ) {
				foreach ( array_keys( $this->routes() ) as $name ) {
					$c = $this->run_case( $name, $who, $path, 'false' );
					$this->assert_refused_without_side_effects( $c, 0 === strpos( $name, 'composer_' ) ? 403 : 401 );
					$this->assertCount( 1, $c['calls'], "{$c['label']}: the site filter ran once" );
				}
			}
		}
	}

	/**
	 * Positive control: with the default filter, an editor with a valid nonce
	 * is admitted on every route, and the filter receives `true` and the
	 * request, so it decides from an admitted state.
	 */
	public function test_editor_is_admitted_and_the_filter_sees_true_and_the_request(): void {
		foreach ( array_keys( $this->routes() ) as $name ) {
			$c = $this->run_case( $name, 'editor_cookie', 'core', 'default' );
			$this->assertTrue( $c['admitted'], "{$c['label']}: admitted" );
			$this->assertSame( 200, $c['status'], "{$c['label']}: status" );
			$this->assertCount( 1, $c['calls'], "{$c['label']}: the site filter ran once" );
			$this->assertTrue( $c['calls'][0][0], "{$c['label']}: the filter's value is true" );
			$this->assertInstanceOf( WP_REST_Request::class, $c['calls'][0][1] ?? null, "{$c['label']}: the filter receives the request" );
		}
		$this->assertSame( 0, $this->transport, 'No HTTP transport ran.' );
	}
}
