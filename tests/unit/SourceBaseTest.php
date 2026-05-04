<?php
/**
 * Unit tests for Outpost_Source_Base — pattern matcher (validate +
 * match) and default-method behaviors (matches_url, recipe_for_url,
 * mode_for_url, map_extracted).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Base;
use WP_Mock;

require_once dirname( __DIR__ ) . '/fixtures/source-test-fakes.php';

final class SourceBaseTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		// `wp_parse_url` is provided by tests/bootstrap.php (delegates to parse_url).
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	// --- validate_pattern ----------------------------------------------------

	public function test_validate_pattern_accepts_match_any_sentinel(): void {
		Outpost_Source_Base::validate_pattern( '*' );
		$this->assertTrue( true );
	}

	public function test_validate_pattern_accepts_exact_host(): void {
		Outpost_Source_Base::validate_pattern( 'example.com' );
		$this->assertTrue( true );
	}

	public function test_validate_pattern_accepts_suffix_wildcard(): void {
		Outpost_Source_Base::validate_pattern( '*.example.social' );
		$this->assertTrue( true );
	}

	public function test_validate_pattern_accepts_host_plus_path(): void {
		Outpost_Source_Base::validate_pattern( 'example.com/category/' );
		$this->assertTrue( true );
	}

	public function test_validate_pattern_rejects_empty(): void {
		$this->expectException( \InvalidArgumentException::class );
		Outpost_Source_Base::validate_pattern( '' );
	}

	public function test_validate_pattern_rejects_scheme_prefix(): void {
		$this->expectException( \InvalidArgumentException::class );
		Outpost_Source_Base::validate_pattern( 'https://example.com' );
	}

	public function test_validate_pattern_rejects_query_string(): void {
		$this->expectException( \InvalidArgumentException::class );
		Outpost_Source_Base::validate_pattern( 'example.com?q=1' );
	}

	public function test_validate_pattern_rejects_fragment(): void {
		$this->expectException( \InvalidArgumentException::class );
		Outpost_Source_Base::validate_pattern( 'example.com#anchor' );
	}

	public function test_validate_pattern_rejects_middle_wildcard(): void {
		$this->expectException( \InvalidArgumentException::class );
		Outpost_Source_Base::validate_pattern( 'foo.*.example.com' );
	}

	public function test_validate_pattern_rejects_trailing_wildcard(): void {
		$this->expectException( \InvalidArgumentException::class );
		Outpost_Source_Base::validate_pattern( 'example.*' );
	}

	public function test_validate_pattern_rejects_double_wildcard(): void {
		$this->expectException( \InvalidArgumentException::class );
		Outpost_Source_Base::validate_pattern( '*.*.example.com' );
	}

	public function test_validate_pattern_rejects_regex_metacharacters(): void {
		$this->expectException( \InvalidArgumentException::class );
		Outpost_Source_Base::validate_pattern( 'example.com/a(b|c)/' );
	}

	public function test_validate_pattern_rejects_path_without_trailing_slash(): void {
		$this->expectException( \InvalidArgumentException::class );
		Outpost_Source_Base::validate_pattern( 'example.com/path' );
	}

	public function test_validate_pattern_rejects_empty_host_with_path(): void {
		$this->expectException( \InvalidArgumentException::class );
		Outpost_Source_Base::validate_pattern( '/path/' );
	}

	// --- pattern_matches -----------------------------------------------------

	public function test_match_any_pattern_matches_anything(): void {
		$this->assertTrue( Outpost_Source_Base::pattern_matches( '*', 'https://example.com/' ) );
		$this->assertTrue( Outpost_Source_Base::pattern_matches( '*', 'https://anything.example.test/path' ) );
	}

	public function test_exact_host_match_succeeds(): void {
		$this->assertTrue(
			Outpost_Source_Base::pattern_matches( 'example.com', 'https://example.com/anything' )
		);
	}

	public function test_exact_host_match_is_case_insensitive(): void {
		$this->assertTrue(
			Outpost_Source_Base::pattern_matches( 'Example.COM', 'https://example.com/' )
		);
	}

	public function test_exact_host_pattern_rejects_subdomain(): void {
		$this->assertFalse(
			Outpost_Source_Base::pattern_matches( 'example.com', 'https://api.example.com/' )
		);
	}

	public function test_suffix_pattern_matches_subdomains(): void {
		$this->assertTrue(
			Outpost_Source_Base::pattern_matches( '*.example.social', 'https://alice.example.social/' )
		);
		$this->assertTrue(
			Outpost_Source_Base::pattern_matches( '*.example.social', 'https://bob.example.social/path' )
		);
	}

	public function test_suffix_pattern_does_not_match_apex(): void {
		// `*.example.social` matches subdomains, NOT example.social itself.
		$this->assertFalse(
			Outpost_Source_Base::pattern_matches( '*.example.social', 'https://example.social/' )
		);
	}

	public function test_path_prefix_pattern_matches_correct_path(): void {
		$this->assertTrue(
			Outpost_Source_Base::pattern_matches( 'example.com/category/', 'https://example.com/category/foo' )
		);
	}

	public function test_path_prefix_pattern_rejects_different_path(): void {
		$this->assertFalse(
			Outpost_Source_Base::pattern_matches( 'example.com/category/', 'https://example.com/other/foo' )
		);
	}

	public function test_path_prefix_match_is_case_sensitive_per_http_semantics(): void {
		$this->assertFalse(
			Outpost_Source_Base::pattern_matches( 'example.com/Category/', 'https://example.com/category/x' )
		);
	}

	public function test_pattern_rejects_url_without_host(): void {
		$this->assertFalse( Outpost_Source_Base::pattern_matches( 'example.com', '/just-a-path' ) );
	}

	// --- default matches_url ------------------------------------------------

	public function test_matches_url_walks_host_patterns_for_match(): void {
		$source = new \Outpost_F5TestSource_Stub(
			array(
				'host_patterns' => array( 'a.example.com', '*.b.example.com' ),
			)
		);
		$this->assertTrue( $source->matches_url( 'https://a.example.com/' ) );
		$this->assertTrue( $source->matches_url( 'https://x.b.example.com/' ) );
		$this->assertFalse( $source->matches_url( 'https://other.example.com/' ) );
	}

	// --- default recipe_for_url ---------------------------------------------

	public function test_recipe_for_url_returns_capabilities_recipe(): void {
		$recipe = array( 'endpoint' => 'https://example.com/oembed?url={url}' );
		$source = new \Outpost_F5TestSource_Stub( array( 'recipe' => $recipe ) );
		$this->assertSame( $recipe, $source->recipe_for_url( 'https://example.com/track/abc' ) );
	}

	public function test_recipe_for_url_returns_empty_array_when_recipe_missing(): void {
		$source = new \Outpost_F5TestSource_Stub( array( 'recipe' => null ) );
		$this->assertSame( array(), $source->recipe_for_url( 'https://example.com/' ) );
	}

	// --- default mode_for_url -----------------------------------------------

	public function test_mode_for_url_returns_unambiguous_mode(): void {
		$source = new \Outpost_F5TestSource_Stub(
			array(
				'ambiguity' => 'unambiguous',
				'mode'      => 'listen',
			)
		);
		$this->assertSame( 'listen', $source->mode_for_url( 'https://example.com/' ) );
	}

	public function test_mode_for_url_returns_default_for_ambiguous(): void {
		$source = new \Outpost_F5TestSource_Stub(
			array(
				'ambiguity'    => 'ambiguous',
				'mode'         => null,
				'mode_default' => 'reply',
			)
		);
		$this->assertSame( 'reply', $source->mode_for_url( 'https://example.com/' ) );
	}

	public function test_mode_for_url_falls_back_to_bookmark_when_unset(): void {
		$source = new \Outpost_F5TestSource_Stub(
			array(
				'ambiguity'    => 'ambiguous',
				'mode'         => null,
				'mode_default' => null,
			)
		);
		$this->assertSame( 'bookmark', $source->mode_for_url( 'https://example.com/' ) );
	}

	// --- default map_extracted ----------------------------------------------

	public function test_map_extracted_applies_simple_field_mapping(): void {
		$source = new \Outpost_F5TestSource_Stub(
			array(
				'mapping' => array(
					'title'         => 'p-name',
					'thumbnail_url' => 'u-photo',
				),
			)
		);
		$out = $source->map_extracted(
			array(
				'title'         => 'Track Name',
				'thumbnail_url' => 'https://example.com/thumb.jpg',
				'extra'         => 'ignored',
			),
			'https://example.com/track/abc'
		);
		$this->assertSame(
			array(
				'p-name'  => 'Track Name',
				'u-photo' => 'https://example.com/thumb.jpg',
			),
			$out
		);
	}

	public function test_map_extracted_substitutes_at_source_url(): void {
		$source = new \Outpost_F5TestSource_Stub(
			array(
				'mapping' => array(
					'@source_url' => 'u-listen-of',
				),
			)
		);
		$out = $source->map_extracted( array(), 'https://example.com/track/abc' );
		$this->assertSame( array( 'u-listen-of' => 'https://example.com/track/abc' ), $out );
	}

	public function test_map_extracted_substitutes_at_now_iso8601(): void {
		$source = new \Outpost_F5TestSource_Stub(
			array(
				'mapping' => array(
					'@now' => 'dt-published',
				),
			)
		);
		$out = $source->map_extracted( array(), 'https://example.com/' );
		$this->assertArrayHasKey( 'dt-published', $out );
		// Loose ISO 8601 sanity check.
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $out['dt-published'] );
	}

	public function test_map_extracted_drops_null_target(): void {
		$source = new \Outpost_F5TestSource_Stub(
			array(
				'mapping' => array(
					'title'   => 'p-name',
					'private' => null,
				),
			)
		);
		$out = $source->map_extracted(
			array(
				'title'   => 'A',
				'private' => 'should not be exported',
			),
			'https://example.com/'
		);
		$this->assertSame( array( 'p-name' => 'A' ), $out );
	}

	public function test_map_extracted_drops_missing_raw_keys(): void {
		$source = new \Outpost_F5TestSource_Stub(
			array(
				'mapping' => array(
					'title' => 'p-name',
					'photo' => 'u-photo',
				),
			)
		);
		$out = $source->map_extracted( array( 'title' => 'A' ), 'https://example.com/' );
		$this->assertSame( array( 'p-name' => 'A' ), $out );
	}
}
