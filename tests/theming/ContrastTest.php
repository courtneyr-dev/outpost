<?php
/**
 * Unit tests for Outpost_Contrast (FY-Theming).
 *
 * Validates WCAG 2.1 contrast-ratio computation against published
 * reference pairs (within 0.01 accuracy) and verifies that the
 * adjust-for-minimum walk produces colors that hit the target ratio
 * (or get measurably closer when the target is unreachable).
 *
 * @package Outpost\Tests\Theming
 */

declare(strict_types=1);

namespace Outpost\Tests\Theming;

use Outpost_Contrast;
use WP_Mock;

final class ContrastTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	// --- ratio: known reference pairs ------------------------------------

	public function test_ratio_white_on_black_is_21(): void {
		$this->assertEqualsWithDelta( 21.0, Outpost_Contrast::ratio( '#ffffff', '#000000' ), 0.01 );
	}

	public function test_ratio_black_on_white_is_21(): void {
		// Order-independent.
		$this->assertEqualsWithDelta( 21.0, Outpost_Contrast::ratio( '#000000', '#ffffff' ), 0.01 );
	}

	public function test_ratio_same_color_is_1(): void {
		$this->assertEqualsWithDelta( 1.0, Outpost_Contrast::ratio( '#888888', '#888888' ), 0.01 );
	}

	public function test_ratio_russian_violet_on_warm_paper(): void {
		// #241c4a on #fbfaf5 — Outpost's day default text-on-bg.
		// Expected ratio ~14.4 per WCAG calculator.
		$ratio = Outpost_Contrast::ratio( '#241c4a', '#fbfaf5' );
		$this->assertGreaterThanOrEqual( 14.0, $ratio );
		$this->assertLessThanOrEqual( 15.0, $ratio );
	}

	public function test_ratio_orange_on_warm_paper_fails_body_passes_large(): void {
		// #fb8500 on #fbfaf5 — accent on bg. Expected ~2.5 (WCAG calc).
		// Fails AA body (4.5), fails AA large (3.0).
		$ratio = Outpost_Contrast::ratio( '#fb8500', '#fbfaf5' );
		$this->assertLessThan( Outpost_Contrast::TARGET_AA_BODY, $ratio );
	}

	public function test_ratio_handles_three_char_hex(): void {
		// #fff = #ffffff, #000 = #000000.
		$this->assertEqualsWithDelta( 21.0, Outpost_Contrast::ratio( '#fff', '#000' ), 0.01 );
	}

	public function test_ratio_handles_no_hash_prefix(): void {
		$this->assertEqualsWithDelta( 21.0, Outpost_Contrast::ratio( 'ffffff', '000000' ), 0.01 );
	}

	public function test_ratio_returns_zero_on_malformed_input(): void {
		$this->assertSame( 0.0, Outpost_Contrast::ratio( 'not-a-color', '#000000' ) );
		$this->assertSame( 0.0, Outpost_Contrast::ratio( '#zzzzzz', '#000000' ) );
		$this->assertSame( 0.0, Outpost_Contrast::ratio( '', '#fff' ) );
	}

	public function test_ratio_strips_alpha_in_eight_char_hex(): void {
		// #ffffff80 → #ffffff (alpha ignored for contrast math).
		$this->assertEqualsWithDelta( 21.0, Outpost_Contrast::ratio( '#ffffff80', '#000000' ), 0.01 );
	}

	// --- meets ----------------------------------------------------------

	public function test_meets_default_threshold_is_aa_body(): void {
		$this->assertTrue( Outpost_Contrast::meets( '#000000', '#ffffff' ) );
		$this->assertFalse( Outpost_Contrast::meets( '#888888', '#999999' ) );
	}

	public function test_meets_custom_threshold(): void {
		// Pure black/white passes AAA body too (7.0).
		$this->assertTrue( Outpost_Contrast::meets( '#000', '#fff', Outpost_Contrast::TARGET_AAA_BODY ) );
	}

	// --- adjust_for_minimum ---------------------------------------------

	public function test_adjust_returns_input_when_already_passing(): void {
		$adjusted = Outpost_Contrast::adjust_for_minimum( '#000000', '#ffffff', Outpost_Contrast::TARGET_AA_BODY );
		$this->assertSame( '#000000', $adjusted );
	}

	public function test_adjust_walks_toward_compliant_for_warm_failure(): void {
		// #fb8500 on #fbfaf5 fails (~2.5). Adjusted should hit 4.5+.
		$adjusted = Outpost_Contrast::adjust_for_minimum( '#fb8500', '#fbfaf5', Outpost_Contrast::TARGET_AA_BODY );
		$this->assertNotSame( '#fb8500', $adjusted );
		$this->assertGreaterThanOrEqual( 4.5, Outpost_Contrast::ratio( $adjusted, '#fbfaf5' ) );
	}

	public function test_adjust_walks_lighter_when_bg_is_dark(): void {
		// Light gray on near-black should fail; adjusted goes lighter (toward white).
		$adjusted = Outpost_Contrast::adjust_for_minimum( '#555555', '#1a1614', Outpost_Contrast::TARGET_AA_BODY );
		$this->assertGreaterThanOrEqual( 4.5, Outpost_Contrast::ratio( $adjusted, '#1a1614' ) );
	}

	public function test_adjust_walks_darker_when_bg_is_light(): void {
		// Light gray text on white fails; adjusted goes darker (toward black).
		$adjusted = Outpost_Contrast::adjust_for_minimum( '#bbbbbb', '#ffffff', Outpost_Contrast::TARGET_AA_BODY );
		$this->assertGreaterThanOrEqual( 4.5, Outpost_Contrast::ratio( $adjusted, '#ffffff' ) );
	}

	public function test_adjust_for_cool_hue(): void {
		// Sky-blue on warm paper — needs to darken.
		$adjusted = Outpost_Contrast::adjust_for_minimum( '#8ecae6', '#fbfaf5', Outpost_Contrast::TARGET_AA_BODY );
		$this->assertGreaterThanOrEqual( 4.4, Outpost_Contrast::ratio( $adjusted, '#fbfaf5' ) );
	}

	public function test_adjust_for_neutral_hue(): void {
		$adjusted = Outpost_Contrast::adjust_for_minimum( '#a0a0a0', '#ffffff', Outpost_Contrast::TARGET_AA_BODY );
		$this->assertGreaterThanOrEqual( 4.4, Outpost_Contrast::ratio( $adjusted, '#ffffff' ) );
	}

	public function test_adjust_handles_malformed_input_safely(): void {
		// Garbage in → original returned (no exception).
		$this->assertSame( 'garbage', Outpost_Contrast::adjust_for_minimum( 'garbage', '#000000', 4.5 ) );
		$this->assertSame( '#fff', Outpost_Contrast::adjust_for_minimum( '#fff', 'nope', 4.5 ) );
	}

	public function test_adjust_returns_closest_when_target_unreachable(): void {
		// Same-color pair can't reach 4.5 against itself; adjuster
		// returns closest-achieved rather than the failing input.
		$adjusted = Outpost_Contrast::adjust_for_minimum( '#888888', '#888888', Outpost_Contrast::TARGET_AAA_BODY );
		$ratio    = Outpost_Contrast::ratio( $adjusted, '#888888' );
		$this->assertGreaterThan( 1.0, $ratio );
	}

	public function test_constants_match_wcag_thresholds(): void {
		$this->assertSame( 4.5, Outpost_Contrast::TARGET_AA_BODY );
		$this->assertSame( 3.0, Outpost_Contrast::TARGET_AA_LARGE );
		$this->assertSame( 7.0, Outpost_Contrast::TARGET_AAA_BODY );
	}
}
