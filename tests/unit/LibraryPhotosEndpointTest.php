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

	public function test_decorative_block_round_trips_into_core_image_on_72(): void {
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

	public function test_decorative_block_is_plain_empty_alt_before_72(): void {
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

	private function allow_image( bool $can_edit = true, bool $can_read_parent = true, string $pending = '' ): void {
		WP_Mock::userFunction( 'get_post' )->with( 5 )->andReturn( $this->image( array( 'post_excerpt' => 'Original fixture caption' ) ) );
		WP_Mock::userFunction( 'wp_attachment_is_image', array( 'return' => true ) );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_post', 5 )->andReturn( $can_edit );
		WP_Mock::userFunction( 'current_user_can' )->with( 'read_post', 9 )->andReturn( $can_read_parent );
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

	/**
	 * @return array<string, array{0: bool, 1: bool, 2: string}>
	 */
	public static function unusable_images(): array {
		return array(
			'another user\'s image'            => array( false, true, '' ),
			'image on a post the user can\'t read' => array( true, false, '' ),
			'client upload awaiting finalize'  => array( true, true, '1' ),
		);
	}

	/**
	 * @dataProvider unusable_images
	 */
	public function test_unusable_image_is_refused_without_side_effects( bool $can_edit, bool $can_read_parent, string $pending ): void {
		$this->allow_image( $can_edit, $can_read_parent, $pending );
		$this->forbid_attachment_writes();
		WP_Mock::userFunction( 'wp_insert_post', array( 'times' => 0 ) );

		$response = Outpost_Library_Photos_Endpoint::handle( $this->draft_request( array( 'attachmentId' => 5 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 403, $response->get_error_data()['status'] );
	}

	// --- finalize tracking ------------------------------------------------------

	public function test_client_processed_upload_is_pending_until_finalize(): void {
		$upload = new WP_REST_Request( 'POST', '/wp/v2/media' );
		$upload->set_param( 'generate_sub_sizes', false );
		WP_Mock::userFunction( 'wp_attachment_is_image', array( 'return' => true ) );
		WP_Mock::userFunction( 'wp_is_client_side_media_processing_enabled', array( 'return' => true ) );
		WP_Mock::userFunction( 'update_post_meta' )->once()->with( 5, Outpost_Library_Photos_Endpoint::PENDING_META, '1' );
		Outpost_Library_Photos_Endpoint::track_client_upload( $this->image(), $upload, true );

		WP_Mock::userFunction( 'delete_post_meta' )->once()->with( 5, Outpost_Library_Photos_Endpoint::PENDING_META );
		$finalize = new WP_REST_Request( 'POST', '/wp/v2/media/5/finalize' );
		$ok       = new WP_REST_Response( array(), 200 );
		$this->assertSame( $ok, Outpost_Library_Photos_Endpoint::complete_client_upload( $ok, array(), $finalize ) );
	}

	public function test_failed_finalize_keeps_the_image_pending(): void {
		WP_Mock::userFunction( 'delete_post_meta', array( 'times' => 0 ) );
		$finalize = new WP_REST_Request( 'POST', '/wp/v2/media/5/finalize' );
		Outpost_Library_Photos_Endpoint::complete_client_upload( new \WP_Error( 'x', 'y' ), array(), $finalize );
		Outpost_Library_Photos_Endpoint::complete_client_upload( new WP_REST_Response( array(), 400 ), array(), $finalize );
		$this->assertTrue( true );
	}

	public function test_server_processed_upload_is_never_marked_pending(): void {
		$upload = new WP_REST_Request( 'POST', '/wp/v2/media' );
		WP_Mock::userFunction( 'update_post_meta', array( 'times' => 0 ) );
		Outpost_Library_Photos_Endpoint::track_client_upload( $this->image(), $upload, true );
		$this->assertTrue( true );
	}
}
