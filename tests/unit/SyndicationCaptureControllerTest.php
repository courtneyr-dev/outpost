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
			$_SERVER['REDIRECT_HTTP_AUTHORIZATION']
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
		// H6/H7: bearer_has_scope() reads indieauth_scopes via the real
		// apply_filters() shim (WP_Mock::onFilter), not the userFunction
		// mock mock_filters() sets up — that override is inert for this
		// call (see trait-bearer-auth.php discovery notes). POST is the
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
	 * H7 fix-round-1, Critical 1 regression: IndieAuth's own
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
