<?php
/**
 * Unit tests for Outpost_Media_Lookup_Endpoint.
 *
 * The endpoint is a same-site proxy in front of the Post Kinds for IndieWeb
 * lookup REST routes (`/post-kinds-indieweb/v1/lookup/{video,book,music,game,
 * venue}`). Outpost dispatches internally via `rest_do_request` — mocked here
 * so tests never touch a live REST server or the Post Kinds plugin.
 *
 * Coverage mirrors PreviewEndpointTest / ComposerConfigEndpointTest:
 *   - kind → PKIW category mapping (+ the outpost_media_lookup_kind_map filter)
 *   - Post Kinds inactive gate (501, and the inner dispatch never fires)
 *   - bearer-in-body auth (has_bearer_header)
 *   - api_not_configured passthrough (graceful, not an error)
 *   - row normalization to {title,cover,creator,year,external_id,url}
 *   - edit_posts permission gate with absence-of-side-effects
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Media_Lookup_Endpoint;
use WP_Error;
use WP_Mock;
use WP_REST_Request;
use WP_REST_Response;

final class MediaLookupEndpointTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		unset(
			$_POST['access_token'],
			$_SERVER['HTTP_AUTHORIZATION'],
			$_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
			$_SERVER['REQUEST_URI']
		);
		WP_Mock::tearDown();
	}

	/**
	 * Invoke a private/protected static method by reflection.
	 *
	 * @param array<int, mixed> $args
	 * @return mixed
	 */
	private function invoke_private( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( Outpost_Media_Lookup_Endpoint::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null, ...$args );
	}

	private function make_request( string $kind, string $q, string $type = '' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/outpost/v1/lookup' );
		$request->set_param( 'kind', $kind );
		$request->set_param( 'q', $q );
		if ( '' !== $type ) {
			$request->set_param( 'type', $type );
		}
		return $request;
	}

	/**
	 * Prime the mocks a successful authenticated + Post-Kinds-active
	 * handle_request path needs, minus rest_do_request (each test sets that).
	 */
	private function prime_active_authed(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->andReturn( true );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static fn( $v ) => is_string( $v ) ? trim( $v ) : $v
		);
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing(
			static fn( $v ) => is_string( $v ) ? strtolower( $v ) : $v
		);
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			static fn( $tag, $value ) => $value
		);
		WP_Mock::userFunction( 'rest_ensure_response' )->andReturnUsing(
			static fn( $data ) => new WP_REST_Response( $data )
		);
	}

	// --- kind → category mapping -------------------------------------------

	public function test_endpoint_for_kind_maps_composer_variants(): void {
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			static fn( $tag, $value ) => $value
		);
		$this->assertSame( 'video', $this->invoke_private( 'category_for_kind', array( 'watch' ) ) );
		$this->assertSame( 'book', $this->invoke_private( 'category_for_kind', array( 'read' ) ) );
		$this->assertSame( 'music', $this->invoke_private( 'category_for_kind', array( 'listen' ) ) );
		$this->assertSame( 'music', $this->invoke_private( 'category_for_kind', array( 'jam' ) ) );
		$this->assertSame( 'game', $this->invoke_private( 'category_for_kind', array( 'play' ) ) );
		$this->assertSame( 'game', $this->invoke_private( 'category_for_kind', array( 'game' ) ) );
		$this->assertSame( 'venue', $this->invoke_private( 'category_for_kind', array( 'checkin' ) ) );
	}

	public function test_endpoint_for_kind_accepts_category_aliases(): void {
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			static fn( $tag, $value ) => $value
		);
		$this->assertSame( 'video', $this->invoke_private( 'category_for_kind', array( 'video' ) ) );
		$this->assertSame( 'book', $this->invoke_private( 'category_for_kind', array( 'book' ) ) );
		$this->assertSame( 'music', $this->invoke_private( 'category_for_kind', array( 'music' ) ) );
		$this->assertSame( 'venue', $this->invoke_private( 'category_for_kind', array( 'venue' ) ) );
	}

	public function test_endpoint_for_kind_returns_null_for_unknown(): void {
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			static fn( $tag, $value ) => $value
		);
		$this->assertNull( $this->invoke_private( 'category_for_kind', array( 'sasquatch' ) ) );
		$this->assertNull( $this->invoke_private( 'category_for_kind', array( '' ) ) );
	}

	public function test_kind_map_filter_can_extend(): void {
		WP_Mock::onFilter( 'outpost_media_lookup_kind_map' )
			->with(
				array(
					'watch'   => 'video',
					'video'   => 'video',
					'read'    => 'book',
					'book'    => 'book',
					'listen'  => 'music',
					'jam'     => 'music',
					'music'   => 'music',
					'play'    => 'game',
					'game'    => 'game',
					'checkin' => 'venue',
					'venue'   => 'venue',
				)
			)
			->reply(
				array(
					'watch'  => 'video',
					'cook'   => 'recipe',
				)
			);
		$this->assertSame( 'recipe', $this->invoke_private( 'category_for_kind', array( 'cook' ) ) );
	}

	// --- normalization -----------------------------------------------------

	public function test_normalize_row_maps_tmdb_movie_shape(): void {
		$row = array(
			'tmdb_id'      => 27205,
			'id'           => 27205,
			'title'        => 'Inception',
			'poster'       => 'https://image.example/poster.jpg',
			'release_date' => '2010-07-16',
			'year'         => '2010',
			'overview'     => 'ignored',
		);
		$out = $this->invoke_private( 'normalize_row', array( $row ) );
		$this->assertSame( 'Inception', $out['title'] );
		$this->assertSame( 'https://image.example/poster.jpg', $out['cover'] );
		$this->assertSame( '2010', $out['year'] );
		$this->assertSame( '27205', $out['external_id'] );
		$this->assertArrayHasKey( 'creator', $out );
		$this->assertArrayHasKey( 'url', $out );
	}

	public function test_normalize_row_maps_openlibrary_book_shape(): void {
		$row = array(
			'title'              => 'Dune',
			'author'             => 'Frank Herbert',
			'cover'              => 'https://covers.example/dune.jpg',
			'first_publish_year' => 1965,
			'key'                => '/works/OL893415W',
		);
		$out = $this->invoke_private( 'normalize_row', array( $row ) );
		$this->assertSame( 'Dune', $out['title'] );
		$this->assertSame( 'Frank Herbert', $out['creator'] );
		$this->assertSame( 'https://covers.example/dune.jpg', $out['cover'] );
		$this->assertSame( '1965', $out['year'] );
		$this->assertSame( '/works/OL893415W', $out['external_id'] );
	}

	public function test_normalize_row_maps_musicbrainz_shape(): void {
		$row = array(
			'title'  => 'Discovery',
			'artist' => 'Daft Punk',
			'id'     => 'mbid-1234',
			'date'   => '2001-03-12',
		);
		$out = $this->invoke_private( 'normalize_row', array( $row ) );
		$this->assertSame( 'Discovery', $out['title'] );
		$this->assertSame( 'Daft Punk', $out['creator'] );
		$this->assertSame( '2001', $out['year'] );
		$this->assertSame( 'mbid-1234', $out['external_id'] );
	}

	public function test_normalize_row_joins_creator_array_of_strings(): void {
		$row = array(
			'title'   => 'Good Omens',
			'authors' => array( 'Terry Pratchett', 'Neil Gaiman' ),
		);
		$out = $this->invoke_private( 'normalize_row', array( $row ) );
		$this->assertSame( 'Terry Pratchett, Neil Gaiman', $out['creator'] );
	}

	public function test_normalize_row_joins_creator_array_of_objects(): void {
		$row = array(
			'title'   => 'Subject',
			'authors' => array(
				array( 'name' => 'Ada Lovelace' ),
				array( 'name' => 'Alan Turing' ),
			),
		);
		$out = $this->invoke_private( 'normalize_row', array( $row ) );
		$this->assertSame( 'Ada Lovelace, Alan Turing', $out['creator'] );
	}

	public function test_normalize_row_falls_back_to_name_and_image(): void {
		$row = array(
			'name'  => 'The Blue Note',
			'image' => 'https://img.example/venue.jpg',
		);
		$out = $this->invoke_private( 'normalize_row', array( $row ) );
		$this->assertSame( 'The Blue Note', $out['title'] );
		$this->assertSame( 'https://img.example/venue.jpg', $out['cover'] );
	}

	public function test_normalize_row_derives_year_from_release_date(): void {
		$row = array(
			'title'        => 'The Matrix',
			'release_date' => '1999-03-31',
		);
		$out = $this->invoke_private( 'normalize_row', array( $row ) );
		$this->assertSame( '1999', $out['year'] );
	}

	public function test_normalize_row_empty_defaults(): void {
		$out = $this->invoke_private( 'normalize_row', array( array() ) );
		$this->assertSame( '', $out['title'] );
		$this->assertSame( '', $out['cover'] );
		$this->assertSame( '', $out['creator'] );
		$this->assertSame( '', $out['year'] );
		$this->assertSame( '', $out['external_id'] );
		$this->assertSame( '', $out['url'] );
	}

	public function test_extract_rows_handles_list(): void {
		$rows = $this->invoke_private( 'extract_rows', array( array( array( 'title' => 'A' ), array( 'title' => 'B' ) ) ) );
		$this->assertCount( 2, $rows );
	}

	public function test_extract_rows_unwraps_results_key(): void {
		$rows = $this->invoke_private( 'extract_rows', array( array( 'results' => array( array( 'title' => 'A' ) ) ) ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'A', $rows[0]['title'] );
	}

	public function test_extract_rows_wraps_single_assoc_row(): void {
		$rows = $this->invoke_private( 'extract_rows', array( array( 'title' => 'Solo', 'year' => '2018' ) ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Solo', $rows[0]['title'] );
	}

	// --- handle_request: gating --------------------------------------------

	public function test_handle_request_501_when_post_kinds_inactive(): void {
		// Post Kinds absent → gate returns 501 BEFORE any inner dispatch.
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static fn( $v ) => is_string( $v ) ? trim( $v ) : $v
		);
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing(
			static fn( $v ) => is_string( $v ) ? strtolower( $v ) : $v
		);
		WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			static fn( $tag, $value ) => $value
		);
		// Absence of side effects: the protected inner dispatch never runs.
		WP_Mock::userFunction( 'rest_do_request' )->never();

		$result = Outpost_Media_Lookup_Endpoint::handle_request( $this->make_request( 'watch', 'Inception' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'post_kinds_inactive', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 501, $data['status'] );
	}

	public function test_handle_request_400_for_unknown_kind(): void {
		$this->prime_active_authed();
		WP_Mock::userFunction( 'rest_do_request' )->never();
		$result = Outpost_Media_Lookup_Endpoint::handle_request( $this->make_request( 'sasquatch', 'Inception' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_kind', $result->get_error_code() );
	}

	public function test_handle_request_400_for_short_query(): void {
		$this->prime_active_authed();
		WP_Mock::userFunction( 'rest_do_request' )->never();
		$result = Outpost_Media_Lookup_Endpoint::handle_request( $this->make_request( 'watch', 'a' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_query', $result->get_error_code() );
	}

	// --- handle_request: happy path + inner dispatch -----------------------

	public function test_handle_request_returns_normalized_results(): void {
		$this->prime_active_authed();
		$inner = new WP_REST_Response(
			array(
				array(
					'tmdb_id' => 27205,
					'title'   => 'Inception',
					'poster'  => 'https://img.example/p.jpg',
					'year'    => '2010',
				),
			),
			200
		);
		WP_Mock::userFunction( 'rest_do_request' )->once()->andReturn( $inner );

		$result = Outpost_Media_Lookup_Endpoint::handle_request( $this->make_request( 'watch', 'Inception', 'movie' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertFalse( $data['notConfigured'] );
		$this->assertCount( 1, $data['results'] );
		$this->assertSame( 'Inception', $data['results'][0]['title'] );
		$this->assertSame( 'https://img.example/p.jpg', $data['results'][0]['cover'] );
		$this->assertSame( '27205', $data['results'][0]['external_id'] );
	}

	public function test_handle_request_dispatches_to_correct_pkiw_route(): void {
		$this->prime_active_authed();
		$captured = null;
		WP_Mock::userFunction( 'rest_do_request' )->once()->andReturnUsing(
			function ( $request ) use ( &$captured ) {
				$captured = $request;
				return new WP_REST_Response( array(), 200 );
			}
		);

		Outpost_Media_Lookup_Endpoint::handle_request( $this->make_request( 'read', 'Dune' ) );

		$this->assertInstanceOf( WP_REST_Request::class, $captured );
		$this->assertSame( 'GET', $captured->get_method() );
		$this->assertSame( '/post-kinds-indieweb/v1/lookup/book', $captured->get_route() );
		$this->assertSame( 'Dune', $captured->get_param( 'q' ) );
	}

	public function test_handle_request_passes_movie_tv_type_for_video(): void {
		$this->prime_active_authed();
		$captured = null;
		WP_Mock::userFunction( 'rest_do_request' )->once()->andReturnUsing(
			function ( $request ) use ( &$captured ) {
				$captured = $request;
				return new WP_REST_Response( array(), 200 );
			}
		);

		Outpost_Media_Lookup_Endpoint::handle_request( $this->make_request( 'watch', 'The Bear', 'tv' ) );

		$this->assertSame( 'tv', $captured->get_param( 'type' ) );
	}

	public function test_handle_request_not_configured_passthrough(): void {
		$this->prime_active_authed();
		$inner = new WP_REST_Response(
			array(
				'code'    => 'api_not_configured',
				'message' => 'RAWG API is not configured.',
				'data'    => array( 'status' => 400 ),
			),
			400
		);
		WP_Mock::userFunction( 'rest_do_request' )->once()->andReturn( $inner );

		$result = Outpost_Media_Lookup_Endpoint::handle_request( $this->make_request( 'game', 'Catan' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertTrue( $data['notConfigured'] );
		$this->assertSame( array(), $data['results'] );
	}

	public function test_handle_request_inner_unauthorized_surfaces_401(): void {
		// The GoDaddy inner-auth failure mode: inner dispatch 403s because the
		// bearer rode in the body and never reached the WP user. Surface as 401.
		$this->prime_active_authed();
		$inner = new WP_REST_Response(
			array(
				'code'    => 'rest_forbidden',
				'message' => 'Sorry, you are not allowed to do that.',
				'data'    => array( 'status' => 403 ),
			),
			403
		);
		WP_Mock::userFunction( 'rest_do_request' )->once()->andReturn( $inner );

		$result = Outpost_Media_Lookup_Endpoint::handle_request( $this->make_request( 'watch', 'Inception' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unauthorized', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 401, $data['status'] );
	}

	public function test_handle_request_inner_404_returns_empty_results(): void {
		$this->prime_active_authed();
		$inner = new WP_REST_Response(
			array(
				'code'    => 'game_not_found',
				'message' => 'Game not found.',
				'data'    => array( 'status' => 404 ),
			),
			404
		);
		WP_Mock::userFunction( 'rest_do_request' )->once()->andReturn( $inner );

		$result = Outpost_Media_Lookup_Endpoint::handle_request( $this->make_request( 'game', 'Nonexistent' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertFalse( $data['notConfigured'] );
		$this->assertSame( array(), $data['results'] );
	}

	public function test_handle_request_inner_server_error_surfaces_502(): void {
		$this->prime_active_authed();
		$inner = new WP_REST_Response(
			array(
				'code'    => 'lookup_failed',
				'message' => 'Upstream boom.',
				'data'    => array( 'status' => 500 ),
			),
			500
		);
		WP_Mock::userFunction( 'rest_do_request' )->once()->andReturn( $inner );

		$result = Outpost_Media_Lookup_Endpoint::handle_request( $this->make_request( 'watch', 'Inception' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'lookup_failed', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 502, $data['status'] );
	}

	// --- permission callback + bearer-in-body ------------------------------

	public function test_permission_denied_when_unauthenticated(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( false );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::onFilter( 'outpost_media_lookup_permission' )->with( false )->reply( false );
		// Absence of side effects: a denied request never dispatches to PKIW.
		WP_Mock::userFunction( 'rest_do_request' )->never();

		$result = Outpost_Media_Lookup_Endpoint::check_permission();

		$this->assertInstanceOf( WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertSame( 401, $data['status'] );
	}

	public function test_permission_granted_for_edit_posts(): void {
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::onFilter( 'outpost_media_lookup_permission' )->with( true )->reply( true );
		$this->assertTrue( Outpost_Media_Lookup_Endpoint::check_permission() );
	}

	public function test_permission_granted_for_body_access_token(): void {
		// GoDaddy strips Authorization; the token rides in the POST body.
		$_POST['access_token'] = 'secret-token'; // outpost-lint:fixture-credential
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( false );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static fn( $v ) => is_string( $v ) ? trim( $v ) : $v
		);
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::onFilter( 'outpost_media_lookup_permission' )->with( true )->reply( true );

		$this->assertTrue( Outpost_Media_Lookup_Endpoint::check_permission() );
	}

	// --- GoDaddy header reinjection (determine_current_user shim) -----------

	private function mock_body_token_helpers(): void {
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static fn( $v ) => is_string( $v ) ? trim( $v ) : $v
		);
	}

	public function test_reinject_restores_header_from_body_token(): void {
		// Mobile on GoDaddy: header stripped, token in the body, no WP user yet.
		$_SERVER['REQUEST_URI'] = '/wp-json/outpost/v1/lookup?_t=1';
		$_POST['access_token']  = 'live-token'; // outpost-lint:fixture-credential
		$this->mock_body_token_helpers();

		$result = Outpost_Media_Lookup_Endpoint::reinject_bearer_from_body( null );

		$this->assertNull( $result, 'must pass through — it restores the header, it does not resolve the user' );
		$this->assertSame( 'Bearer live-token', $_SERVER['HTTP_AUTHORIZATION'] ?? '' );
	}

	public function test_reinject_skips_when_header_already_present(): void {
		$_SERVER['REQUEST_URI']       = '/wp-json/outpost/v1/lookup';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer already-here'; // outpost-lint:fixture-credential
		$_POST['access_token']         = 'other-token'; // outpost-lint:fixture-credential
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );

		Outpost_Media_Lookup_Endpoint::reinject_bearer_from_body( null );

		$this->assertSame( 'Bearer already-here', $_SERVER['HTTP_AUTHORIZATION'] );
	}

	public function test_reinject_skips_when_not_the_lookup_route(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
		$_POST['access_token']  = 'tok'; // outpost-lint:fixture-credential
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );

		Outpost_Media_Lookup_Endpoint::reinject_bearer_from_body( null );

		$this->assertArrayNotHasKey( 'HTTP_AUTHORIZATION', $_SERVER, 'must not touch auth for unrelated routes' );
	}

	public function test_reinject_passes_through_already_resolved_user(): void {
		// A cookie/header already authenticated the request — do nothing.
		$_SERVER['REQUEST_URI'] = '/wp-json/outpost/v1/lookup';
		$_POST['access_token']  = 'tok'; // outpost-lint:fixture-credential

		$result = Outpost_Media_Lookup_Endpoint::reinject_bearer_from_body( 7 );

		$this->assertSame( 7, $result );
		$this->assertArrayNotHasKey( 'HTTP_AUTHORIZATION', $_SERVER );
	}
}
