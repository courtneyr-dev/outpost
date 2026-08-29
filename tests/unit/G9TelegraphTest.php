<?php
/**
 * Unit tests for the G9 Telegraph adapter.
 *
 * Test surface focuses on the block-to-Telegraph-DOM converter (the
 * heart of the adapter, with the most logic worth covering); HTTP-
 * dependent paths (createAccount, createPage) are mocked via WP_Mock
 * userFunction stubs.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Telegraph_Adapter;
use WP_Mock;

final class G9TelegraphTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	// --- Block converter ------------------------------------------------

	public function test_paragraph_block_converts_to_p_node(): void {
		$blocks = array(
			array(
				'blockName' => 'core/paragraph',
				'innerHTML' => '<p>Hello world.</p>',
				'attrs'     => array(),
			),
		);
		WP_Mock::userFunction( 'wp_kses' )->andReturnUsing( static function ( $s ) {
			return $s;
		} );

		$out = Outpost_Telegraph_Adapter::convert_blocks_to_telegraph_dom( $blocks );

		$this->assertCount( 1, $out );
		$this->assertSame( 'p', $out[0]['tag'] );
		$this->assertSame( 'Hello world.', trim( (string) $out[0]['children'][0] ) );
	}

	public function test_heading_h1_or_h2_collapses_to_h3(): void {
		WP_Mock::userFunction( 'wp_kses' )->andReturnUsing( static function ( $s ) {
			return $s;
		} );

		$blocks = array(
			array(
				'blockName' => 'core/heading',
				'innerHTML' => '<h1>Top</h1>',
				'attrs'     => array( 'level' => 1 ),
			),
			array(
				'blockName' => 'core/heading',
				'innerHTML' => '<h2>Section</h2>',
				'attrs'     => array( 'level' => 2 ),
			),
			array(
				'blockName' => 'core/heading',
				'innerHTML' => '<h3>Sub</h3>',
				'attrs'     => array( 'level' => 3 ),
			),
		);
		$out    = Outpost_Telegraph_Adapter::convert_blocks_to_telegraph_dom( $blocks );

		$this->assertSame( 'h3', $out[0]['tag'] );
		$this->assertSame( 'h3', $out[1]['tag'] );
		$this->assertSame( 'h3', $out[2]['tag'] );
	}

	public function test_heading_h5_or_h6_collapses_to_h4(): void {
		WP_Mock::userFunction( 'wp_kses' )->andReturnUsing( static function ( $s ) {
			return $s;
		} );

		$blocks = array(
			array(
				'blockName' => 'core/heading',
				'innerHTML' => '<h5>Detail</h5>',
				'attrs'     => array( 'level' => 5 ),
			),
			array(
				'blockName' => 'core/heading',
				'innerHTML' => '<h6>Aside</h6>',
				'attrs'     => array( 'level' => 6 ),
			),
		);
		$out    = Outpost_Telegraph_Adapter::convert_blocks_to_telegraph_dom( $blocks );

		$this->assertSame( 'h4', $out[0]['tag'] );
		$this->assertSame( 'h4', $out[1]['tag'] );
	}

	public function test_quote_block_converts_to_blockquote(): void {
		WP_Mock::userFunction( 'wp_kses' )->andReturnUsing( static function ( $s ) {
			return $s;
		} );

		$blocks = array(
			array(
				'blockName' => 'core/quote',
				'innerHTML' => '<blockquote>Said so.</blockquote>',
				'attrs'     => array(),
			),
		);
		$out    = Outpost_Telegraph_Adapter::convert_blocks_to_telegraph_dom( $blocks );

		$this->assertSame( 'blockquote', $out[0]['tag'] );
	}

	public function test_separator_converts_to_hr(): void {
		$blocks = array(
			array(
				'blockName' => 'core/separator',
				'innerHTML' => '<hr/>',
				'attrs'     => array(),
			),
		);
		$out    = Outpost_Telegraph_Adapter::convert_blocks_to_telegraph_dom( $blocks );

		$this->assertSame( 'hr', $out[0]['tag'] );
	}

	public function test_code_block_converts_to_pre_with_code_child(): void {
		WP_Mock::userFunction( 'wp_strip_all_tags' )->andReturnUsing( static function ( $s ) {
			return preg_replace( '~<[^>]+>~', '', (string) $s );
		} );

		$blocks = array(
			array(
				'blockName' => 'core/code',
				'innerHTML' => '<pre><code>echo "hi";</code></pre>',
				'attrs'     => array(),
			),
		);
		$out    = Outpost_Telegraph_Adapter::convert_blocks_to_telegraph_dom( $blocks );

		$this->assertSame( 'pre', $out[0]['tag'] );
		$this->assertSame( 'code', $out[0]['children'][0]['tag'] );
	}

	public function test_unordered_list_converts_to_ul_with_li(): void {
		WP_Mock::userFunction( 'wp_kses' )->andReturnUsing( static function ( $s ) {
			return $s;
		} );

		$blocks = array(
			array(
				'blockName' => 'core/list',
				'innerHTML' => '<ul><li>One</li><li>Two</li></ul>',
				'attrs'     => array(),
			),
		);
		$out    = Outpost_Telegraph_Adapter::convert_blocks_to_telegraph_dom( $blocks );

		$this->assertSame( 'ul', $out[0]['tag'] );
		$this->assertCount( 2, $out[0]['children'] );
		$this->assertSame( 'li', $out[0]['children'][0]['tag'] );
	}

	public function test_ordered_list_converts_to_ol(): void {
		WP_Mock::userFunction( 'wp_kses' )->andReturnUsing( static function ( $s ) {
			return $s;
		} );

		$blocks = array(
			array(
				'blockName' => 'core/list',
				'innerHTML' => '<ol><li>First</li></ol>',
				'attrs'     => array( 'ordered' => true ),
			),
		);
		$out    = Outpost_Telegraph_Adapter::convert_blocks_to_telegraph_dom( $blocks );

		$this->assertSame( 'ol', $out[0]['tag'] );
	}

	public function test_image_block_extracts_src_to_figure_with_img(): void {
		$blocks = array(
			array(
				'blockName' => 'core/image',
				'innerHTML' => '<figure><img src="https://example.com/cat.jpg" alt=""></figure>',
				'attrs'     => array(),
			),
		);
		$out    = Outpost_Telegraph_Adapter::convert_blocks_to_telegraph_dom( $blocks );

		$this->assertSame( 'figure', $out[0]['tag'] );
		$this->assertSame( 'img', $out[0]['children'][0]['tag'] );
		$this->assertSame( 'https://example.com/cat.jpg', $out[0]['children'][0]['attrs']['src'] );
	}

	public function test_youtube_embed_preserved_other_embeds_dropped(): void {
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( static function ( $url, $component = -1 ) {
			$parts = parse_url( (string) $url );
			if ( -1 === $component ) {
				return $parts;
			}
			$map = array(
				PHP_URL_HOST => 'host',
			);
			return $parts[ $map[ $component ] ?? '' ] ?? null;
		} );

		$blocks = array(
			array(
				'blockName' => 'core/embed',
				'innerHTML' => '',
				'attrs'     => array( 'url' => 'https://youtube.com/watch?v=abc' ),
			),
			array(
				'blockName' => 'core/embed',
				'innerHTML' => '',
				'attrs'     => array( 'url' => 'https://example.com/embed' ),
			),
		);
		$out    = Outpost_Telegraph_Adapter::convert_blocks_to_telegraph_dom( $blocks );

		$this->assertCount( 1, $out );
		$this->assertSame( 'iframe', $out[0]['tag'] );
		$this->assertSame( 'https://youtube.com/watch?v=abc', $out[0]['attrs']['src'] );
	}

	public function test_unsupported_block_falls_back_to_plain_text_paragraph(): void {
		WP_Mock::userFunction( 'wp_strip_all_tags' )->andReturnUsing( static function ( $s ) {
			return preg_replace( '~<[^>]+>~', '', (string) $s );
		} );

		$blocks = array(
			array(
				'blockName' => 'custom/widget',
				'innerHTML' => '<div class="widget">Some content</div>',
				'attrs'     => array(),
			),
		);
		$out    = Outpost_Telegraph_Adapter::convert_blocks_to_telegraph_dom( $blocks );

		$this->assertSame( 'p', $out[0]['tag'] );
		$this->assertStringContainsString( 'Some content', (string) $out[0]['children'][0] );
	}

	public function test_empty_block_list_returns_empty(): void {
		$out = Outpost_Telegraph_Adapter::convert_blocks_to_telegraph_dom( array() );
		$this->assertSame( array(), $out );
	}
}
