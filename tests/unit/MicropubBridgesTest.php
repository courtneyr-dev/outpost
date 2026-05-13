<?php
/**
 * Unit tests for Outpost_Micropub_Bridges.
 *
 * Covers the three Micropub-to-companion-storage bridges plus the auto-
 * inference of WordPress Post Format from h-entry signals (the POSSE
 * integration glue between Post Kinds and Post Formats).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Micropub_Bridges;
use Outpost_Companion_Detector;
use Outpost_Companion_Registry;
use WP_Mock;
use WP_Term;

final class MicropubBridgesTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		\PFBT_Format_Detector::$mark_as_manual_calls = array();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	private function invoke_private( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( Outpost_Micropub_Bridges::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null, ...$args );
	}

	// --- Post format inference (POSSE integration) -------------------------

	public function test_infer_post_format_like_of_maps_to_link(): void {
		$result = $this->invoke_private(
			'infer_post_format',
			array( array( 'like-of' => 'https://example.test/post' ) )
		);
		$this->assertEquals( 'link', $result );
	}

	public function test_infer_post_format_repost_of_maps_to_link(): void {
		$result = $this->invoke_private(
			'infer_post_format',
			array( array( 'repost-of' => 'https://example.test/post' ) )
		);
		$this->assertEquals( 'link', $result );
	}

	public function test_infer_post_format_bookmark_of_maps_to_link(): void {
		$result = $this->invoke_private(
			'infer_post_format',
			array( array( 'bookmark-of' => 'https://example.test/post' ) )
		);
		$this->assertEquals( 'link', $result );
	}

	public function test_infer_post_format_single_photo_maps_to_image(): void {
		$result = $this->invoke_private(
			'infer_post_format',
			array( array( 'photo' => 'https://example.test/photo.jpg' ) )
		);
		$this->assertEquals( 'image', $result );
	}

	public function test_infer_post_format_photo_array_one_maps_to_image(): void {
		$result = $this->invoke_private(
			'infer_post_format',
			array( array( 'photo' => array( 'https://example.test/a.jpg' ) ) )
		);
		$this->assertEquals( 'image', $result );
	}

	public function test_infer_post_format_photo_array_many_maps_to_gallery(): void {
		$result = $this->invoke_private(
			'infer_post_format',
			array(
				array(
					'photo' => array(
						'https://example.test/a.jpg',
						'https://example.test/b.jpg',
					),
				),
			)
		);
		$this->assertEquals( 'gallery', $result );
	}

	public function test_infer_post_format_dedupes_doubled_photo_array(): void {
		// The upstream Micropub plugin enriches `$input['photo']`
		// post-sideload, so a single-photo post may arrive with a
		// 2-entry doubled array. Dedupe before deciding gallery vs
		// image so a single unique URL still maps to image.
		$result = $this->invoke_private(
			'infer_post_format',
			array(
				array(
					'photo' => array(
						'https://example.test/single.jpg',
						'https://example.test/single.jpg',
					),
				),
			)
		);
		$this->assertEquals( 'image', $result );
	}

	public function test_infer_post_format_dedupes_doubled_three_photos(): void {
		// Three unique photos doubled to 6 entries should still map
		// to gallery (count > 1 after dedupe).
		$result = $this->invoke_private(
			'infer_post_format',
			array(
				array(
					'photo' => array(
						'https://example.test/a.jpg',
						'https://example.test/b.jpg',
						'https://example.test/c.jpg',
						'https://example.test/a.jpg',
						'https://example.test/b.jpg',
						'https://example.test/c.jpg',
					),
				),
			)
		);
		$this->assertEquals( 'gallery', $result );
	}

	public function test_infer_post_format_listen_of_maps_to_audio(): void {
		$result = $this->invoke_private(
			'infer_post_format',
			array( array( 'listen-of' => 'https://example.test/track' ) )
		);
		$this->assertEquals( 'audio', $result );
	}

	public function test_infer_post_format_watch_of_maps_to_video(): void {
		$result = $this->invoke_private(
			'infer_post_format',
			array( array( 'watch-of' => 'https://example.test/movie' ) )
		);
		$this->assertEquals( 'video', $result );
	}

	public function test_infer_post_format_in_reply_to_maps_to_status(): void {
		$result = $this->invoke_private(
			'infer_post_format',
			array(
				array(
					'in-reply-to' => 'https://example.test/post',
					'content'     => 'Quick reply.',
				),
			)
		);
		$this->assertEquals( 'status', $result );
	}

	public function test_infer_post_format_name_only_maps_to_standard(): void {
		$result = $this->invoke_private(
			'infer_post_format',
			array(
				array(
					'name'    => 'Article title',
					'content' => 'Long body…',
				),
			)
		);
		$this->assertEquals( 'standard', $result );
	}

	public function test_infer_post_format_short_content_maps_to_status(): void {
		$result = $this->invoke_private(
			'infer_post_format',
			array( array( 'content' => 'short note' ) )
		);
		$this->assertEquals( 'status', $result );
	}

	public function test_infer_post_format_long_content_maps_to_standard(): void {
		$result = $this->invoke_private(
			'infer_post_format',
			array( array( 'content' => str_repeat( 'a', 300 ) ) )
		);
		$this->assertEquals( 'standard', $result );
	}

	public function test_infer_post_format_empty_returns_null(): void {
		$result = $this->invoke_private( 'infer_post_format', array( array() ) );
		$this->assertNull( $result );
	}

	public function test_infer_post_format_empty_string_content_returns_null(): void {
		$result = $this->invoke_private(
			'infer_post_format',
			array( array( 'content' => '' ) )
		);
		$this->assertNull( $result );
	}

	// --- Scalar property reading -----------------------------------------

	public function test_scalar_returns_null_for_missing_key(): void {
		$result = $this->invoke_private(
			'scalar',
			array( array( 'foo' => 'bar' ), 'baz' )
		);
		$this->assertNull( $result );
	}

	public function test_scalar_returns_string_for_scalar_value(): void {
		$result = $this->invoke_private(
			'scalar',
			array( array( 'mp-slug' => 'my-post' ), 'mp-slug' )
		);
		$this->assertEquals( 'my-post', $result );
	}

	public function test_scalar_returns_first_element_for_array_value(): void {
		$result = $this->invoke_private(
			'scalar',
			array( array( 'mp-slug' => array( 'my-post' ) ), 'mp-slug' )
		);
		$this->assertEquals( 'my-post', $result );
	}

	public function test_scalar_returns_null_for_non_string(): void {
		$result = $this->invoke_private(
			'scalar',
			array( array( 'mp-slug' => 42 ), 'mp-slug' )
		);
		$this->assertNull( $result );
	}

	// --- Property presence check -----------------------------------------

	public function test_has_property_true_for_string(): void {
		$result = $this->invoke_private(
			'has_property',
			array( array( 'name' => 'Title' ), 'name' )
		);
		$this->assertTrue( $result );
	}

	public function test_has_property_false_for_empty_string(): void {
		$result = $this->invoke_private(
			'has_property',
			array( array( 'name' => '' ), 'name' )
		);
		$this->assertFalse( $result );
	}

	public function test_has_property_false_for_whitespace(): void {
		$result = $this->invoke_private(
			'has_property',
			array( array( 'name' => '   ' ), 'name' )
		);
		$this->assertFalse( $result );
	}

	public function test_has_property_true_for_non_empty_array(): void {
		$result = $this->invoke_private(
			'has_property',
			array( array( 'category' => array( 'a', 'b' ) ), 'category' )
		);
		$this->assertTrue( $result );
	}

	public function test_has_property_false_for_empty_array(): void {
		$result = $this->invoke_private(
			'has_property',
			array( array( 'category' => array() ), 'category' )
		);
		$this->assertFalse( $result );
	}

	public function test_has_property_false_for_missing_key(): void {
		$result = $this->invoke_private(
			'has_property',
			array( array(), 'name' )
		);
		$this->assertFalse( $result );
	}

	// --- Property extraction (form-encoded vs JSON Micropub) -------------

	public function test_extract_properties_form_encoded(): void {
		$result = $this->invoke_private(
			'extract_properties',
			array( array( 'content' => 'hi', 'mp-slug' => 'test' ) )
		);
		$this->assertEquals( array( 'content' => 'hi', 'mp-slug' => 'test' ), $result );
	}

	public function test_extract_properties_json_nested(): void {
		$result = $this->invoke_private(
			'extract_properties',
			array(
				array(
					'type'       => 'h-entry',
					'properties' => array( 'content' => 'hi' ),
				),
			)
		);
		$this->assertEquals( array( 'content' => 'hi' ), $result );
	}

	// --- mp-categories[] auto-create bridge ------------------------------

	public function test_apply_categories_no_op_when_property_missing(): void {
		// No expectations on get_term_by/wp_insert_term/wp_set_post_categories —
		// they shouldn't be called when the property is absent.
		$this->invoke_private( 'apply_categories', array( 42, array() ) );
		$this->assertTrue( true ); // reached without WP_Mock failures
	}

	public function test_apply_categories_no_op_when_property_empty_array(): void {
		WP_Mock::userFunction( 'sanitize_text_field' )->never();
		$this->invoke_private( 'apply_categories', array( 42, array( 'mp-categories' => array() ) ) );
		$this->assertTrue( true );
	}

	public function test_apply_categories_uses_existing_term_when_name_matches(): void {
		WP_Mock::userFunction( 'sanitize_text_field' )
			->once()
			->with( 'Tech' )
			->andReturn( 'Tech' );
		WP_Mock::userFunction( 'get_term_by' )
			->once()
			->with( 'name', 'Tech', 'category' )
			->andReturn( new WP_Term( array( 'term_id' => 7 ) ) );
		WP_Mock::userFunction( 'wp_set_post_categories' )
			->once()
			->with( 42, array( 7 ), true );
		$this->invoke_private(
			'apply_categories',
			array( 42, array( 'mp-categories' => array( 'Tech' ) ) )
		);
		$this->assertTrue( true );
	}

	public function test_apply_categories_creates_new_term_when_missing(): void {
		WP_Mock::userFunction( 'sanitize_text_field' )
			->andReturn( 'Brand New' );
		WP_Mock::userFunction( 'get_term_by' )
			->andReturn( false );
		WP_Mock::userFunction( 'sanitize_title' )
			->andReturn( 'brand-new' );
		WP_Mock::userFunction( 'wp_insert_term' )
			->once()
			->with( 'Brand New', 'category' )
			->andReturn( array( 'term_id' => 99 ) );
		WP_Mock::userFunction( 'is_wp_error' )
			->andReturn( false );
		WP_Mock::userFunction( 'wp_set_post_categories' )
			->once()
			->with( 42, array( 99 ), true );
		$this->invoke_private(
			'apply_categories',
			array( 42, array( 'mp-categories' => array( 'Brand New' ) ) )
		);
		$this->assertTrue( true );
	}

	public function test_apply_categories_reuses_term_found_by_slug_fallback(): void {
		// First get_term_by('name', ...) returns false; second get_term_by('slug', ...) returns the term.
		WP_Mock::userFunction( 'sanitize_text_field' )
			->andReturn( 'Tech-Stuff' );
		WP_Mock::userFunction( 'sanitize_title' )
			->andReturn( 'tech-stuff' );
		$call_count = 0;
		WP_Mock::userFunction( 'get_term_by' )
			->andReturnUsing(
				function () use ( &$call_count ) {
					$call_count++;
					return $call_count === 1
						? false
						: new WP_Term( array( 'term_id' => 12 ) );
				}
			);
		WP_Mock::userFunction( 'wp_set_post_categories' )
			->once()
			->with( 42, array( 12 ), true );
		$this->invoke_private(
			'apply_categories',
			array( 42, array( 'mp-categories' => array( 'Tech-Stuff' ) ) )
		);
		$this->assertEquals( 2, $call_count );
	}

	// --- Place name bridge (mp-place-name → _outpost_place_name post meta) ---

	public function test_apply_place_name_writes_post_meta(): void {
		WP_Mock::userFunction( 'sanitize_text_field' )
			->with( 'Big Bend National Park' )
			->andReturn( 'Big Bend National Park' );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, '_outpost_place_name', 'Big Bend National Park' )
			->andReturn( true );
		$this->invoke_private(
			'apply_place_name',
			array( 42, array( 'mp-place-name' => 'Big Bend National Park' ) )
		);
		$this->assertTrue( true );
	}

	public function test_apply_place_name_deletes_when_empty(): void {
		WP_Mock::userFunction( 'sanitize_text_field' )
			->with( '' )
			->andReturn( '' );
		WP_Mock::userFunction( 'delete_post_meta' )
			->once()
			->with( 42, '_outpost_place_name' )
			->andReturn( true );
		$this->invoke_private(
			'apply_place_name',
			array( 42, array( 'mp-place-name' => '' ) )
		);
		$this->assertTrue( true );
	}

	public function test_apply_place_name_noop_when_property_absent(): void {
		// Property entirely absent — no meta read or write should fire.
		// `sanitize_text_field` is not mocked because it shouldn't be called.
		$this->invoke_private(
			'apply_place_name',
			array( 42, array() )
		);
		$this->assertTrue( true );
	}

	// --- F1: ?q=syndicate-to chip merging -------------------------------------

	public function test_merge_syndicate_chips_appends_activitypub_when_plugin_active(): void {
		// Reset adapter cache so this test owns the registry state.
		Outpost_Companion_Registry::reset_for_tests();
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );

		$result = Outpost_Micropub_Bridges::merge_syndicate_chips( array() );

		$uids = array_column( $result, 'uid' );
		$this->assertContains( 'activitypub', $uids );
		$activitypub_chip = null;
		foreach ( $result as $chip ) {
			if ( 'activitypub' === ( $chip['uid'] ?? null ) ) {
				$activitypub_chip = $chip;
				break;
			}
		}
		$this->assertNotNull( $activitypub_chip );
		$this->assertSame( 'Fediverse (via ActivityPub plugin)', $activitypub_chip['name'] );
	}

	public function test_merge_syndicate_chips_omits_activitypub_when_plugin_inactive(): void {
		Outpost_Companion_Registry::reset_for_tests();
		// Detector falls through to get_plugins() when is_plugin_active is
		// false, so both must be mocked. Empty get_plugins => 'absent'
		// status across the board.
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		$result = Outpost_Micropub_Bridges::merge_syndicate_chips( array() );

		$uids = array_column( $result, 'uid' );
		$this->assertNotContains( 'activitypub', $uids );
	}

	public function test_merge_syndicate_chips_preserves_existing_targets(): void {
		Outpost_Companion_Registry::reset_for_tests();
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		// Existing chip from elsewhere (e.g. the Syndication Links plugin
		// or a manually-configured target) must passthrough unchanged.
		// Generic example.social handle — never a real instance.
		$existing = array(
			array(
				'uid'  => 'manual-mastodon-example-social',
				'name' => 'Mastodon (example.social)',
			),
		);
		$result   = Outpost_Micropub_Bridges::merge_syndicate_chips( $existing );

		$uids = array_column( $result, 'uid' );
		$this->assertContains( 'manual-mastodon-example-social', $uids );
		$this->assertCount( 1, $result );
	}

	public function test_merge_syndicate_chips_dedupes_by_uid(): void {
		Outpost_Companion_Registry::reset_for_tests();
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );

		// If something else already registered a chip with uid='activitypub'
		// (a custom integration, a future companion overlap), the merger
		// must NOT append a duplicate.
		$existing = array(
			array(
				'uid'  => 'activitypub',
				'name' => 'Pre-existing federation chip',
			),
		);
		$result   = Outpost_Micropub_Bridges::merge_syndicate_chips( $existing );

		$activitypub_chips = array_filter(
			$result,
			static fn( $chip ): bool => 'activitypub' === ( $chip['uid'] ?? null )
		);
		$this->assertCount( 1, $activitypub_chips );
		// The pre-existing chip wins — the merger doesn't overwrite.
		$first = array_values( $activitypub_chips )[0];
		$this->assertSame( 'Pre-existing federation chip', $first['name'] );
	}

	public function test_merge_syndicate_chips_handles_non_array_input(): void {
		Outpost_Companion_Registry::reset_for_tests();
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		// The Shanske Micropub plugin always passes an array, but defensive
		// callers might pass something else through their own filter chain.
		$result = Outpost_Micropub_Bridges::merge_syndicate_chips( null );
		$this->assertIsArray( $result );
		$this->assertSame( array(), $result );
	}

	// --- F3: photo alt-text bridge ----------------------------------------
	//
	// Investigation findings (F3, see also CLAUDE.md F3 entry):
	//
	// 1. Outpost is a Micropub CLIENT. The image upload write path is
	//    owned by the Shanske Micropub plugin
	//    (indieweb/wordpress-micropub) — Outpost has no PHP photo upload
	//    handler.
	//
	// 2. The Shanske Micropub plugin recognizes structured photo entries
	//    of the form { value: <url>, alt: <alt> } per the Micropub spec
	//    JSON syntax, but passes the alt through to
	//    media_sideload_url($url, $post_id, $title) where the third arg
	//    becomes attachment post_title — NOT _wp_attachment_image_alt.
	//
	// 3. The Shanske Micropub plugin does NOT honor the parallel-array
	//    convention (`photo[]=...&mp-photo-alt[]=...`). Outpost client
	//    requests using that shape silently lose the alt text.
	//
	// 4. The ActivityPub plugin's Post transformer reads
	//    `_wp_attachment_image_alt` to populate AP attachment[].name. The
	//    field is the canonical WP alt-text storage location.
	//
	// 5. So without an Outpost-side bridge, every Outpost-originated
	//    Photo post syndicates to the fediverse with empty image alt
	//    text — accessibility regression.
	//
	// The apply_photo_alt_text bridge below fixes the chain end-to-end
	// without modifying the upstream Micropub plugin.

	public function test_apply_photo_alt_writes_meta_for_structured_shape(): void {
		WP_Mock::userFunction( 'attachment_url_to_postid' )
			->with( 'https://example.test/wp-content/uploads/2026/05/photo1.jpg' )
			->andReturn( 101 );
		WP_Mock::userFunction( 'wp_get_post_parent_id' )
			->with( 101 )
			->andReturn( 42 );
		WP_Mock::userFunction( 'sanitize_text_field' )
			->with( 'A red apple on a wooden table' )
			->andReturn( 'A red apple on a wooden table' );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 101, '_wp_attachment_image_alt', 'A red apple on a wooden table' )
			->andReturn( true );

		$properties = array(
			'photo' => array(
				array(
					'value' => 'https://example.test/wp-content/uploads/2026/05/photo1.jpg',
					'alt'   => 'A red apple on a wooden table',
				),
			),
		);
		$this->invoke_private( 'apply_photo_alt_text', array( 42, $properties ) );
		$this->assertTrue( true );
	}

	public function test_apply_photo_alt_writes_meta_for_parallel_array_shape(): void {
		WP_Mock::userFunction( 'attachment_url_to_postid' )
			->with( 'https://example.test/photo1.jpg' )
			->andReturn( 101 );
		WP_Mock::userFunction( 'attachment_url_to_postid' )
			->with( 'https://example.test/photo2.jpg' )
			->andReturn( 102 );
		WP_Mock::userFunction( 'wp_get_post_parent_id' )
			->with( 101 )
			->andReturn( 42 );
		WP_Mock::userFunction( 'wp_get_post_parent_id' )
			->with( 102 )
			->andReturn( 42 );
		WP_Mock::userFunction( 'sanitize_text_field' )
			->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 101, '_wp_attachment_image_alt', 'first alt' )
			->andReturn( true );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 102, '_wp_attachment_image_alt', 'second alt' )
			->andReturn( true );

		$properties = array(
			'photo'         => array(
				'https://example.test/photo1.jpg',
				'https://example.test/photo2.jpg',
			),
			'mp-photo-alt' => array( 'first alt', 'second alt' ),
		);
		$this->invoke_private( 'apply_photo_alt_text', array( 42, $properties ) );
		$this->assertTrue( true );
	}

	public function test_apply_photo_alt_persists_empty_string_when_alt_missing(): void {
		// Empty alt is preserved (not skipped) so the AP attachment.name
		// has an explicit empty value rather than missing field.
		WP_Mock::userFunction( 'attachment_url_to_postid' )
			->with( 'https://example.test/photo.jpg' )
			->andReturn( 101 );
		WP_Mock::userFunction( 'wp_get_post_parent_id' )
			->with( 101 )
			->andReturn( 42 );
		WP_Mock::userFunction( 'sanitize_text_field' )
			->with( '' )
			->andReturn( '' );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 101, '_wp_attachment_image_alt', '' )
			->andReturn( true );

		$properties = array(
			'photo' => 'https://example.test/photo.jpg',
		);
		$this->invoke_private( 'apply_photo_alt_text', array( 42, $properties ) );
		$this->assertTrue( true );
	}

	public function test_apply_photo_alt_skips_attachments_belonging_to_other_posts(): void {
		// If attachment_url_to_postid resolves to an attachment whose
		// post_parent is some OTHER post, the bridge must not touch it.
		// Defensive — keeps the bridge from clobbering alt text on
		// shared media.
		WP_Mock::userFunction( 'attachment_url_to_postid' )
			->with( 'https://example.test/shared.jpg' )
			->andReturn( 99 );
		WP_Mock::userFunction( 'wp_get_post_parent_id' )
			->with( 99 )
			->andReturn( 7 );
		// update_post_meta must NOT be called.

		$properties = array(
			'photo' => array(
				array(
					'value' => 'https://example.test/shared.jpg',
					'alt'   => 'should not write',
				),
			),
		);
		$this->invoke_private( 'apply_photo_alt_text', array( 42, $properties ) );
		$this->assertTrue( true );
	}

	public function test_apply_photo_alt_skips_unresolvable_urls(): void {
		// External URLs (not in our media library) return 0 from
		// attachment_url_to_postid. Bridge skips them silently.
		WP_Mock::userFunction( 'attachment_url_to_postid' )
			->with( 'https://other-site.example/their-photo.jpg' )
			->andReturn( 0 );
		// update_post_meta must NOT be called.

		$properties = array(
			'photo' => array(
				array(
					'value' => 'https://other-site.example/their-photo.jpg',
					'alt'   => 'someone else has this',
				),
			),
		);
		$this->invoke_private( 'apply_photo_alt_text', array( 42, $properties ) );
		$this->assertTrue( true );
	}

	public function test_apply_photo_alt_noop_when_no_photo_property(): void {
		// No photo, no calls. Reply / Like / Note posts run through this
		// bridge alongside Photo posts; the noop path matters.
		$this->invoke_private( 'apply_photo_alt_text', array( 42, array() ) );
		$this->assertTrue( true );
	}

	public function test_apply_photo_alt_attachments_with_zero_parent_are_accepted(): void {
		// Some attachments are created with post_parent=0 (orphan). The
		// upstream Micropub plugin re-parents them, but during early
		// processing the parent may still be 0. Treat 0 as "OK to write"
		// — the bridge's job is to honor the alt-text intent, not enforce
		// parent linkage.
		WP_Mock::userFunction( 'attachment_url_to_postid' )
			->with( 'https://example.test/orphan.jpg' )
			->andReturn( 101 );
		WP_Mock::userFunction( 'wp_get_post_parent_id' )
			->with( 101 )
			->andReturn( 0 );
		WP_Mock::userFunction( 'sanitize_text_field' )
			->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 101, '_wp_attachment_image_alt', 'orphan alt' )
			->andReturn( true );

		$properties = array(
			'photo' => array(
				array(
					'value' => 'https://example.test/orphan.jpg',
					'alt'   => 'orphan alt',
				),
			),
		);
		$this->invoke_private( 'apply_photo_alt_text', array( 42, $properties ) );
		$this->assertTrue( true );
	}

	public function test_collect_photo_alt_pairs_normalizes_both_shapes(): void {
		// Direct test of the private resolver — verifies structured
		// entries beat parallel-array entries when both are present.
		$result = $this->invoke_private(
			'collect_photo_alt_pairs',
			array(
				array(
					'photo' => array(
						array(
							'value' => 'https://example.test/struct.jpg',
							'alt'   => 'structured alt',
						),
						'https://example.test/plain.jpg',
					),
					'mp-photo-alt' => array( 'parallel alt 1', 'parallel alt 2' ),
				),
			)
		);
		$this->assertSame(
			array(
				array(
					'url' => 'https://example.test/struct.jpg',
					'alt' => 'structured alt',
				),
				array(
					'url' => 'https://example.test/plain.jpg',
					'alt' => 'parallel alt 2',
				),
			),
			$result
		);
	}

	// --- C1 coordination: mark_format_manual ------------------------------
	//
	// Outpost's apply_post_format calls mark_format_manual after applying
	// either an explicit `mp-post-format` value or an inferred format. This
	// satisfies the coordination contract C1 with PFBT_Format_Detector (auto-
	// detect re-enabled in PFBT v2.3.0+): once Outpost decides the format,
	// PFBT's detector must respect that choice on every subsequent save.

	public function test_mark_format_manual_calls_pfbt_with_post_id(): void {
		$this->invoke_private( 'mark_format_manual', array( 123 ) );

		$this->assertSame( array( 123 ), \PFBT_Format_Detector::$mark_as_manual_calls );
	}

	public function test_mark_format_manual_passes_each_distinct_post_id(): void {
		$this->invoke_private( 'mark_format_manual', array( 100 ) );
		$this->invoke_private( 'mark_format_manual', array( 200 ) );
		$this->invoke_private( 'mark_format_manual', array( 300 ) );

		$this->assertSame(
			array( 100, 200, 300 ),
			\PFBT_Format_Detector::$mark_as_manual_calls
		);
	}

	public function test_mark_format_manual_is_idempotent_call_pattern(): void {
		// PFBT_Format_Detector::mark_as_manual is documented idempotent on the
		// PFBT side (update_post_meta of the same value is a no-op). Outpost's
		// wrapper passes through the same post ID without dedup; verify the
		// call pattern matches the contract.
		$this->invoke_private( 'mark_format_manual', array( 555 ) );
		$this->invoke_private( 'mark_format_manual', array( 555 ) );

		$this->assertSame(
			array( 555, 555 ),
			\PFBT_Format_Detector::$mark_as_manual_calls,
			'mark_format_manual passes each call through; PFBT side handles idempotence'
		);
	}
}
