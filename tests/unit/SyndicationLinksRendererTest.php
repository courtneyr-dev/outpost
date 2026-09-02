<?php
/**
 * Unit tests for Outpost_Syndication_Links_Renderer (F12).
 *
 * Tests the_content/the_excerpt filter behavior + the
 * `outpost_render_syndication_links` filter.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Syndication_Links_Renderer;
use Outpost_Manual_Share_Syndication_Writeback;
use WP_Mock;

final class SyndicationLinksRendererTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $meta_store = array();

	public function setUp(): void {
		WP_Mock::setUp();
		$this->meta_store = array();
		// F2 #10 / A2 #8 static-state reset for filter mocks.
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );

		WP_Mock::userFunction( 'esc_url' )->andReturnUsing( static fn ( string $u ): string => $u );
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing( static fn ( string $s ): string => $s );
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn ( string $u ): string => $u );
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			function ( int $post_id, string $key, bool $single ) {
				return $this->meta_store[ $post_id ][ $key ] ?? '';
			}
		);
		WP_Mock::userFunction( 'update_post_meta' )->andReturnUsing(
			function ( int $post_id, string $key, $value ): bool {
				$this->meta_store[ $post_id ][ $key ] = $value;
				return true;
			}
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	private function seed_links( int $post_id, array $links ): void {
		$this->meta_store[ $post_id ]['outpost_syndication_links'] = $links;
	}

	// =====================================================================
	// Default rendering
	// =====================================================================

	public function test_returns_empty_string_for_post_without_links(): void {
		$html = Outpost_Syndication_Links_Renderer::render_for_post( 42 );
		$this->assertSame( '', $html );
	}

	public function test_renders_single_anchor_for_one_link(): void {
		$this->seed_links( 42, array(
			array(
				'platform_id' => 'instagram-feed',
				'url'         => 'https://www.instagram.com/p/abc',
				'added_at'    => '2026-05-04T18:32:11+00:00',
				'source'      => 'manual_share',
			),
		) );

		$html = Outpost_Syndication_Links_Renderer::render_for_post( 42 );

		$this->assertStringContainsString( '<a class="u-syndication outpost-u-syndication-link"', $html );
		$this->assertStringContainsString( 'href="https://www.instagram.com/p/abc"', $html );
		$this->assertStringContainsString( '>Instagram</a>', $html );
		$this->assertStringContainsString( 'rel="syndication"', $html );
	}

	public function test_renders_multiple_anchors(): void {
		$this->seed_links( 42, array(
			array(
				'platform_id' => 'instagram-feed',
				'url'         => 'https://www.instagram.com/p/abc',
				'added_at'    => '',
				'source'      => 'manual_share',
			),
			array(
				'platform_id' => 'facebook',
				'url'         => 'https://www.facebook.com/posts/1',
				'added_at'    => '',
				'source'      => 'manual_share',
			),
		) );

		$html = Outpost_Syndication_Links_Renderer::render_for_post( 42 );

		$this->assertStringContainsString( 'instagram.com/p/abc', $html );
		$this->assertStringContainsString( 'facebook.com/posts/1', $html );
		$this->assertSame( 2, substr_count( $html, '<a class="u-syndication' ) );
	}

	public function test_wrapper_div_has_hidden_attribute_by_default(): void {
		$this->seed_links( 42, array(
			array(
				'platform_id' => 'instagram-feed',
				'url'         => 'https://www.instagram.com/p/abc',
				'added_at'    => '',
				'source'      => 'manual_share',
			),
		) );

		$html = Outpost_Syndication_Links_Renderer::render_for_post( 42 );

		$this->assertStringContainsString( '<div class="outpost-syndication-links" hidden>', $html );
	}

	public function test_skips_entries_with_empty_url(): void {
		$this->seed_links( 42, array(
			array(
				'platform_id' => 'instagram-feed',
				'url'         => '',
				'added_at'    => '',
				'source'      => 'manual_share',
			),
			array(
				'platform_id' => 'facebook',
				'url'         => 'https://www.facebook.com/posts/1',
				'added_at'    => '',
				'source'      => 'manual_share',
			),
		) );

		$html = Outpost_Syndication_Links_Renderer::render_for_post( 42 );

		$this->assertSame( 1, substr_count( $html, '<a class="u-syndication' ) );
		$this->assertStringContainsString( 'facebook', $html );
	}

	public function test_unknown_platform_id_falls_back_to_humanized_label(): void {
		$this->seed_links( 42, array(
			array(
				'platform_id' => 'custom-vsco',
				'url'         => 'https://vsco.co/user/journal/abc',
				'added_at'    => '',
				'source'      => 'manual_share',
			),
		) );

		$html = Outpost_Syndication_Links_Renderer::render_for_post( 42 );

		$this->assertStringContainsString( '>Custom Vsco</a>', $html );
	}

	// =====================================================================
	// outpost_render_syndication_links filter
	// =====================================================================

	public function test_filter_can_disable_rendering(): void {
		$this->seed_links( 42, array(
			array(
				'platform_id' => 'instagram-feed',
				'url'         => 'https://www.instagram.com/p/abc',
				'added_at'    => '',
				'source'      => 'manual_share',
			),
		) );
		WP_Mock::onFilter( 'outpost_render_syndication_links' )
			->withAnyArgs()
			->reply( false );

		$html = Outpost_Syndication_Links_Renderer::render_for_post( 42 );

		$this->assertSame( '', $html );
	}
}
