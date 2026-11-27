<?php
/**
 * Integration test: the recent drafts picker (`/outpost/v1/recent-drafts`).
 *
 * Two authors and a pending post are seeded per test. The picker lists only
 * the token user's own drafts, pages them without revealing anyone else's
 * titles or counts, and refuses another author's draft ID exactly where
 * WordPress's own edit check refuses it. On Core 7.2 the list comes from the
 * core/content-query ability; earlier Core uses WP_Query, and both paths must
 * return the same pages.
 *
 * Everything a test creates is deleted in tearDown(), so the suite leaves a
 * shared CI database as it found it.
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
final class RecentDraftsEndpointTest extends TestCase {

	use IndieAuthTokenFixture;

	private int $author_id = 0;

	private int $other_id = 0;

	/** @var int[] */
	private array $own_drafts = array();

	private int $pending_id = 0;

	/** @var int[] */
	private array $other_drafts = array();

	/** @var int[] */
	private array $extra_posts = array();

	/** @var int[] */
	private array $extra_users = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_insert_user' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap. Run via `npm run test:integration` inside wp-env tests-cli.' );
		}

		$this->reset_indieauth_fixture();
		wp_set_current_user( 0 );
		$this->author_id = $this->make_author( 'drafts_a_' );
		$this->other_id  = $this->make_author( 'drafts_b_' );

		// Twelve own drafts: a full first page of ten and a second page of two.
		// Dates descend from the newest, so page order is deterministic.
		for ( $i = 1; $i <= 12; ++$i ) {
			$this->own_drafts[] = $this->make_post( 'Own draft ' . $i, 'draft', $this->author_id, $i );
		}
		$this->pending_id = $this->make_post( 'Own pending item', 'pending', $this->author_id, 0 );
		for ( $i = 1; $i <= 3; ++$i ) {
			$this->other_drafts[] = $this->make_post( 'Other author draft ' . $i, 'draft', $this->other_id, $i );
		}
	}

	protected function tearDown(): void {
		$this->reset_indieauth_fixture();
		wp_set_current_user( 0 );
		foreach ( array_merge( $this->own_drafts, array( $this->pending_id ), $this->other_drafts, $this->extra_posts ) as $post_id ) {
			if ( $post_id > 0 ) {
				wp_delete_post( $post_id, true );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( array_merge( array( $this->author_id, $this->other_id ), $this->extra_users ) as $user_id ) {
			if ( $user_id > 0 ) {
				wp_delete_user( $user_id );
			}
		}
		parent::tearDown();
	}

	private function make_author( string $prefix, string $role = 'author' ): int {
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
	 * Store fields exactly as given, as if another user had saved them,
	 * without running the current user's save filters.
	 *
	 * @param array<string, string> $fields Post fields.
	 */
	private function store_raw( int $post_id, array $fields ): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, $fields, array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
	}

	private function make_post( string $title, string $status, int $author, int $age_minutes, string $type = 'post' ): int {
		$post_id = wp_insert_post(
			array(
				'post_title'    => $title,
				'post_status'   => $status,
				'post_author'   => $author,
				'post_type'     => $type,
				'post_content'  => 'Plain draft text.',
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() - 60 * $age_minutes ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 60 * $age_minutes ),
			),
			true
		);
		$this->assertIsInt( $post_id );
		return $post_id;
	}

	/**
	 * Dispatch the route as the PWA does: a form POST with the token in the
	 * Authorization header, the user resolved the way core resolves it.
	 *
	 * @param array<string, string> $fields Form fields.
	 * @param string[]              $scopes Token scopes.
	 */
	private function call( int $user_id, array $fields, array $scopes = array( 'create', 'update' ) ): WP_REST_Response {
		$this->reset_indieauth_request_state();
		$token                         = $this->issue_indieauth_token( $user_id, $scopes );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$this->resolve_current_user_like_core();
		$request = new WP_REST_Request( 'POST', '/outpost/v1/recent-drafts' );
		$request->set_body_params( $fields );
		$response = rest_get_server()->dispatch( $request );
		$this->reset_indieauth_request_state();
		wp_set_current_user( 0 );
		return $response;
	}

	/**
	 * The native editor's check for opening a post, evaluated with no token
	 * in play: the capability WordPress checks on post.php?action=edit.
	 */
	private function native_editor_allows( int $user_id, int $post_id ): bool {
		$this->reset_indieauth_request_state();
		return user_can( $user_id, 'edit_post', $post_id );
	}

	/** @return int[] */
	private function ids( WP_REST_Response $response ): array {
		$data = $response->get_data();
		return array_map( static fn( array $item ): int => $item['id'], $data['items'] );
	}

	/**
	 * The WordPress test library unhooks Core's ability registration, so a
	 * test that needs core/content-query registers it the way Core does,
	 * inside its init actions. Returns whether this test registered it.
	 */
	private function register_core_content_query(): bool {
		if ( ! class_exists( 'WP_Abilities_Content' ) ) {
			$this->markTestSkipped( 'core/content-query ships with WordPress 7.2.' );
		}
		if ( wp_has_ability( 'core/content-query' ) ) {
			return false;
		}
		global $wp_current_filter;
		if ( ! wp_has_ability_category( 'content' ) ) {
			$wp_current_filter[] = 'wp_abilities_api_categories_init';
			wp_register_ability_category( 'content', array( 'label' => 'Content', 'description' => 'Posts and other content.' ) );
			array_pop( $wp_current_filter );
		}
		$wp_current_filter[] = 'wp_abilities_api_init';
		( new \WP_Abilities_Content() )->register();
		array_pop( $wp_current_filter );
		return true;
	}

	/** @return array<string, array{0: bool}> */
	public function query_paths(): array {
		return array(
			'core/content-query' => array( true ),
			'WP_Query'           => array( false ),
		);
	}

	/**
	 * @test
	 * @dataProvider query_paths
	 */
	public function pages_list_only_the_authors_own_drafts_without_counts( bool $use_ability ): void {
		$registered = $use_ability && $this->register_core_content_query();
		if ( ! $use_ability && function_exists( 'wp_has_ability' ) && wp_has_ability( 'core/content-query' ) ) {
			$this->markTestSkipped( 'core/content-query is registered, so the WP_Query path cannot run here.' );
		}
		try {
			$this->assert_author_only_pages( $use_ability );
		} finally {
			if ( $registered ) {
				wp_unregister_ability( 'core/content-query' );
				wp_unregister_ability_category( 'content' );
			}
		}
	}

	private function assert_author_only_pages( bool $use_ability ): void {
		$executed = array();
		$watch    = static function ( string $name ) use ( &$executed ): void {
			$executed[] = $name;
		};
		add_action( 'wp_before_execute_ability', $watch );

		$first  = $this->call( $this->author_id, array( 'page' => '1' ) );
		$second = $this->call( $this->author_id, array( 'page' => '2' ) );
		$beyond = $this->call( $this->author_id, array( 'page' => '3' ) );
		remove_action( 'wp_before_execute_ability', $watch );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( array( 'items', 'hasNext' ), array_keys( $first->get_data() ), 'A page carries no totals.' );
		$this->assertSame( array_slice( $this->own_drafts, 0, 10 ), $this->ids( $first ), 'Newest ten own drafts, newest first.' );
		$this->assertTrue( $first->get_data()['hasNext'] );
		$this->assertSame( array_slice( $this->own_drafts, 10 ), $this->ids( $second ) );
		$this->assertFalse( $second->get_data()['hasNext'] );
		$this->assertSame( 200, $beyond->get_status(), 'A page past the end is empty on both query paths, not an error.' );
		$this->assertSame( array( 'items' => array(), 'hasNext' => false ), $beyond->get_data() );
		$this->assertSame( 'private, no-store', $first->get_headers()['Cache-Control'] );

		foreach ( $first->get_data()['items'] as $item ) {
			$this->assertSame( array( 'id', 'title' ), array_keys( $item ) );
		}

		$this->assertSame(
			$use_ability ? array( 'core/content-query', 'core/content-query', 'core/content-query' ) : array(),
			$executed,
			'With core/content-query registered every page comes from it; without it, WP_Query serves them.'
		);

		$theirs = $this->call( $this->other_id, array( 'page' => '1' ) );
		$this->assertSame( $this->other_drafts, $this->ids( $theirs ) );
		$this->assertFalse( $theirs->get_data()['hasNext'], 'The first author\'s twelve drafts never add a page to the second author\'s list.' );
	}

	/**
	 * @test
	 */
	public function an_own_draft_reopens_with_its_editor_link(): void {
		$draft_id = $this->own_drafts[0];
		$this->assertTrue( $this->native_editor_allows( $this->author_id, $draft_id ) );

		$response = $this->call( $this->author_id, array( 'id' => (string) $draft_id ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $draft_id, $data['id'] );
		$this->assertSame( 'Own draft 1', $data['title'] );
		$this->assertSame( 'Plain draft text.', $data['content'] );
		$this->assertStringContainsString( 'post.php?post=' . $draft_id . '&action=edit', (string) $data['editUrl'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $data['version'] );
	}

	/**
	 * @test
	 */
	public function another_authors_draft_fails_where_the_native_editor_fails(): void {
		$foreign = $this->other_drafts[0];
		$this->assertFalse( $this->native_editor_allows( $this->author_id, $foreign ), 'Precondition: Core refuses the edit.' );

		$open = $this->call( $this->author_id, array( 'id' => (string) $foreign ) );
		$save = $this->call(
			$this->author_id,
			array(
				'id'      => (string) $foreign,
				'title'   => 'Overwritten',
				'version' => 'anything',
			)
		);

		foreach ( array( $open, $save ) as $response ) {
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'outpost_draft_unavailable', $response->get_data()['code'] );
			$this->assertArrayNotHasKey( 'title', $response->get_data()['data'] ?? array(), 'The refusal carries no draft data.' );
		}
		$this->assertSame( 'Other author draft 1', get_post( $foreign )->post_title, 'A refused save changes nothing.' );
	}

	/**
	 * @test
	 */
	public function refusals_do_not_reveal_whether_a_post_exists(): void {
		$page_id             = $this->make_post( 'Own page draft', 'draft', $this->author_id, 1, 'page' );
		$this->extra_posts[] = $page_id;
		$missing             = max( array_merge( $this->own_drafts, $this->other_drafts, array( $page_id ) ) ) + 1000;

		$bodies = array();
		foreach ( array( $this->other_drafts[0], $this->pending_id, $page_id, $missing ) as $id ) {
			$response = $this->call( $this->author_id, array( 'id' => (string) $id ) );
			$this->assertSame( 403, $response->get_status(), 'ID ' . $id );
			$bodies[] = $response->get_data();
		}
		$this->assertCount( 1, array_unique( array_map( 'wp_json_encode', $bodies ) ), 'Foreign, pending, page and missing IDs get one identical refusal.' );
	}

	/**
	 * @test
	 */
	public function a_save_needs_the_current_version(): void {
		$draft_id = $this->own_drafts[1];
		$opened   = $this->call( $this->author_id, array( 'id' => (string) $draft_id ) )->get_data();

		$stale = $this->call(
			$this->author_id,
			array(
				'id'      => (string) $draft_id,
				'title'   => 'Stale save',
				'version' => str_repeat( '0', 64 ),
			)
		);
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'Own draft 2', get_post( $draft_id )->post_title );

		$saved = $this->call(
			$this->author_id,
			array(
				'id'      => (string) $draft_id,
				'title'   => 'Saved <b>from</b> Outpost',
				'content' => 'Edited text & more.',
				'version' => $opened['version'],
			)
		);
		$this->assertSame( 200, $saved->get_status() );
		$post = get_post( $draft_id );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'Saved from Outpost', $post->post_title, 'Titles are sanitized as text.' );
		$this->assertSame( 'Edited text & more.', $saved->get_data()['content'] );
		$this->assertNotSame( $opened['version'], $saved->get_data()['version'] );
	}

	/**
	 * @test
	 */
	public function an_authors_title_edit_keeps_an_editors_embed(): void {
		$editor_id           = $this->make_author( 'drafts_e_', 'editor' );
		$this->extra_users[] = $editor_id;
		$draft_id            = $this->own_drafts[2];
		$embed               = '<!-- wp:html --><iframe src="https://player.example/embed/1" title="Clip"></iframe><!-- /wp:html -->';

		wp_set_current_user( $editor_id );
		$this->assertTrue( current_user_can( 'unfiltered_html' ), 'Precondition: the editor may save an iframe.' );
		wp_update_post( wp_slash( array( 'ID' => $draft_id, 'post_content' => $embed ) ) );
		wp_set_current_user( 0 );
		$this->assertSame( $embed, get_post( $draft_id )->post_content );
		$this->assertFalse( user_can( $this->author_id, 'unfiltered_html' ), 'Precondition: the author may not.' );

		$opened = $this->call( $this->author_id, array( 'id' => (string) $draft_id ) )->get_data();
		$this->assertNull( $opened['content'], 'Formatted content stays in the native editor.' );

		$saved = $this->call(
			$this->author_id,
			array(
				'id'      => (string) $draft_id,
				'title'   => 'Retitled by the author',
				'version' => $opened['version'],
			)
		);

		$this->assertSame( 200, $saved->get_status() );
		$post = get_post( $draft_id );
		$this->assertSame( 'Retitled by the author', $post->post_title );
		$this->assertSame( $embed, $post->post_content, 'A title edit leaves the editor\'s embed as it was.' );
	}

	/**
	 * @test
	 */
	public function plain_text_edits_keep_literal_text_and_untouched_fields(): void {
		$draft_id = $this->own_drafts[3];
		$text     = 'Read https://example.com/a%20b?q=%2F now';
		$this->store_raw(
			$draft_id,
			array(
				'post_title'   => 'Trip notes',
				'post_content' => $text,
			)
		);
		$opened = $this->call( $this->author_id, array( 'id' => (string) $draft_id ) )->get_data();
		$this->assertSame( $text, $opened['content'] );

		// A title edit: the picker sends the unchanged content back.
		$titled = $this->call(
			$this->author_id,
			array(
				'id'      => (string) $draft_id,
				'title'   => 'Trip %20 plan',
				'content' => $opened['content'],
				'version' => $opened['version'],
			)
		);
		$this->assertSame( 200, $titled->get_status() );
		$this->assertSame( 'Trip %20 plan', get_post( $draft_id )->post_title, 'Percent sequences in a title are text.' );
		$this->assertSame( $text, get_post( $draft_id )->post_content, 'Unchanged content is not rewritten.' );

		// A content edit: the picker sends the unchanged title back. An editor
		// gave the title markup the author's own save filters would strip.
		$this->store_raw( $draft_id, array( 'post_title' => 'Trip <em>%20</em> plan' ) );
		$reopened = $this->call( $this->author_id, array( 'id' => (string) $draft_id ) )->get_data();
		$edited   = $text . ' and https://example.com/c%2Fd & <more>';
		$saved    = $this->call(
			$this->author_id,
			array(
				'id'      => (string) $draft_id,
				'title'   => $reopened['title'],
				'content' => $edited,
				'version' => $reopened['version'],
			)
		);
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( $edited, $saved->get_data()['content'], 'Edited plain text reopens exactly as typed.' );
		$this->assertSame( 'Trip <em>%20</em> plan', get_post( $draft_id )->post_title, 'An unchanged title is not rewritten.' );
	}

	/**
	 * @test
	 * @dataProvider query_paths
	 */
	public function drafts_saved_in_the_same_second_page_by_id( bool $use_ability ): void {
		$registered = $use_ability && $this->register_core_content_query();
		if ( ! $use_ability && function_exists( 'wp_has_ability' ) && wp_has_ability( 'core/content-query' ) ) {
			$this->markTestSkipped( 'core/content-query is registered, so the WP_Query path cannot run here.' );
		}
		$tied_author         = $this->make_author( 'drafts_t_' );
		$this->extra_users[] = $tied_author;
		$date                = gmdate( 'Y-m-d H:i:s', time() - 3600 );
		$tied                = array();
		for ( $i = 1; $i <= 11; ++$i ) {
			$post_id = wp_insert_post(
				array(
					'post_title'    => 'Tied draft ' . $i,
					'post_status'   => 'draft',
					'post_author'   => $tied_author,
					'post_date'     => $date,
					'post_date_gmt' => $date,
				),
				true
			);
			$this->assertIsInt( $post_id );
			$tied[]              = $post_id;
			$this->extra_posts[] = $post_id;
		}
		try {
			$first  = $this->call( $tied_author, array( 'page' => '1' ) );
			$second = $this->call( $tied_author, array( 'page' => '2' ) );
		} finally {
			if ( $registered ) {
				wp_unregister_ability( 'core/content-query' );
				wp_unregister_ability_category( 'content' );
			}
		}

		rsort( $tied );
		$this->assertSame( array_slice( $tied, 0, 10 ), $this->ids( $first ), 'Equal dates fall back to the newest ID first.' );
		$this->assertSame( array_slice( $tied, 10 ), $this->ids( $second ), 'The page boundary neither repeats nor skips a draft.' );
	}

	/**
	 * @test
	 */
	public function a_failed_save_returns_a_generic_error(): void {
		global $wpdb;
		$draft_id = $this->own_drafts[4];
		$opened   = $this->call( $this->author_id, array( 'id' => (string) $draft_id ) )->get_data();

		$break    = static function ( string $query ) use ( $wpdb ): string {
			return 0 === strpos( $query, "UPDATE `{$wpdb->posts}`" ) ? "UPDATE `{$wpdb->posts}` SET outpost_no_such_column = 1 WHERE ID = 0" : $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $break );
		try {
			$saved = $this->call(
				$this->author_id,
				array(
					'id'      => (string) $draft_id,
					'title'   => 'Never stored',
					'version' => $opened['version'],
				)
			);
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertSame( 500, $saved->get_status() );
		$this->assertSame( 'outpost_draft_save_failed', $saved->get_data()['code'] );
		$this->assertStringNotContainsString( 'outpost_no_such_column', (string) wp_json_encode( $saved->get_data() ), 'The database error stays on the server.' );
		$this->assertSame( 'Own draft 5', get_post( $draft_id )->post_title );
	}

	/**
	 * @test
	 */
	public function a_token_without_update_scope_is_refused(): void {
		$response = $this->call( $this->author_id, array( 'page' => '1' ), array( 'create' ) );

		$this->assertSame( 403, $response->get_status() );
	}
}
