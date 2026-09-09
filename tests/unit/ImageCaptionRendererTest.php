<?php
/**
 * Unit tests for Outpost_Image_Caption_Renderer.
 *
 * Core's Image block renders a caption only when one is inside the block's
 * markup. It never looks the attachment's caption up at render time, so a
 * caption written to the attachment shows nowhere until something injects it.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use WP_Mock;

final class ImageCaptionRendererTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_injects_the_attachment_caption_into_an_image_block(): void {
		WP_Mock::userFunction( 'get_post_field' )
			->with( 'post_excerpt', 38025 )
			->andReturn( 'They own this street now' );
		WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing( static fn( $v ) => $v );

		$html = '<figure class="wp-block-image size-full">'
			. '<img src="https://example.test/turkey.jpg" alt="Turkeys"/></figure>';
		$block = array(
			'blockName' => 'core/image',
			'attrs'     => array( 'id' => 38025 ),
		);

		$out = \Outpost_Image_Caption_Renderer::filter_block( $html, $block );

		$this->assertStringContainsString(
			'<figcaption class="wp-element-caption">They own this street now</figcaption>',
			$out
		);
		$this->assertStringContainsString( '</figure>', $out );
	}

	public function test_leaves_a_block_that_already_has_a_caption_alone(): void {
		$html = '<figure class="wp-block-image">'
			. '<img src="https://example.test/a.jpg" alt="A"/>'
			. '<figcaption class="wp-element-caption">Written in the editor</figcaption></figure>';
		$block = array(
			'blockName' => 'core/image',
			'attrs'     => array( 'id' => 38025 ),
		);

		$this->assertSame( $html, \Outpost_Image_Caption_Renderer::filter_block( $html, $block ) );
	}

	public function test_leaves_non_image_blocks_alone(): void {
		$html  = '<p>Just a paragraph.</p>';
		$block = array(
			'blockName' => 'core/paragraph',
			'attrs'     => array(),
		);

		$this->assertSame( $html, \Outpost_Image_Caption_Renderer::filter_block( $html, $block ) );
	}

	public function test_leaves_the_block_alone_when_the_attachment_has_no_caption(): void {
		WP_Mock::userFunction( 'get_post_field' )
			->with( 'post_excerpt', 38026 )
			->andReturn( '' );

		$html  = '<figure class="wp-block-image"><img src="https://example.test/b.jpg" alt="B"/></figure>';
		$block = array(
			'blockName' => 'core/image',
			'attrs'     => array( 'id' => 38026 ),
		);

		$this->assertSame( $html, \Outpost_Image_Caption_Renderer::filter_block( $html, $block ) );
	}

	public function test_leaves_an_image_block_with_no_attachment_id_alone(): void {
		$html  = '<figure class="wp-block-image"><img src="https://example.test/c.jpg" alt="C"/></figure>';
		$block = array(
			'blockName' => 'core/image',
			'attrs'     => array(),
		);

		$this->assertSame( $html, \Outpost_Image_Caption_Renderer::filter_block( $html, $block ) );
	}
}
