<?php
/**
 * Integration test: the Media Library chooser's credential rules and the
 * per-use alt reading behind Manual Share and ActivityPub, run against
 * WordPress core's own REST request and WP_HTML_Tag_Processor.
 *
 * - A token reaches /outpost/v1/library-photos only in the Authorization
 *   header. A form-body `access_token` that signed the user in is refused
 *   with the actionable 401; a cookie session with its REST nonce passes.
 * - An `<img>` with no `alt` attribute reads as null (fall back to the
 *   shared alt), `alt=""` as ''.
 * - With one attachment shown twice, ActivityPub 9.4.4 sends the later
 *   use's alt, so that use decides whether the description is cleared.
 *
 * Everything a test creates is deleted in tearDown().
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Image_Block_Uses;
use Outpost_Library_Photos_Endpoint;
use PHPUnit\Framework\TestCase;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @coversNothing
 */
final class LibraryPhotosAuthAndAltTest extends TestCase {

	use IndieAuthTokenFixture;

	private int $user_id = 0;

	/** @var int[] */
	private array $posts = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_insert_user' ) ) {
			$this->markTestSkipped( 'Needs the integration bootstrap.' );
		}
		$this->reset_indieauth_fixture();
		wp_set_current_user( 0 );
		$user = wp_insert_user(
			array(
				'user_login' => 'library_auth_' . wp_generate_password( 8, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'author',
			)
		);
		$this->assertIsInt( $user );
		$this->user_id = $user;
	}

	protected function tearDown(): void {
		$this->reset_indieauth_fixture();
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$GLOBALS['wp_rest_auth_cookie'] = null;
		wp_set_current_user( 0 );
		foreach ( $this->posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->user_id );
		parent::tearDown();
	}

	private function list_request( array $body = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/outpost/v1/library-photos' );
		$request->set_body_params( $body );
		return $request;
	}

	// --- Credentials ---------------------------------------------------------------

	public function test_form_body_token_that_signed_the_user_in_gets_the_actionable_401(): void {
		$token                 = $this->issue_indieauth_token( $this->user_id, array( 'create' ) );
		$_POST['access_token'] = $token;
		$this->assertSame( $this->user_id, $this->resolve_current_user_like_core(), 'IndieAuth signed in from the body' );

		$response = rest_get_server()->dispatch( $this->list_request( array( 'access_token' => $token ) ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'outpost_authorization_header_missing', $response->get_data()['code'] );
	}

	public function test_bearer_header_token_lists_images(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->issue_indieauth_token( $this->user_id, array( 'create' ) );
		$this->resolve_current_user_like_core();

		$response = rest_get_server()->dispatch( $this->list_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'items', $response->get_data() );
	}

	public function test_cookie_session_with_its_rest_nonce_lists_images(): void {
		wp_set_current_user( $this->user_id );
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_SERVER['HTTP_X_WP_NONCE']     = wp_create_nonce( 'wp_rest' );

		$response = rest_get_server()->dispatch( $this->list_request() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
	}

	// --- Per-use alt -----------------------------------------------------------------

	public function test_missing_alt_reads_as_null_and_explicit_empty_alt_as_empty(): void {
		$content = '<!-- wp:image {"id":71} --><figure class="wp-block-image"><img src="x" class="wp-image-71"/></figure><!-- /wp:image -->'
			. '<!-- wp:image {"id":72} --><figure class="wp-block-image"><img src="x" alt="" class="wp-image-72"/></figure><!-- /wp:image -->'
			. '<!-- wp:image {"id":73} --><figure class="wp-block-image"><img src="x" alt="Fish &amp; chips" class="wp-image-73"/></figure><!-- /wp:image -->';

		$this->assertSame(
			array( null, '', 'Fish & chips' ),
			array_column( Outpost_Image_Block_Uses::in_content( $content ), 'alt' )
		);
	}

	/**
	 * @return array<string, array{0: bool, 1: string}>
	 */
	public static function repeated_use_orders(): array {
		return array(
			'decorative first, described later' => array( false, 'Described later' ),
			'described first, decorative later' => array( true, '' ),
		);
	}

	/**
	 * @dataProvider repeated_use_orders
	 */
	public function test_activitypub_decides_on_the_use_it_sends( bool $decorative_last, string $expected_name ): void {
		$attachment = $this->make_attachment();
		$decorative = '<!-- wp:image {"id":' . $attachment . ',"isDecorative":true} --><figure class="wp-block-image"><img src="x" alt="" class="wp-image-' . $attachment . '" role="none"/></figure><!-- /wp:image -->';
		$described  = '<!-- wp:image {"id":' . $attachment . '} --><figure class="wp-block-image"><img src="x" alt="Described ' . ( $decorative_last ? 'first' : 'later' ) . '" class="wp-image-' . $attachment . '"/></figure><!-- /wp:image -->';
		$post_id    = wp_insert_post(
			array(
				'post_status'  => 'draft',
				'post_author'  => $this->user_id,
				'post_content' => $decorative_last ? $described . $decorative : $decorative . $described,
			),
			true
		);
		$this->assertIsInt( $post_id );
		$this->posts[] = $post_id;
		$post          = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$image = wp_get_attachment_image_src( $attachment, 'large' );
		$this->assertIsArray( $image );
		// The name ActivityPub 9.4.4 built: the later use's alt, or the shared alt when that is empty.
		$sent = array(
			array(
				'type' => 'Image',
				'url'  => $image[0],
				'name' => $decorative_last ? 'Shared alt' : 'Described later',
			),
		);

		$out = Outpost_Library_Photos_Endpoint::keep_decorative_alt_empty( $sent, $post );

		$this->assertSame( $expected_name, $out[0]['name'] );
	}

	private function make_attachment(): int {
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Repeated use fixture',
				'post_status'    => 'inherit',
			),
			'repeated-use-fixture.jpg'
		);
		$this->assertIsInt( $id );
		$this->posts[] = $id;
		update_post_meta( $id, '_wp_attachment_image_alt', 'Shared alt' );
		return $id;
	}
}
