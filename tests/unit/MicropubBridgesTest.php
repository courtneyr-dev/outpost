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
}
