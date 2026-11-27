<?php
/**
 * Integration test: handing a saved Outpost draft to the native editor for review.
 *
 * A Micropub draft save returns `X-Outpost-Draft-Edit`, the same post's
 * native editor URL. An editor then reviews that draft ID in WordPress: adds
 * a block note (6.9 Notes) and later restores a revision (Visual Revisions).
 * Between the two, the author makes an unrelated title edit from Outpost's
 * recent drafts picker. The note stays attached, the draft keeps its ID and
 * status, and no syndication job is scheduled or fired at any point.
 *
 * Everything a test creates is deleted in tearDown().
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @coversNothing
 */
final class DraftReviewHandoffTest extends TestCase {

	use IndieAuthTokenFixture;

	private int $author_id = 0;

	private int $editor_id = 0;

	private int $other_id = 0;

	/** @var int[] */
	private array $posts = array();

	/** @var array<int, int> Hook name => did_action() count before the test. */
	private array $syndication_actions = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_insert_user' ) || ! defined( 'MICROPUB_NAMESPACE' ) ) {
			$this->markTestSkipped( 'Needs the integration bootstrap with the Micropub plugin loaded.' );
		}
		$this->reset_indieauth_fixture();
		wp_set_current_user( 0 );
		$this->author_id = $this->make_user( 'handoff_a_', 'author' );
		$this->editor_id = $this->make_user( 'handoff_e_', 'editor' );
		$this->other_id  = $this->make_user( 'handoff_o_', 'author' );
		foreach ( array( 'micropub_syndication', \Outpost_POSSE_Dispatcher::CRON_HOOK, 'outpost_posse_before_dispatch' ) as $hook ) {
			$this->syndication_actions[ $hook ] = did_action( $hook );
		}
	}

	protected function tearDown(): void {
		$this->reset_indieauth_fixture();
		wp_set_current_user( 0 );
		foreach ( $this->posts as $post_id ) {
			wp_clear_scheduled_hook( \Outpost_POSSE_Dispatcher::CRON_HOOK, array( $post_id ) );
			foreach ( get_comments( array( 'post_id' => $post_id, 'type' => 'all', 'status' => 'all', 'fields' => 'ids' ) ) as $comment_id ) {
				wp_delete_comment( (int) $comment_id, true );
			}
			wp_delete_post( $post_id, true );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( array( $this->author_id, $this->editor_id, $this->other_id ) as $user_id ) {
			if ( $user_id > 0 ) {
				wp_delete_user( $user_id );
			}
		}
		parent::tearDown();
	}

	private function make_user( string $prefix, string $role ): int {
		$login = $prefix . wp_generate_password( 8, false );
		return (int) wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => $login . '@example.test',
				'role'       => $role,
			)
		);
	}

	/**
	 * @param array<string, string> $fields Form fields.
	 */
	private function dispatch_as( int $user_id, string $route, array $fields ): WP_REST_Response {
		$this->reset_indieauth_request_state();
		$token                         = $this->issue_indieauth_token( $user_id, array( 'create', 'update' ) );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$this->resolve_current_user_like_core();
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( $fields );
		$response = rest_do_request( $request );
		$this->reset_indieauth_request_state();
		wp_set_current_user( 0 );
		return $response;
	}

	private function save_outpost_draft( string $content, bool $draft = true ): WP_REST_Response {
		$fields = array(
			'h'       => 'entry',
			'content' => $content,
		);
		if ( $draft ) {
			$fields['post-status'] = 'draft';
		}
		$response = $this->dispatch_as( $this->author_id, '/' . MICROPUB_NAMESPACE . '/endpoint', $fields );
		$data     = $response->get_data();
		if ( isset( $data['ID'] ) ) {
			$this->posts[] = (int) $data['ID'];
		}
		return $response;
	}

	private function assert_no_syndication_job( int $post_id ): void {
		$crons = _get_cron_array();
		foreach ( is_array( $crons ) ? $crons : array() as $events ) {
			$this->assertArrayNotHasKey( \Outpost_POSSE_Dispatcher::CRON_HOOK, $events, 'No POSSE dispatch is scheduled.' );
		}
		foreach ( $this->syndication_actions as $hook => $count ) {
			$this->assertSame( $count, did_action( $hook ), $hook . ' did not fire.' );
		}
		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	/**
	 * @test
	 */
	public function a_saved_draft_links_to_the_same_post_in_the_native_editor(): void {
		$response = $this->save_outpost_draft( 'Ready for review' );

		$this->assertSame( 201, $response->get_status() );
		$post_id = (int) $response->get_data()['ID'];
		$post    = get_post( $post_id );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( $this->author_id, (int) $post->post_author );

		$headers = $response->get_headers();
		$this->assertSame( admin_url( 'post.php?post=' . $post_id . '&action=edit' ), $headers['X-Outpost-Draft-Edit'] );
		$this->assertSame( 'private, no-store', $headers['Cache-Control'] );
		$this->assert_no_syndication_job( $post_id );
	}

	/**
	 * @test
	 */
	public function a_published_note_gets_no_editor_link(): void {
		$response = $this->save_outpost_draft( 'Published straight away', false );

		$this->assertSame( 201, $response->get_status() );
		$this->assertArrayNotHasKey( 'X-Outpost-Draft-Edit', $response->get_headers() );
	}

	/**
	 * @test
	 */
	public function an_editor_note_and_restore_survive_an_unrelated_composer_edit(): void {
		$post_id = (int) $this->save_outpost_draft( 'Draft for the editor' )->get_data()['ID'];
		\Outpost_POSSE_Meta::set_targets( $post_id, array( 'mastodon' ) );

		// Only users the native editor lets in can annotate the draft.
		$this->assertTrue( user_can( $this->editor_id, 'edit_post', $post_id ) );
		$this->assertFalse( user_can( $this->other_id, 'edit_post', $post_id ) );

		// The editor opens the draft in the block editor, which saves it as
		// blocks, and adds a note anchored to the paragraph.
		wp_set_current_user( $this->editor_id );
		$note_id = wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'note',
				'comment_content'  => 'Tighten the opening line.',
				'comment_approved' => '1',
				'user_id'          => $this->editor_id,
			)
		);
		$this->assertIsInt( $note_id );
		$annotated = '<!-- wp:paragraph {"metadata":{"noteId":' . $note_id . '}} --><p>Draft for the editor</p><!-- /wp:paragraph -->';
		$this->assertSame( $post_id, wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $annotated ) ), true ) );
		$annotated_revision = (int) array_key_first( wp_get_post_revisions( $post_id ) );
		wp_set_current_user( 0 );

		// The author reopens the same draft in Outpost and changes only the title.
		$opened = $this->dispatch_as( $this->author_id, '/outpost/v1/recent-drafts', array( 'id' => (string) $post_id ) );
		$this->assertSame( 200, $opened->get_status() );
		$this->assertNull( $opened->get_data()['content'], 'Block content stays in the native editor.' );
		$edited = $this->dispatch_as(
			$this->author_id,
			'/outpost/v1/recent-drafts',
			array(
				'id'      => (string) $post_id,
				'title'   => 'Retitled from Outpost',
				'version' => $opened->get_data()['version'],
			)
		);
		$this->assertSame( 200, $edited->get_status() );

		$post = get_post( $post_id );
		$this->assertSame( 'Retitled from Outpost', $post->post_title );
		$this->assertSame( $annotated, $post->post_content, 'The note anchor in the block survives.' );
		$note = get_comment( $note_id );
		$this->assertSame( (string) $post_id, $note->comment_post_ID, 'The note stays on the same draft.' );
		$this->assertSame( '1', $note->comment_approved );
		$this->assert_no_syndication_job( $post_id );

		// The editor restores the annotated revision on the same draft ID.
		wp_set_current_user( $this->editor_id );
		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );
		$restored = wp_restore_post_revision( $annotated_revision );
		wp_set_current_user( 0 );

		$this->assertSame( $post_id, $restored );
		$post = get_post( $post_id );
		$this->assertSame( $annotated, $post->post_content );
		$this->assertSame( (string) $post_id, get_comment( $note_id )->comment_post_ID );
		$this->assert_no_syndication_job( $post_id );
	}
}
