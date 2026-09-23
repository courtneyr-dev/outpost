<?php
/**
 * Unit tests for Outpost_Syndication_Capture_Controller (F12).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Syndication_Capture_Controller;
use Outpost_Manual_Share_Audit_Log;
use Outpost_Manual_Share_Pending_Capture_Detector;
use Outpost_Manual_Share_Syndication_Writeback;
use WP_Error;
use WP_Mock;
use WP_REST_Request;

final class SyndicationCaptureControllerTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $meta_store = array();

	private bool $user_can_edit_post = true;

	private bool $user_logged_in = true;

	public function setUp(): void {
		WP_Mock::setUp();
		// Outpost_Request_Headers sanitizes every $_SERVER read.
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => is_string( $v ) ? trim( $v ) : '' );
		$this->meta_store         = array();
		$this->user_can_edit_post = true;
		$this->user_logged_in      = true;
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests( null );

		WP_Mock::userFunction( 'wp_generate_uuid4' )->andReturnUsing(
			static fn (): string => 'uuid-' . bin2hex( random_bytes( 4 ) )
		);
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn ( string $u ): string => $u );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static fn ( string $u ) => parse_url( $u )
		);
		WP_Mock::userFunction( 'wp_http_validate_url' )->andReturnUsing(
			static function ( string $url ) {
				$parts = parse_url( $url );
				if ( false === $parts || empty( $parts['host'] ) ) {
					return false;
				}
				$host = strtolower( (string) $parts['host'] );
				if ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
					return false;
				}
				return $url;
			}
		);
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			function ( int $post_id, string $key, bool $single ) {
				return $this->meta_store[ $post_id ][ $key ] ?? '';
			}
		);
		WP_Mock::userFunction( 'update_post_meta' )->andReturnUsing(
			function ( int $post_id, string $key, $value ): bool {
				$this->meta_store[ $post_id ][ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'current_user_can' )->andReturnUsing(
			function ( string $cap, int $post_id = 0 ): bool {
				return $this->user_can_edit_post;
			}
		);
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturnUsing(
			fn (): bool => $this->user_logged_in
		);
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		WP_Mock::userFunction( 'get_post' )->andReturnUsing(
			static fn ( int $post_id ) => new \WP_Post( array(
				'ID'          => $post_id,
				'post_title'  => 'Post ' . $post_id,
				'post_author' => 7,
			) )
		);
		WP_Mock::userFunction( 'get_permalink' )->andReturnUsing(
			static fn ( int $post_id ): string => 'https://example.com/posts/' . $post_id
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests( null );
		unset(
			$_SERVER['HTTP_AUTHORIZATION'],
			$_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
			$_REQUEST['_wpnonce'],
			$GLOBALS['wp_rest_auth_cookie']
		);
	}

	/**
	 * @param int|false $determine_user User resolved by bearer validation.
	 */
	private function mock_filters( $determine_user ): void {
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			static function ( $hook, $value ) use ( $determine_user ) {
				if ( 'determine_current_user' === $hook ) {
					return $determine_user;
				}
				return $value;
			}
		);
	}

	public function test_permission_rejects_unvalidated_bearer_header(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer x'; // outpost-lint:fixture-credential
		$this->user_can_edit_post        = false;
		$this->user_logged_in             = false;
		$this->mock_filters( false );

		$result = Outpost_Syndication_Capture_Controller::check_permission( new \WP_REST_Request( 'POST', '/' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] ?? null );
		$this->assertSame( array(), $this->meta_store );
	}

	public function test_permission_allows_validated_bearer_editor(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		$this->user_logged_in             = false;
		WP_Mock::userFunction( 'wp_set_current_user' )->with( 42 )->andReturn( null );
		$this->mock_filters( 42 );
		// H6: bearer_has_scope() reads indieauth_scopes via the real
		// apply_filters() shim (WP_Mock::onFilter), not the userFunction
		// mock mock_filters() sets up — that override is inert for this
		// call (see "Scope source" in trait-bearer-auth.php). POST is the
		// mutating branch on this controller, so `create`/`update` only.
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'create' ) );

		$this->assertTrue( Outpost_Syndication_Capture_Controller::check_permission( new \WP_REST_Request( 'POST', '/' ) ) );
	}

	// =====================================================================
	// H6: bearer-authenticated /manual-share/capture requires create/update
	// scope. `mock_filters()` above stubs `apply_filters` wholesale, which
	// WP_Mock never actually routes `apply_filters()` calls through (that
	// function is on WP_Mock's built-in list and is never Patchwork-
	// redefined) — determine_current_user and indieauth_scopes must be
	// driven through WP_Mock::onFilter() instead, the mechanism the real
	// apply_filters() shim consults.
	//
	// A note on scope choice for these cases: under real IndieAuth (see
	// includes/class-scopes.php's Scopes::register_builtin_scopes()), `read`
	// and `update` ALONE never satisfy `current_user_can('edit_posts')` in
	// the first place — `read`'s only mapped capability is `read`, and
	// `update`'s mapped capabilities are `edit_published_posts` /
	// `edit_others_posts` (not the plural `edit_posts` this route's guard
	// checks). Only `create` and `draft` map to `edit_posts`. That makes
	// `draft` the one IndieAuth scope that reaches this trait's OWN 403
	// branch on a mutating route in a real deployment — a `draft`-scoped
	// token passes `current_user_can('edit_posts')` but is neither `create`
	// nor `update`, so it isolates this trait's scope check as the actual
	// blocker. The `read`-scope tests below are still useful as a unit-level
	// check of `bearer_has_scope()`'s own logic, but in this suite
	// `current_user_can` is manually stubbed and does not model IndieAuth's
	// `map_meta_cap` filter, so they would not by themselves prove the
	// gate matters against a real deployment the way the `draft` case does.
	// =====================================================================

	public function test_permission_denies_bearer_token_scoped_for_profile_only_on_capture(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		$this->user_logged_in             = false;
		WP_Mock::userFunction( 'wp_set_current_user' )->with( 42 )->andReturn( null );
		WP_Mock::onFilter( 'determine_current_user' )->with( false )->reply( 42 );
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'profile' ) );

		$result = Outpost_Syndication_Capture_Controller::check_permission(
			new \WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	public function test_permission_allows_bearer_token_scoped_for_create_on_capture(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		$this->user_logged_in             = false;
		WP_Mock::userFunction( 'wp_set_current_user' )->with( 42 )->andReturn( null );
		WP_Mock::onFilter( 'determine_current_user' )->with( false )->reply( 42 );
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'create' ) );

		$this->assertTrue(
			Outpost_Syndication_Capture_Controller::check_permission(
				new \WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' )
			)
		);
	}

	public function test_permission_read_scope_alone_is_insufficient_for_capture(): void {
		// /capture mutates (writes completed_at + silo_url); unlike the
		// read-only GET /pending route on this same controller, a `read`
		// scope alone must not authorize it.
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		$this->user_logged_in             = false;
		WP_Mock::userFunction( 'wp_set_current_user' )->with( 42 )->andReturn( null );
		WP_Mock::onFilter( 'determine_current_user' )->with( false )->reply( 42 );
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'read' ) );

		$result = Outpost_Syndication_Capture_Controller::check_permission(
			new \WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	public function test_permission_read_scope_authorizes_get_pending(): void {
		// GET /pending is read-only, so a `read`-only token is enough.
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		$this->user_logged_in             = false;
		WP_Mock::userFunction( 'wp_set_current_user' )->with( 42 )->andReturn( null );
		WP_Mock::onFilter( 'determine_current_user' )->with( false )->reply( 42 );
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'read' ) );

		$this->assertTrue(
			Outpost_Syndication_Capture_Controller::check_permission(
				new \WP_REST_Request( 'GET', '/outpost/v1/manual-share/pending' )
			)
		);
	}

	public function test_permission_draft_scope_alone_is_insufficient_for_capture(): void {
		// `draft` maps to `edit_posts` under real IndieAuth (see the note
		// above the H6 section), so — unlike `read` — this genuinely
		// isolates the trait's own create/update requirement as the reason
		// a `draft`-scoped token is refused on the mutating /capture route.
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		$this->user_logged_in             = false;
		WP_Mock::userFunction( 'wp_set_current_user' )->with( 42 )->andReturn( null );
		WP_Mock::onFilter( 'determine_current_user' )->with( false )->reply( 42 );
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'draft' ) );

		$result = Outpost_Syndication_Capture_Controller::check_permission(
			new \WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * H6 fix round 1, Critical 1 regression: IndieAuth's own
	 * `determine_current_user` runs globally at priority 15, during
	 * WordPress's normal early current-user resolution — well before this
	 * route's permission_callback runs. A header (or form-encoded) bearer
	 * token is therefore routinely ALREADY resolved (`is_user_logged_in()`
	 * already true) by the time `authenticate_bearer_token()` executes; its
	 * early return means this trait's OWN resolution branch never fires,
	 * even though a real bearer credential authenticated the request. The
	 * scope check must still run — driven by the credential's presence on
	 * the request, not by who resolved the current user.
	 */
	public function test_permission_refuses_already_logged_in_header_token_scoped_for_draft_on_capture(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		$this->user_logged_in             = true;
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'draft' ) );

		$result = Outpost_Syndication_Capture_Controller::check_permission(
			new \WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	// =====================================================================
	// H6 fix round 2: the trait must see every credential IndieAuth
	// authenticates. IndieAuth reads the header with an unanchored
	// `/Bearer ([\x20-\x7E]+)/` and falls back to getallheaders(). Round 1
	// matched only `^\s*Bearer` in $_SERVER, so `Authorization: X Bearer
	// <draft token>` logged the user in through IndieAuth (and its
	// map_meta_cap grants `draft` edit_posts) while bearer_has_scope() saw
	// no credential and skipped the scope check on every mutating route.
	// =====================================================================

	/**
	 * Model the state IndieAuth's determine_current_user leaves behind after
	 * it verified a token: a non-empty `indieauth_response` and the token's
	 * scopes on `indieauth_scopes`.
	 *
	 * @param string[] $scopes Token scopes.
	 */
	private function mock_verified_indieauth_token( array $scopes ): void {
		WP_Mock::onFilter( 'indieauth_response' )->with( null )->reply(
			array(
				'scope' => implode( ' ', $scopes ),
				'user'  => 7,
			)
		);
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( $scopes );
	}

	public function test_permission_refuses_prefixed_bearer_header_scoped_for_draft_on_capture(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'X Bearer valid'; // outpost-lint:fixture-credential
		$this->user_logged_in          = true;
		$this->mock_verified_indieauth_token( array( 'draft' ) );

		$result = Outpost_Syndication_Capture_Controller::check_permission(
			new \WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	public function test_permission_allows_prefixed_bearer_header_scoped_for_create_on_capture(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'X Bearer valid'; // outpost-lint:fixture-credential
		$this->user_logged_in          = true;
		$this->mock_verified_indieauth_token( array( 'create' ) );

		$this->assertTrue(
			Outpost_Syndication_Capture_Controller::check_permission(
				new \WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' )
			)
		);
	}

	/**
	 * Isolates the `indieauth_response` signal: no header or body token the
	 * trait can read, yet IndieAuth verified one (a token source or header
	 * shape the trait's own reader does not cover).
	 */
	public function test_permission_refuses_indieauth_verified_request_with_no_readable_credential(): void {
		$this->user_logged_in = true;
		$this->mock_verified_indieauth_token( array( 'draft' ) );

		$result = Outpost_Syndication_Capture_Controller::check_permission(
			new \WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * Isolates the header regex: `indieauth_response` has no callback, so
	 * only the trait's own reader can mark `X Bearer <token>` as bearer.
	 */
	public function test_permission_reads_a_prefixed_bearer_header_without_indieauth_response(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'X Bearer valid'; // outpost-lint:fixture-credential
		$this->user_logged_in          = true;
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'draft' ) );

		$result = Outpost_Syndication_Capture_Controller::check_permission(
			new \WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * Isolates the getallheaders() fallback: $_SERVER carries no
	 * Authorization and `indieauth_response` has no callback, so only the
	 * trait's own reader can mark the request as bearer.
	 */
	public function test_permission_reads_a_header_only_getallheaders_exposes(): void {
		$this->user_logged_in = true;
		WP_Mock::userFunction( 'getallheaders' )->andReturn(
			array( 'authorization' => 'Bearer valid' ) // outpost-lint:fixture-credential
		);
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'draft' ) );

		$result = Outpost_Syndication_Capture_Controller::check_permission(
			new \WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * IndieAuth inactive: a credential is present but neither
	 * `indieauth_response` nor `indieauth_scopes` has a callback, so both
	 * return null. Another `determine_current_user` authority logged the
	 * user in; the gate fails closed.
	 */
	public function test_permission_fails_closed_when_a_credential_has_no_scope_source(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		$this->user_logged_in          = true;

		$result = Outpost_Syndication_Capture_Controller::check_permission(
			new \WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * An application-password `Basic` header is not a bearer credential: a
	 * cookie session carrying one keeps the plain `edit_posts` gate.
	 */
	public function test_permission_does_not_treat_a_basic_header_as_bearer(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'editor:abcd EFGH ijkl MNOP qrst UVWX' ); // outpost-lint:fixture-credential
		$this->user_logged_in          = true;

		$this->assertTrue(
			Outpost_Syndication_Capture_Controller::check_permission(
				new \WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' )
			)
		);
	}

	// =====================================================================
	// H6 fix round 3: a cookie session with a valid REST nonce is a
	// first-party browser request and skips the scope gate, whatever
	// Authorization header rides along. The nonce is checked first, then
	// the token signals. RestRouteResolutionTest's valid-nonce control
	// (admin cookie + nonce + an iOS Shortcut bearer header) got 403 from
	// the round 2 gate.
	// =====================================================================

	/**
	 * Model the request core's auth-cookie validation leaves behind: the
	 * `$wp_rest_auth_cookie` flag set, a logged-in user, and a `_wpnonce`
	 * that wp_verify_nonce( ..., 'wp_rest' ) accepts or rejects.
	 */
	private function arrive_as_cookie_session( bool $nonce_valid ): void {
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$this->user_logged_in           = true;
		$_REQUEST['_wpnonce']           = $nonce_valid ? 'valid-rest-nonce' : 'bogus-rest-nonce';
		WP_Mock::userFunction( 'wp_verify_nonce' )->andReturnUsing(
			static fn ( $nonce, $action ) => 'valid-rest-nonce' === $nonce && 'wp_rest' === $action ? 1 : false
		);
	}

	public function test_permission_allows_cookie_session_with_valid_nonce_and_a_stray_shortcut_bearer_header(): void {
		$this->arrive_as_cookie_session( true );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ShortcutToken0123456789abcdefABCDEF'; // outpost-lint:fixture-credential

		$this->assertTrue(
			Outpost_Syndication_Capture_Controller::check_permission(
				new \WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' )
			)
		);
	}

	public function test_permission_refuses_cookie_session_with_invalid_nonce_and_a_profile_scoped_token(): void {
		$this->arrive_as_cookie_session( false );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		$this->mock_verified_indieauth_token( array( 'profile' ) );

		$result = Outpost_Syndication_Capture_Controller::check_permission(
			new \WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * No valid nonce and no credential: the capability check decides alone,
	 * as it did before H6.
	 */
	public function test_permission_keeps_the_capability_result_for_cookie_session_with_invalid_nonce_and_no_credential(): void {
		$this->arrive_as_cookie_session( false );

		$this->assertTrue(
			Outpost_Syndication_Capture_Controller::check_permission(
				new \WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' )
			)
		);
	}

	/**
	 * A valid `wp_rest` nonce without core's auth-cookie flag is not a
	 * cookie session: a bearer-only client that somehow holds a nonce still
	 * goes through the scope gate.
	 */
	public function test_permission_applies_the_scope_gate_to_a_valid_nonce_without_the_auth_cookie(): void {
		$this->arrive_as_cookie_session( true );
		unset( $GLOBALS['wp_rest_auth_cookie'] );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		$this->mock_verified_indieauth_token( array( 'draft' ) );

		$result = Outpost_Syndication_Capture_Controller::check_permission(
			new \WP_REST_Request( 'POST', '/outpost/v1/manual-share/capture' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	private function build_capture_request( array $params ): WP_REST_Request {
		$request = $this->createMock( WP_REST_Request::class );
		$request->method( 'get_param' )->willReturnCallback(
			static fn ( string $key ) => $params[ $key ] ?? null
		);
		return $request;
	}

	private function seed_audit_entry( int $post_id, string $platform_id ): array {
		// Use the Audit_Log API to write a real entry so the controller's
		// lookup logic runs against persisted data.
		$entry = Outpost_Manual_Share_Audit_Log::add_entry(
			$post_id,
			$platform_id,
			Outpost_Manual_Share_Audit_Log::STRATEGY_NAVIGATOR_SHARE
		);
		return $entry;
	}

	// =====================================================================
	// Capture endpoint
	// =====================================================================

	public function test_capture_records_url_and_updates_audit_log(): void {
		$entry = $this->seed_audit_entry( 42, 'instagram-feed' );

		$response = Outpost_Syndication_Capture_Controller::handle_capture_request(
			$this->build_capture_request( array(
				'post_id'      => 42,
				'audit_log_id' => $entry['id'],
				'silo_url'     => 'https://www.instagram.com/p/abc',
			) )
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertSame( 'recorded', $payload['status'] );
		$this->assertSame( 'instagram-feed', $payload['platform_id'] );
		$this->assertCount( 1, $payload['syndication_links'] );

		$entries = Outpost_Manual_Share_Audit_Log::get_entries( 42 );
		$this->assertSame( 'https://www.instagram.com/p/abc', $entries[0]['silo_url'] );
		$this->assertNotNull( $entries[0]['completed_at'] );
		$this->assertSame( 'fired', $entries[0]['outcome'] );
	}

	public function test_capture_returns_400_for_invalid_url(): void {
		$entry = $this->seed_audit_entry( 42, 'instagram-feed' );

		$result = Outpost_Syndication_Capture_Controller::handle_capture_request(
			$this->build_capture_request( array(
				'post_id'      => 42,
				'audit_log_id' => $entry['id'],
				'silo_url'     => 'javascript:alert(1)',
			) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
	}

	public function test_capture_returns_400_for_zero_post_id(): void {
		$result = Outpost_Syndication_Capture_Controller::handle_capture_request(
			$this->build_capture_request( array(
				'post_id'      => 0,
				'audit_log_id' => 'whatever',
				'silo_url'     => 'https://example.com/p/abc',
			) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_post_id', $result->get_error_code() );
	}

	public function test_capture_returns_403_for_user_without_edit_permission(): void {
		$this->user_can_edit_post = false;
		$entry                    = $this->seed_audit_entry( 42, 'instagram-feed' );

		$result = Outpost_Syndication_Capture_Controller::handle_capture_request(
			$this->build_capture_request( array(
				'post_id'      => 42,
				'audit_log_id' => $entry['id'],
				'silo_url'     => 'https://www.instagram.com/p/abc',
			) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_post', $result->get_error_code() );
	}

	public function test_capture_returns_404_when_audit_entry_missing(): void {
		$result = Outpost_Syndication_Capture_Controller::handle_capture_request(
			$this->build_capture_request( array(
				'post_id'      => 42,
				'audit_log_id' => 'totally-fake-id',
				'silo_url'     => 'https://example.com/p/abc',
			) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'audit_log_entry_not_found', $result->get_error_code() );
	}

	public function test_capture_soft_warns_on_platform_mismatch(): void {
		$entry = $this->seed_audit_entry( 42, 'instagram-feed' );

		$response = Outpost_Syndication_Capture_Controller::handle_capture_request(
			$this->build_capture_request( array(
				'post_id'      => 42,
				'audit_log_id' => $entry['id'],
				'silo_url'     => 'https://twitter.com/user/status/1',
			) )
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertSame( 'mismatch_warning', $payload['status'] );
		// Audit log NOT updated yet — user must confirm.
		$entries = Outpost_Manual_Share_Audit_Log::get_entries( 42 );
		$this->assertNull( $entries[0]['silo_url'] );
		$this->assertNull( $entries[0]['completed_at'] );
	}

	public function test_capture_proceeds_when_mismatch_confirmed(): void {
		$entry = $this->seed_audit_entry( 42, 'instagram-feed' );

		$response = Outpost_Syndication_Capture_Controller::handle_capture_request(
			$this->build_capture_request( array(
				'post_id'          => 42,
				'audit_log_id'     => $entry['id'],
				'silo_url'         => 'https://twitter.com/user/status/1',
				'confirm_mismatch' => true,
			) )
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertSame( 'recorded', $payload['status'] );
		$this->assertTrue( $payload['mismatch_confirmed'] );
	}

	// =====================================================================
	// Pending endpoint
	// =====================================================================

	public function test_pending_returns_user_results(): void {
		// Seed one pending entry old enough to clear the grace period.
		$this->meta_store[42]['outpost_manual_share_log'] = array(
			array(
				'id'           => 'pending-1',
				'version'      => 1,
				'platform_id'  => 'instagram-feed',
				'fired_at'     => gmdate( 'c', time() - 120 ),
				'strategy'     => 'navigator_share',
				'outcome'      => 'unknown',
				'completed_at' => null,
				'silo_url'     => null,
			),
		);
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests(
			static fn ( int $user_id ): array => array( 42 )
		);

		$response = Outpost_Syndication_Capture_Controller::handle_pending_request(
			$this->createMock( WP_REST_Request::class )
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertCount( 1, $payload['pending'] );
		$this->assertSame( 42, $payload['pending'][0]['post_id'] );
	}

	public function test_pending_returns_empty_when_user_has_none(): void {
		Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests(
			static fn ( int $user_id ): array => array()
		);
		$response = Outpost_Syndication_Capture_Controller::handle_pending_request(
			$this->createMock( WP_REST_Request::class )
		);

		$payload = $response->get_data();
		$this->assertSame( array(), $payload['pending'] );
	}
}
