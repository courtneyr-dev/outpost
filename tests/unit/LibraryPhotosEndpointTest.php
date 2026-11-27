<?php
/**
 * Unit tests for Outpost_Library_Photos_Endpoint.
 *
 * The draft path must store the chosen attachment's ID and leave the
 * attachment itself untouched (parent, caption, alt, derivatives). The alt
 * cases must reach the saved block as the author chose them: described text
 * stays literal, and a decorative image gets alt="" (plus `isDecorative` and
 * role="none" where core's image block supports them).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Library_Photos_Endpoint;
use WP_Mock;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

final class LibraryPhotosEndpointTest extends \WP_Mock\Tools\TestCase {

	private const FRENCH_ALT = 'Un cercle jaune sur fond bleu, à côté d’un étang';

	public function setUp(): void {
		WP_Mock::setUp();
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => trim( (string) $v ) );
		WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		WP_Mock::userFunction( 'esc_url' )->andReturnUsing( static fn( $v ) => (string) $v );
		WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing( static fn( $v ) => (string) $v );
		WP_Mock::userFunction( 'get_comment_delimited_block_content' )->andReturnUsing(
			static fn( $name, $attrs, $html ) => '<!-- wp:image ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' -->' . "\n" . $html . "\n" . '<!-- /wp:image -->'
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	private function image( array $fields = array() ): WP_Post {
		return new WP_Post(
			$fields + array(
				'ID'          => 5,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => 9,
				'post_title'  => 'Existing fixture image',
			)
		);
	}

	// --- alt_choice ------------------------------------------------------------

	public function test_decorative_choice_carries_no_text(): void {
		$this->assertSame(
			array(
				'decorative' => true,
				'alt'        => '',
			),
			Outpost_Library_Photos_Endpoint::alt_choice( 'ignored text', true, 'stored alt' )
		);
	}

	public function test_explicit_text_is_kept_literally(): void {
		$this->assertSame(
			array(
				'decorative' => false,
				'alt'        => self::FRENCH_ALT,
			),
			Outpost_Library_Photos_Endpoint::alt_choice( self::FRENCH_ALT, false, 'stored alt' )
		);
	}

	public function test_absent_alt_falls_back_to_the_stored_alt(): void {
		$this->assertSame( 'stored alt', Outpost_Library_Photos_Endpoint::alt_choice( null, false, 'stored alt' )['alt'] );
	}

	public function test_explicit_empty_alt_without_decorative_is_refused(): void {
		// A present empty alt must not silently fall back to the stored one.
		$this->assertNull( Outpost_Library_Photos_Endpoint::alt_choice( '', false, 'stored alt' ) );
	}

	// --- image_block -----------------------------------------------------------

	public function test_block_stores_attachment_id_alt_and_caption(): void {
		$block = Outpost_Library_Photos_Endpoint::image_block(
			$this->image( array( 'post_excerpt' => 'Original fixture caption' ) ),
			'https://example.test/wp-content/uploads/fixture.jpg',
			array(
				'decorative' => false,
				'alt'        => self::FRENCH_ALT,
			),
			true
		);

		$this->assertStringContainsString( '"id":5', $block );
		$this->assertStringContainsString( 'alt="' . htmlspecialchars( self::FRENCH_ALT, ENT_QUOTES, 'UTF-8' ) . '"', $block );
		$this->assertStringContainsString( 'class="wp-image-5"/>', $block );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption">Original fixture caption</figcaption>', $block );
		$this->assertStringNotContainsString( 'isDecorative', $block );
		$this->assertStringNotContainsString( 'role=', $block );
	}

	public function test_block_escapes_markup_in_alt_once(): void {
		$block = Outpost_Library_Photos_Endpoint::image_block(
			$this->image(),
			'https://example.test/a.jpg',
			array(
				'decorative' => false,
				'alt'        => 'Tom & Jerry say "hi" <b>',
			),
			true
		);
		$this->assertStringContainsString( 'alt="Tom &amp; Jerry say &quot;hi&quot; &lt;b&gt;"', $block );
	}

	public function test_decorative_block_round_trips_into_core_image_when_supported(): void {
		$block = Outpost_Library_Photos_Endpoint::image_block(
			$this->image(),
			'https://example.test/a.jpg',
			array(
				'decorative' => true,
				'alt'        => '',
			),
			true
		);
		$this->assertStringContainsString( '"isDecorative":true', $block );
		$this->assertStringContainsString( 'alt="" class="wp-image-5" role="none"/>', $block );
	}

	public function test_decorative_block_is_plain_empty_alt_without_core_support(): void {
		$block = Outpost_Library_Photos_Endpoint::image_block(
			$this->image(),
			'https://example.test/a.jpg',
			array(
				'decorative' => true,
				'alt'        => '',
			),
			false
		);
		$this->assertStringContainsString( 'alt="" class="wp-image-5"/>', $block );
		$this->assertStringNotContainsString( 'isDecorative', $block );
		$this->assertStringNotContainsString( 'role=', $block );
	}

	// --- save draft -----------------------------------------------------------

	private function draft_request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/outpost/v1/library-photos' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/** Metadata Core writes for a finished image that needed no sub-sizes. */
	private const SMALL_IMAGE_METADATA = array(
		'width'  => 120,
		'height' => 80,
		'file'   => '2026/11/small.jpg',
		'sizes'  => array(),
	);

	/**
	 * @param mixed $metadata What wp_get_attachment_metadata() returns.
	 */
	private function allow_image( bool $can_edit = true, bool $can_read_parent = true, string $pending = '', $metadata = self::SMALL_IMAGE_METADATA ): void {
		WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $this->image( array( 'post_excerpt' => 'Original fixture caption' ) ) );
		WP_Mock::userFunction( 'wp_attachment_is_image', array( 'return' => true ) );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_post', 5 )->andReturn( $can_edit );
		WP_Mock::userFunction( 'current_user_can' )->with( 'read_post', 9 )->andReturn( $can_read_parent );
		WP_Mock::userFunction( 'wp_get_attachment_metadata' )->with( 5 )->andReturn( $metadata );
		WP_Mock::userFunction( 'get_post_meta' )->with( 5, Outpost_Library_Photos_Endpoint::PENDING_META, true )->andReturn( $pending );
	}

	private function forbid_attachment_writes(): void {
		foreach ( array( 'wp_update_post', 'update_post_meta', 'wp_update_attachment_metadata', 'wp_set_post_parent', 'delete_post_meta' ) as $fn ) {
			WP_Mock::userFunction( $fn, array( 'times' => 0 ) );
		}
	}

	public function test_saving_a_draft_stores_the_id_and_leaves_the_attachment_alone(): void {
		$this->allow_image();
		$this->forbid_attachment_writes();
		WP_Mock::userFunction( 'wp_get_attachment_image_url' )->with( 5, 'full' )->andReturn( 'https://example.test/fixture.jpg' );
		WP_Mock::userFunction( 'get_post_meta' )->with( 5, '_wp_attachment_image_alt', true )->andReturn( 'Synthetic attachment alt' );
		WP_Mock::userFunction( 'wp_slash' )->andReturnUsing( static fn( $v ) => $v );
		$registry = \Mockery::mock( 'alias:WP_Block_Type_Registry' );
		$registry->shouldReceive( 'get_instance->get_registered' )->andReturn( (object) array( 'attributes' => array() ) );

		$inserted = null;
		WP_Mock::userFunction( 'wp_insert_post' )->once()->andReturnUsing(
			static function ( $postarr ) use ( &$inserted ) {
				$inserted = $postarr;
				return 77;
			}
		);
		WP_Mock::userFunction( 'get_edit_post_link' )->with( 77, 'raw' )->andReturn( 'https://example.test/wp-admin/post.php?post=77&action=edit' );

		$response = Outpost_Library_Photos_Endpoint::handle( $this->draft_request( array( 'attachmentId' => 5 ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 77, $response->get_data()['id'] );
		$this->assertSame( 'draft', $inserted['post_status'] );
		$this->assertStringContainsString( '"id":5', $inserted['post_content'] );
		$this->assertStringContainsString( 'alt="Synthetic attachment alt"', $inserted['post_content'] );
		$this->assertStringContainsString( 'Original fixture caption', $inserted['post_content'] );
		$this->assertArrayNotHasKey( 'post_parent', $inserted );
	}

	public function test_decorative_draft_records_the_choice_for_activitypub(): void {
		$this->allow_image();
		WP_Mock::userFunction( 'wp_get_attachment_image_url' )->with( 5, 'full' )->andReturn( 'https://example.test/fixture.jpg' );
		WP_Mock::userFunction( 'get_post_meta' )->with( 5, '_wp_attachment_image_alt', true )->andReturn( 'Shared alt from another post' );
		WP_Mock::userFunction( 'wp_slash' )->andReturnUsing( static fn( $v ) => $v );
		$registry = \Mockery::mock( 'alias:WP_Block_Type_Registry' );
		$registry->shouldReceive( 'get_instance->get_registered' )->andReturn( (object) array( 'attributes' => array() ) );
		WP_Mock::userFunction( 'wp_insert_post' )->once()->andReturn( 78 );
		WP_Mock::userFunction( 'get_edit_post_link' )->andReturn( 'https://example.test/wp-admin/post.php?post=78&action=edit' );
		WP_Mock::userFunction( 'add_post_meta' )->once()->with( 78, Outpost_Library_Photos_Endpoint::DECORATIVE_META, 5 );

		$response = Outpost_Library_Photos_Endpoint::handle(
			$this->draft_request(
				array(
					'attachmentId' => 5,
					'alt'          => '',
					'decorative'   => true,
				)
			)
		);
		$this->assertSame( 201, $response->get_status() );
	}

	/**
	 * @return array<string, array{0: bool, 1: bool, 2: string, 3: mixed}>
	 */
	public static function unusable_images(): array {
		return array(
			'another user\'s image'                        => array( false, true, '', self::SMALL_IMAGE_METADATA ),
			'image on a post the user can\'t read'          => array( true, false, '', self::SMALL_IMAGE_METADATA ),
			'client upload awaiting finalize'              => array( true, true, '1', self::SMALL_IMAGE_METADATA ),
			// Core inserts the attachment, fires rest_after_insert_attachment (where
			// the pending mark lands), and only then writes metadata.
			'inserted, metadata not written yet (no mark)' => array( true, true, '', false ),
			'inserted, metadata empty (no mark)'           => array( true, true, '', array() ),
		);
	}

	/**
	 * @dataProvider unusable_images
	 *
	 * @param mixed $metadata What wp_get_attachment_metadata() returns.
	 */
	public function test_unusable_image_is_refused_without_side_effects( bool $can_edit, bool $can_read_parent, string $pending, $metadata ): void {
		$this->allow_image( $can_edit, $can_read_parent, $pending, $metadata );
		$this->forbid_attachment_writes();
		WP_Mock::userFunction( 'wp_insert_post', array( 'times' => 0 ) );

		$response = Outpost_Library_Photos_Endpoint::handle( $this->draft_request( array( 'attachmentId' => 5 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] );
	}

	// --- finalize tracking ------------------------------------------------------

	/**
	 * @return array{callback: array{0: \WP_REST_Attachments_Controller, 1: string}}
	 */
	private function core_finalize_handler(): array {
		return array( 'callback' => array( new \WP_REST_Attachments_Controller(), 'finalize_item' ) );
	}

	private function finalize_request( int $route_id, int $resolved_id ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wp/v2/media/' . $route_id . '/finalize' );
		// Core reads `id` from the body or query before the URL, so the ID it
		// finalizes can differ from the one in the route.
		$request->set_param( 'id', $resolved_id );
		return $request;
	}

	public function test_client_processed_upload_is_pending_until_finalize(): void {
		$upload = new WP_REST_Request( 'POST', '/wp/v2/media' );
		$upload->set_param( 'generate_sub_sizes', false );
		WP_Mock::userFunction( 'wp_attachment_is_image', array( 'return' => true ) );
		WP_Mock::userFunction( 'wp_is_client_side_media_processing_enabled', array( 'return' => true ) );
		WP_Mock::userFunction( 'update_post_meta' )->once()->with( 5, Outpost_Library_Photos_Endpoint::PENDING_META, '1' );
		Outpost_Library_Photos_Endpoint::track_client_upload( $this->image(), $upload, true );

		WP_Mock::userFunction( 'delete_post_meta' )->once()->with( 5, Outpost_Library_Photos_Endpoint::PENDING_META );
		$ok = new WP_REST_Response( array(), 200 );
		$this->assertSame( $ok, Outpost_Library_Photos_Endpoint::complete_client_upload( $ok, $this->core_finalize_handler(), $this->finalize_request( 5, 5 ) ) );
	}

	public function test_finalize_of_another_attachment_does_not_clear_the_route_id(): void {
		// `/media/5/finalize` with `id=9` in the body: Core authorizes and
		// finalizes 9, so 5 still has no derivatives and must stay pending.
		WP_Mock::userFunction( 'delete_post_meta', array( 'times' => 0 ) );
		$ok = new WP_REST_Response( array(), 200 );
		Outpost_Library_Photos_Endpoint::complete_client_upload( $ok, $this->core_finalize_handler(), $this->finalize_request( 5, 9 ) );
		$this->assertTrue( true );
	}

	public function test_a_non_core_handler_on_the_finalize_route_does_not_clear(): void {
		WP_Mock::userFunction( 'delete_post_meta', array( 'times' => 0 ) );
		$ok = new WP_REST_Response( array(), 200 );
		Outpost_Library_Photos_Endpoint::complete_client_upload( $ok, array( 'callback' => '__return_true' ), $this->finalize_request( 5, 5 ) );
		Outpost_Library_Photos_Endpoint::complete_client_upload( $ok, array( 'callback' => array( new \WP_REST_Attachments_Controller(), 'create_item' ) ), $this->finalize_request( 5, 5 ) );
		Outpost_Library_Photos_Endpoint::complete_client_upload( $ok, array(), $this->finalize_request( 5, 5 ) );
		$this->assertTrue( true );
	}

	public function test_failed_finalize_keeps_the_image_pending(): void {
		WP_Mock::userFunction( 'delete_post_meta', array( 'times' => 0 ) );
		$finalize = $this->finalize_request( 5, 5 );
		Outpost_Library_Photos_Endpoint::complete_client_upload( new \WP_Error( 'x', 'y' ), $this->core_finalize_handler(), $finalize );
		Outpost_Library_Photos_Endpoint::complete_client_upload( new WP_REST_Response( array(), 400 ), $this->core_finalize_handler(), $finalize );
		$this->assertTrue( true );
	}

	public function test_server_processed_upload_is_never_marked_pending(): void {
		$upload = new WP_REST_Request( 'POST', '/wp/v2/media' );
		WP_Mock::userFunction( 'update_post_meta', array( 'times' => 0 ) );
		Outpost_Library_Photos_Endpoint::track_client_upload( $this->image(), $upload, true );
		$this->assertTrue( true );
	}

	// --- list pagination --------------------------------------------------------

	/**
	 * Attachment rows newest first: rows 1000..941 are client uploads awaiting
	 * finalize, then 35 ready images 940..906.
	 *
	 * @param array<int, array<string, mixed>> $queries Captured get_posts() args.
	 */
	private function mock_library( array &$queries ): void {
		$rows    = range( 1000, 906 );
		$pending = range( 1000, 941 );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_others_posts' )->andReturn( true );
		WP_Mock::userFunction( 'get_posts' )->andReturnUsing(
			static function ( array $args ) use ( $rows, &$queries ): array {
				$queries[] = $args;
				return array_slice( $rows, $args['offset'], $args['posts_per_page'] );
			}
		);
		WP_Mock::userFunction( 'get_post' )->andReturnUsing(
			fn( int $id ) => $this->image(
				array(
					'ID'          => $id,
					'post_parent' => 0,
					'post_title'  => 'Fixture ' . $id,
				)
			)
		);
		WP_Mock::userFunction( 'wp_attachment_is_image', array( 'return' => true ) );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_post', \Mockery::type( 'int' ) )->andReturn( true );
		WP_Mock::userFunction( 'wp_get_attachment_metadata' )->andReturn( self::SMALL_IMAGE_METADATA );
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			static fn( int $id, string $key ) => Outpost_Library_Photos_Endpoint::PENDING_META === $key && in_array( $id, $pending, true ) ? '1' : ''
		);
		WP_Mock::userFunction( 'wp_get_attachment_image_url' )->andReturn( null );
		WP_Mock::userFunction( 'get_edit_post_link' )->andReturn( null );
		WP_Mock::userFunction( 'wp_strip_all_tags' )->andReturnUsing( static fn( $v ) => (string) $v );
	}

	private function list_request( int $offset ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/outpost/v1/library-photos' );
		$request->set_param( 'search', '' );
		$request->set_param( 'offset', $offset );
		return $request;
	}

	public function test_list_skips_unready_rows_before_deciding_the_page_is_full(): void {
		$queries = array();
		$this->mock_library( $queries );

		$first = Outpost_Library_Photos_Endpoint::handle( $this->list_request( 0 ) )->get_data();

		$this->assertCount( 30, $first['items'] );
		$this->assertSame( 940, $first['items'][0]['id'] );
		$this->assertSame( 911, $first['items'][29]['id'] );
		// 60 pending rows plus 30 listed: the next page starts at row 90 (id 910).
		$this->assertSame( 90, $first['nextOffset'] );

		$second = Outpost_Library_Photos_Endpoint::handle( $this->list_request( 90 ) )->get_data();
		$this->assertSame( array( 910, 909, 908, 907, 906 ), array_column( $second['items'], 'id' ) );
		$this->assertNull( $second['nextOffset'] );
	}

	public function test_list_orders_newest_first_with_an_id_tie_breaker(): void {
		$queries = array();
		$this->mock_library( $queries );
		Outpost_Library_Photos_Endpoint::handle( $this->list_request( 0 ) );

		$this->assertSame(
			array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			$queries[0]['orderby']
		);
		$this->assertSame( 'ids', $queries[0]['fields'] );
	}

	// --- Authorization header -----------------------------------------------------

	public function test_missing_authorization_header_gets_an_actionable_401(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );

		$result = Outpost_Library_Photos_Endpoint::permission_check( new WP_REST_Request( 'POST', '/outpost/v1/library-photos' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'outpost_authorization_header_missing', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_token_forwarded_as_redirect_http_authorization_is_accepted(): void {
		// Apache with mod_rewrite hands the header to PHP under this name only.
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		$GLOBALS['wp_rest_auth_cookie']         = null;
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 3 );
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'create' ) );
		WP_Mock::userFunction( 'current_user_can' )->with( 'upload_files' )->andReturn( true );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );

		try {
			$this->assertTrue( Outpost_Library_Photos_Endpoint::permission_check( new WP_REST_Request( 'POST', '/outpost/v1/library-photos' ) ) );
		} finally {
			unset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}
	}

	public function test_body_token_without_a_bearer_header_gets_the_actionable_401(): void {
		// IndieAuth signed the user in from the form body before the route ran.
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$GLOBALS['wp_rest_auth_cookie'] = null;
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 3 );
		WP_Mock::onFilter( 'indieauth_response' )->with( null )->reply( array( 'me' => 'https://example.test/' ) );
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'create' ) );
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		$request = new WP_REST_Request( 'POST', '/outpost/v1/library-photos' );
		$request->set_param( 'access_token', 'body-token' ); // outpost-lint:fixture-credential

		$result = Outpost_Library_Photos_Endpoint::permission_check( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'outpost_authorization_header_missing', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_body_token_is_refused_even_with_an_authorization_header(): void {
		// A header IndieAuth ignores lets it sign in from the body token instead.
		$_SERVER['HTTP_AUTHORIZATION']  = 'Bearer ignored-by-indieauth'; // outpost-lint:fixture-credential
		$GLOBALS['wp_rest_auth_cookie'] = null;
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 3 );
		WP_Mock::onFilter( 'indieauth_response' )->with( null )->reply( array( 'me' => 'https://example.test/' ) );
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'create' ) );
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		$request = new WP_REST_Request( 'POST', '/outpost/v1/library-photos' );
		$request->set_param( 'access_token', 'body-token' ); // outpost-lint:fixture-credential

		try {
			$result = Outpost_Library_Photos_Endpoint::permission_check( $request );
		} finally {
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_bearer_header_token_is_accepted(): void {
		$_SERVER['HTTP_AUTHORIZATION']  = 'Bearer valid'; // outpost-lint:fixture-credential
		$GLOBALS['wp_rest_auth_cookie'] = null;
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 3 );
		WP_Mock::onFilter( 'indieauth_response' )->with( null )->reply( array( 'me' => 'https://example.test/' ) );
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'create' ) );
		WP_Mock::userFunction( 'current_user_can' )->with( 'upload_files' )->andReturn( true );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );

		try {
			$this->assertTrue( Outpost_Library_Photos_Endpoint::permission_check( new WP_REST_Request( 'POST', '/outpost/v1/library-photos' ) ) );
		} finally {
			unset( $_SERVER['HTTP_AUTHORIZATION'] );
		}
	}

	public function test_cookie_session_with_a_rest_nonce_is_accepted(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$_SERVER['HTTP_X_WP_NONCE']     = 'nonce';
		$GLOBALS['wp_rest_auth_cookie'] = true;
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 3 );
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'wp_verify_nonce' )->with( 'nonce', 'wp_rest' )->andReturn( 1 );
		WP_Mock::userFunction( 'current_user_can' )->with( 'upload_files' )->andReturn( true );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );

		try {
			$this->assertTrue( Outpost_Library_Photos_Endpoint::permission_check( new WP_REST_Request( 'POST', '/outpost/v1/library-photos' ) ) );
		} finally {
			unset( $_SERVER['HTTP_X_WP_NONCE'] );
			$GLOBALS['wp_rest_auth_cookie'] = null;
		}
	}

	// --- ActivityPub --------------------------------------------------------------

	/**
	 * ActivityPub 9.4.4 isn't loaded in unit tests. These attachments have the
	 * shape its Post transformer passes to `activitypub_attachments`
	 * (Base::transform_attachment()): an image whose block alt is empty
	 * already carries the attachment's shared alt as `name`.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function activitypub_attachments(): array {
		return array(
			array(
				'type'      => 'Image',
				'url'       => 'https://example.test/uploads/embedded-1024x768.jpg',
				'mediaType' => 'image/jpeg',
				'name'      => self::FRENCH_ALT,
			),
			array(
				'type'      => 'Image',
				'url'       => 'https://example.test/uploads/explicit-1024x768.jpg',
				'mediaType' => 'image/jpeg',
				'name'      => 'Explicit alt text',
			),
			array(
				'type'      => 'Image',
				'url'       => 'https://example.test/uploads/decorative-1024x768.jpg',
				'mediaType' => 'image/jpeg',
				'name'      => 'Shared alt from another post',
			),
		);
	}

	/**
	 * @param array<string, mixed> $decorative_attrs Attributes of the third block.
	 * @param int[]                $recorded         DECORATIVE_META values on the post.
	 */
	private function mock_activitypub_post( array $decorative_attrs, array $recorded ): WP_Post {
		$blocks = array(
			array(
				'blockName'   => 'core/image',
				'attrs'       => array( 'id' => 11 ),
				'innerHTML'   => '<figure class="wp-block-image"><img src="x" alt="' . htmlspecialchars( self::FRENCH_ALT, ENT_QUOTES, 'UTF-8' ) . '" class="wp-image-11"/></figure>',
				'innerBlocks' => array(),
			),
			array(
				'blockName'   => 'core/image',
				'attrs'       => array( 'id' => 12 ),
				'innerHTML'   => '<figure class="wp-block-image"><img src="x" alt="Explicit alt text" class="wp-image-12"/></figure>',
				'innerBlocks' => array(),
			),
			array(
				'blockName'   => 'core/image',
				'attrs'       => array( 'id' => 13 ) + $decorative_attrs,
				'innerHTML'   => '<figure class="wp-block-image"><img src="x" alt="" class="wp-image-13"/></figure>',
				'innerBlocks' => array(),
			),
		);
		WP_Mock::userFunction( 'parse_blocks' )->andReturn( $blocks );
		WP_Mock::userFunction( 'get_post_meta' )->with( 40, Outpost_Library_Photos_Endpoint::DECORATIVE_META, false )->andReturn( $recorded );
		WP_Mock::userFunction( 'wp_get_attachment_image_src' )->andReturnUsing(
			static fn( int $id ) => array( 'https://example.test/uploads/' . array( 11 => 'embedded', 12 => 'explicit', 13 => 'decorative' )[ $id ] . '-1024x768.jpg', 1024, 768, true )
		);
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn( $v ) => (string) $v );
		return new WP_Post(
			array(
				'ID'           => 40,
				'post_content' => '<!-- wp:image {"id":11} -->...',
			)
		);
	}

	public function test_activitypub_keeps_isdecorative_image_description_empty(): void {
		$post = $this->mock_activitypub_post( array( 'isDecorative' => true ), array() );

		$out = Outpost_Library_Photos_Endpoint::keep_decorative_alt_empty( $this->activitypub_attachments(), $post );

		$this->assertSame( self::FRENCH_ALT, $out[0]['name'], 'embedded alt the author applied' );
		$this->assertSame( 'Explicit alt text', $out[1]['name'], 'explicit text' );
		$this->assertSame( '', $out[2]['name'], 'decorative: no fallback to the shared alt' );
	}

	public function test_activitypub_keeps_recorded_decorative_choice_empty_without_isdecorative(): void {
		$post = $this->mock_activitypub_post( array(), array( 13 ) );

		$out = Outpost_Library_Photos_Endpoint::keep_decorative_alt_empty( $this->activitypub_attachments(), $post );

		$this->assertSame( '', $out[2]['name'] );
		$this->assertSame( 'Explicit alt text', $out[1]['name'] );
	}

	public function test_activitypub_fallback_stays_for_an_empty_alt_nobody_marked_decorative(): void {
		$post = $this->mock_activitypub_post( array(), array() );

		$out = Outpost_Library_Photos_Endpoint::keep_decorative_alt_empty( $this->activitypub_attachments(), $post );

		$this->assertSame( $this->activitypub_attachments(), $out );
	}

	/**
	 * One post showing attachment 13 twice: ActivityPub sends the alt of the
	 * later use, so that use alone decides whether the name is cleared.
	 *
	 * @param array<int, array{attrs: array<string, mixed>, alt: string}> $uses Block uses of attachment 13, in order.
	 */
	private function mock_repeated_use_post( array $uses ): WP_Post {
		$blocks = array();
		foreach ( $uses as $use ) {
			$blocks[] = array(
				'blockName'   => 'core/image',
				'attrs'       => array( 'id' => 13 ) + $use['attrs'],
				'innerHTML'   => '<figure class="wp-block-image"><img src="x" alt="' . $use['alt'] . '" class="wp-image-13"/></figure>',
				'innerBlocks' => array(),
			);
		}
		WP_Mock::userFunction( 'parse_blocks' )->andReturn( $blocks );
		WP_Mock::userFunction( 'get_post_meta' )->with( 41, Outpost_Library_Photos_Endpoint::DECORATIVE_META, false )->andReturn( array() );
		WP_Mock::userFunction( 'wp_get_attachment_image_src' )->andReturn( array( 'https://example.test/uploads/decorative-1024x768.jpg', 1024, 768, true ) );
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn( $v ) => (string) $v );
		return new WP_Post(
			array(
				'ID'           => 41,
				'post_content' => '<!-- wp:image {"id":13} -->...',
			)
		);
	}

	/** @return array<int, array<string, string>> */
	private function repeated_use_attachment( string $name ): array {
		return array(
			array(
				'type'      => 'Image',
				'url'       => 'https://example.test/uploads/decorative-1024x768.jpg',
				'mediaType' => 'image/jpeg',
				'name'      => $name,
			),
		);
	}

	public function test_activitypub_keeps_a_later_described_use_of_an_image_first_used_decoratively(): void {
		$post = $this->mock_repeated_use_post(
			array(
				array(
					'attrs' => array( 'isDecorative' => true ),
					'alt'   => '',
				),
				array(
					'attrs' => array(),
					'alt'   => 'Described in the second block',
				),
			)
		);

		$out = Outpost_Library_Photos_Endpoint::keep_decorative_alt_empty( $this->repeated_use_attachment( 'Described in the second block' ), $post );

		$this->assertSame( 'Described in the second block', $out[0]['name'] );
	}

	public function test_activitypub_clears_an_image_whose_later_use_is_decorative(): void {
		$post = $this->mock_repeated_use_post(
			array(
				array(
					'attrs' => array(),
					'alt'   => 'Described in the first block',
				),
				array(
					'attrs' => array( 'isDecorative' => true ),
					'alt'   => '',
				),
			)
		);

		$out = Outpost_Library_Photos_Endpoint::keep_decorative_alt_empty( $this->repeated_use_attachment( 'Shared alt from another post' ), $post );

		$this->assertSame( '', $out[0]['name'] );
	}
}
