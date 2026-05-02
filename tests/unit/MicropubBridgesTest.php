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
use WP_Mock;

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
}
