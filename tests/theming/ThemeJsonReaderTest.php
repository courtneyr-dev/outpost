<?php
/**
 * Unit tests for Outpost_Theme_Json_Reader (FY-Theming).
 *
 * Covers v2 (single-palette) and v3+ (style-variation) theme.json
 * shapes, slug-priority resolution per token, and the empty-shape
 * fallback when the active theme has no theme.json.
 *
 * @package Outpost\Tests\Theming
 */

declare(strict_types=1);

namespace Outpost\Tests\Theming;

use Outpost_Theme_Json_Reader;
use WP_Mock;

final class ThemeJsonReaderTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		Outpost_Theme_Json_Reader::set_reader_for_tests( null );
		WP_Mock::tearDown();
	}

	private function inject_source( array $source ): void {
		Outpost_Theme_Json_Reader::set_reader_for_tests(
			static fn () => $source
		);
	}

	// --- empty / missing -------------------------------------------------

	public function test_returns_empty_shape_when_source_missing(): void {
		Outpost_Theme_Json_Reader::set_reader_for_tests( static fn () => null );

		$out = Outpost_Theme_Json_Reader::read();
		$this->assertSame(
			array(
				'day'   => array(
					'colors' => array(),
					'fonts'  => array(),
				),
				'night' => array(
					'colors' => array(),
					'fonts'  => array(),
				),
			),
			$out
		);
	}

	public function test_returns_empty_when_source_lacks_settings(): void {
		$this->inject_source( array() );
		$out = Outpost_Theme_Json_Reader::read();
		$this->assertSame( array(), $out['day']['colors'] );
		$this->assertSame( array(), $out['night']['fonts'] );
	}

	// --- v2: single palette ----------------------------------------------

	public function test_v2_single_palette_inherits_into_both_modes(): void {
		$this->inject_source(
			array(
				'settings' => array(
					'color' => array(
						'palette' => array(
							array(
								'slug'  => 'foreground',
								'color' => '#241c4a',
							),
							array(
								'slug'  => 'background',
								'color' => '#fbfaf5',
							),
							array(
								'slug'  => 'accent',
								'color' => '#fb8500',
							),
						),
					),
				),
			)
		);

		$out = Outpost_Theme_Json_Reader::read();

		$this->assertSame( '#241c4a', $out['day']['colors']['text'] );
		$this->assertSame( '#fbfaf5', $out['day']['colors']['bg'] );
		$this->assertSame( '#fb8500', $out['day']['colors']['accent'] );
		// Night inherits from day when no dark variation exists.
		$this->assertSame( $out['day']['colors'], $out['night']['colors'] );
	}

	public function test_slug_priority_falls_through_when_primary_absent(): void {
		// `primary` slug stands in for `foreground` on text token.
		$this->inject_source(
			array(
				'settings' => array(
					'color' => array(
						'palette' => array(
							array(
								'slug'  => 'primary',
								'color' => '#001122',
							),
						),
					),
				),
			)
		);

		$out = Outpost_Theme_Json_Reader::read();
		$this->assertSame( '#001122', $out['day']['colors']['text'] );
	}

	public function test_v3_origin_keyed_palette(): void {
		// Schema v3 wraps palette by origin. Reader extracts the
		// 'theme' origin's entries.
		$this->inject_source(
			array(
				'settings' => array(
					'color' => array(
						'palette' => array(
							'theme' => array(
								array(
									'slug'  => 'background',
									'color' => '#abcdef',
								),
							),
						),
					),
				),
			)
		);

		$out = Outpost_Theme_Json_Reader::read();
		$this->assertSame( '#abcdef', $out['day']['colors']['bg'] );
	}

	// --- font families ---------------------------------------------------

	public function test_reads_body_and_display_and_monospace_fonts(): void {
		$this->inject_source(
			array(
				'settings' => array(
					'typography' => array(
						'fontFamilies' => array(
							array(
								'slug'       => 'body',
								'fontFamily' => 'Inter, sans-serif',
							),
							array(
								'slug'       => 'heading',
								'fontFamily' => 'Newsreader, serif',
							),
							array(
								'slug'       => 'mono',
								'fontFamily' => 'JetBrains Mono, monospace',
							),
						),
					),
				),
			)
		);

		$out = Outpost_Theme_Json_Reader::read();
		$this->assertSame( 'Inter, sans-serif', $out['day']['fonts']['body'] );
		$this->assertSame( 'Newsreader, serif', $out['day']['fonts']['display'] );
		$this->assertSame( 'JetBrains Mono, monospace', $out['day']['fonts']['monospace'] );
	}

	// --- v3: style variations -------------------------------------------

	public function test_v3_style_variation_gives_night_a_separate_palette(): void {
		$this->inject_source(
			array(
				'settings'   => array(
					'color' => array(
						'palette' => array(
							array(
								'slug'  => 'background',
								'color' => '#ffffff',
							),
							array(
								'slug'  => 'foreground',
								'color' => '#222222',
							),
						),
					),
				),
				'variations' => array(
					array(
						'slug'     => 'dark',
						'settings' => array(
							'color' => array(
								'palette' => array(
									array(
										'slug'  => 'background',
										'color' => '#111111',
									),
									array(
										'slug'  => 'foreground',
										'color' => '#eeeeee',
									),
								),
							),
						),
					),
				),
			)
		);

		$out = Outpost_Theme_Json_Reader::read();
		$this->assertSame( '#ffffff', $out['day']['colors']['bg'] );
		$this->assertSame( '#222222', $out['day']['colors']['text'] );
		$this->assertSame( '#111111', $out['night']['colors']['bg'] );
		$this->assertSame( '#eeeeee', $out['night']['colors']['text'] );
	}

	public function test_variation_slug_substring_match(): void {
		// `dark-pro` should still resolve to night via substring match.
		$this->inject_source(
			array(
				'variations' => array(
					array(
						'slug'     => 'dark-pro',
						'settings' => array(
							'color' => array(
								'palette' => array(
									array(
										'slug'  => 'background',
										'color' => '#0a0a0a',
									),
								),
							),
						),
					),
				),
			)
		);

		$out = Outpost_Theme_Json_Reader::read();
		$this->assertSame( '#0a0a0a', $out['night']['colors']['bg'] );
	}

	public function test_has_dark_variation_detects_dark_named_variations(): void {
		$this->inject_source(
			array(
				'variations' => array(
					array( 'slug' => 'midnight' ),
				),
			)
		);
		$this->assertTrue( Outpost_Theme_Json_Reader::has_dark_variation() );
	}

	public function test_has_dark_variation_false_when_no_match(): void {
		$this->inject_source(
			array(
				'variations' => array(
					array( 'slug' => 'sunset-orange' ),
				),
			)
		);
		$this->assertFalse( Outpost_Theme_Json_Reader::has_dark_variation() );
	}

	public function test_has_dark_variation_false_when_no_variations(): void {
		$this->inject_source( array() );
		$this->assertFalse( Outpost_Theme_Json_Reader::has_dark_variation() );
	}

	// --- malformed input handling ---------------------------------------

	public function test_palette_entries_missing_slug_or_color_skipped(): void {
		$this->inject_source(
			array(
				'settings' => array(
					'color' => array(
						'palette' => array(
							array( 'slug' => 'background' ), // missing color
							array( 'color' => '#fff' ),       // missing slug
							array(
								'slug'  => 'foreground',
								'color' => '#000',
							),
						),
					),
				),
			)
		);

		$out = Outpost_Theme_Json_Reader::read();
		$this->assertSame( '#000', $out['day']['colors']['text'] );
		$this->assertArrayNotHasKey( 'bg', $out['day']['colors'] );
	}

	public function test_non_array_entries_safely_ignored(): void {
		$this->inject_source(
			array(
				'settings' => array(
					'color' => array(
						'palette' => array(
							'not-an-entry',
							null,
							array(
								'slug'  => 'foreground',
								'color' => '#abc',
							),
						),
					),
				),
			)
		);

		$out = Outpost_Theme_Json_Reader::read();
		$this->assertSame( '#abc', $out['day']['colors']['text'] );
	}
}
