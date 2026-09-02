<?php
/**
 * Integration test: Micropub photo-bridge alt-text fidelity + attachment
 * ownership, against real WordPress (real users, attachments, capabilities).
 *
 * Two audited defects (pre-1.0.4 remediation, 2026-09-01):
 *
 *   Item 3 — alt-text wipe. `Media_Controller::default_file_handler()` appends
 *   each photo's canonical URL back onto the `photo` property after sideloading,
 *   so the bridge sees the URL twice. Pairing alts by index let the bare
 *   duplicate's empty alt overwrite the real one, so `_wp_attachment_image_alt`
 *   ended empty on every real photo post. These fixtures feed the bridge the
 *   exact DOUBLED shape the dependency produces (`photo => [url, url]`,
 *   `mp-photo-alt => [alt]`), verified against the live Micropub endpoint.
 *
 *   Item 4 — cross-user attachment mutation. `Media_Controller::media_sideload_url()`
 *   re-parents any locally resolvable photo URL to the new post with NO
 *   capability check, before `after_micropub` fires, so parentage alone reads as
 *   this post even for another user's media. The fixtures reproduce that
 *   post-transplant state (a foreign-authored attachment parented to the actor's
 *   post) and assert Outpost refuses to overwrite its alt or set it as the
 *   thumbnail. Re-parenting never changes the attachment's author, so `edit_post`
 *   is the ownership signal.
 *
 * A hand-built `after_micropub` array cannot exercise the Micropub plugin's own
 * URL-doubling and re-parenting — that end-to-end path is proven separately over
 * the live `/micropub/1.0/endpoint` (see the remediation report's receipts).
 * These fixtures use real WordPress capability + attachment behavior to guard
 * the fix in CI, where the companion plugins are not loaded.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class MicropubPhotoOwnershipTest extends TestCase {

	private int $author_id = 0;
	private int $editor_id = 0;

	/** @var int[] */
	private array $cleanup = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_insert_user' ) || ! class_exists( 'Outpost_Micropub_Bridges' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap. Run via `npm run test:integration`.' );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$this->author_id = (int) wp_insert_user(
			array(
				'user_login' => 'own_author_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'own_author_' . uniqid() . '@example.test',
				'role'       => 'author',
			)
		);
		$this->editor_id = (int) wp_insert_user(
			array(
				'user_login' => 'own_editor_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'own_editor_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
	}

	protected function tearDown(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->cleanup as $id ) {
			wp_delete_post( $id, true );
		}
		$this->cleanup = array();
		foreach ( array( $this->author_id, $this->editor_id ) as $uid ) {
			if ( $uid ) {
				wp_delete_user( $uid );
			}
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function make_post( int $author ): int {
		$id              = (int) wp_insert_post(
			array(
				'post_title'  => 'own-post-' . uniqid(),
				'post_status' => 'publish',
				'post_author' => $author,
				'post_type'   => 'post',
			)
		);
		$this->cleanup[] = $id;
		return $id;
	}

	/** An image attachment authored by $author and parented to $parent. */
	private function make_image( int $author, int $parent, string $alt = '' ): int {
		$prev = get_current_user_id();
		wp_set_current_user( $author );
		$dir = wp_get_upload_dir();
		wp_mkdir_p( $dir['path'] );
		$fn = 'own-' . uniqid() . '.jpg';
		file_put_contents(
			$dir['path'] . '/' . $fn,
			base64_decode( '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAr/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AL+AAAAAAA//9k=' )
		);
		$att = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'att-' . $fn,
				'post_status'    => 'inherit',
				'post_author'    => $author,
			),
			$dir['path'] . '/' . $fn,
			$parent
		);
		if ( '' !== $alt ) {
			update_post_meta( $att, '_wp_attachment_image_alt', $alt );
		}
		wp_set_current_user( $prev );
		return $att;
	}

	/** The doubled property shape the Micropub plugin produces after sideload. */
	private function doubled_input( string $url, string $alt ): array {
		return array(
			'type'       => array( 'h-entry' ),
			'properties' => array(
				'content'      => array( 'photo post' ),
				'photo'        => array( $url, $url ),
				'mp-photo-alt' => array( $alt ),
			),
		);
	}

	private function fire( int $actor, int $post_id, array $input ): void {
		wp_set_current_user( $actor );
		do_action( 'after_micropub', $input, array( 'ID' => $post_id ) );
	}

	private function alt_of( int $att ): string {
		wp_cache_delete( $att, 'post_meta' );
		return (string) get_post_meta( $att, '_wp_attachment_image_alt', true );
	}

	private function thumb_of( int $post_id ): int {
		clean_post_cache( $post_id );
		return (int) get_post_thumbnail_id( $post_id );
	}

	/**
	 * @test
	 * Item 3: the doubled photo URL no longer wipes the client's alt text, and
	 * the first photo still becomes the thumbnail.
	 */
	public function doubled_photo_url_preserves_alt_and_sets_thumbnail(): void {
		$post = $this->make_post( $this->author_id );
		$att  = $this->make_image( $this->author_id, $post ); // dependency has re-parented it.
		$url  = (string) wp_get_attachment_url( $att );

		$this->fire( $this->author_id, $post, $this->doubled_input( $url, 'A heron at dawn' ) );

		$this->assertSame( 'A heron at dawn', $this->alt_of( $att ), 'The real alt must survive the duplicate URL.' );
		$this->assertSame( $att, $this->thumb_of( $post ), 'First photo becomes the thumbnail.' );
	}

	/**
	 * @test
	 * Item 4: a foreign-authored attachment, transplanted onto the actor's post
	 * by the dependency, is neither alt-overwritten nor made the thumbnail.
	 */
	public function foreign_authored_attachment_is_not_claimed(): void {
		$author_post = $this->make_post( $this->author_id );
		// Editor's image, now parented to the AUTHOR's post (the transplant).
		$editor_att = $this->make_image( $this->editor_id, $author_post, 'editor original alt' );
		$url        = (string) wp_get_attachment_url( $editor_att );

		$this->assertFalse(
			user_can( $this->author_id, 'edit_post', $editor_att ),
			'Precondition: the Author cannot edit the Editor-authored attachment.'
		);

		$this->fire( $this->author_id, $author_post, $this->doubled_input( $url, 'hostile overwrite' ) );

		$this->assertSame( 'editor original alt', $this->alt_of( $editor_att ), 'Editor alt must be untouched.' );
		$this->assertSame( 0, $this->thumb_of( $author_post ), 'Foreign media must not become the thumbnail.' );
	}

	/**
	 * @test
	 * Item 4 positive control: the actor's OWN media is written and set — the
	 * guard does not over-block.
	 */
	public function own_attachment_is_written_and_set(): void {
		$post = $this->make_post( $this->author_id );
		$att  = $this->make_image( $this->author_id, $post );
		$url  = (string) wp_get_attachment_url( $att );

		$this->fire( $this->author_id, $post, $this->doubled_input( $url, 'my own photo' ) );

		$this->assertSame( 'my own photo', $this->alt_of( $att ) );
		$this->assertSame( $att, $this->thumb_of( $post ) );
	}

	/**
	 * @test
	 * Item 4: an admin actor cannot be tricked either — parentage to a DIFFERENT
	 * post fails the ownership check regardless of capability.
	 */
	public function attachment_parented_to_another_post_is_skipped(): void {
		$post       = $this->make_post( $this->author_id );
		$other_post = $this->make_post( $this->author_id );
		$att        = $this->make_image( $this->author_id, $other_post, 'belongs elsewhere' );
		$url        = (string) wp_get_attachment_url( $att );

		$this->fire( $this->author_id, $post, $this->doubled_input( $url, 'should not write' ) );

		$this->assertSame( 'belongs elsewhere', $this->alt_of( $att ), 'Alt on media owned by another post is untouched.' );
		$this->assertSame( 0, $this->thumb_of( $post ) );
	}

	/**
	 * @test
	 * Item 4: the opt-out filter still suppresses the thumbnail; alt text (a
	 * separate accessibility-critical write) is unaffected.
	 */
	public function opt_out_filter_suppresses_thumbnail_keeps_alt(): void {
		$post = $this->make_post( $this->author_id );
		$att  = $this->make_image( $this->author_id, $post );
		$url  = (string) wp_get_attachment_url( $att );

		add_filter( 'outpost_set_featured_image', '__return_false' );
		$this->fire( $this->author_id, $post, $this->doubled_input( $url, 'kept alt' ) );
		remove_filter( 'outpost_set_featured_image', '__return_false' );

		$this->assertSame( 'kept alt', $this->alt_of( $att ), 'Alt still written.' );
		$this->assertSame( 0, $this->thumb_of( $post ), 'Filter suppresses the thumbnail.' );
	}
}
