<?php
/**
 * Holds the CoreSanitizerMocks models to what WordPress 7.1 returned.
 *
 * Every expected value below was recorded on 2026-09-21 by running the real
 * function on a WordPress 7.1 / PHP 8.3 site. A model that drifts from core
 * lets a unit test pass while production breaks, so the models are checked
 * against the recording rather than trusted.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost\Tests\Helpers\CoreSanitizerMocks;
use PHPUnit\Framework\TestCase;

final class CoreSanitizerMocksTest extends TestCase {

	/**
	 * @return array<string, array{0:string, 1:string, 2:string}> input, sanitize_text_field(), wp_kses( , array() )
	 */
	public function recorded_core_outputs(): array {
		return array(
			'sentence with an encoded link' => array(
				'Check this out https://example.com/a%20b/caf%C3%A9?q=hello%20world&x=1',
				'Check this out https://example.com/ab/caf?q=helloworld&x=1',
				'Check this out https://example.com/a%20b/caf%C3%A9?q=hello%20world&amp;x=1',
			),
			'unclosed less-than'            => array(
				'I <3 this https://example.com/a%20b?x=1&y=2',
				'I &lt;3 this https://example.com/ab?x=1&amp;y=2',
				'I &lt;3 this https://example.com/a%20b?x=1&amp;y=2',
			),
			'quotes after an unclosed less-than' => array(
				'I <3 it\'s "great" https://example.com/it\'s?a=1&b=2',
				'I &lt;3 it&#039;s &quot;great&quot; https://example.com/it&#039;s?a=1&amp;b=2',
				'I &lt;3 it&#039;s &quot;great&quot; https://example.com/it&#039;s?a=1&amp;b=2',
			),
			'less-than followed by a space' => array(
				'5 < 6 so https://example.com/a%20b',
				'5 &lt; 6 so https://example.com/ab',
				'5 &lt; 6 so https://example.com/a%20b',
			),
			'tags and a script'             => array(
				'<b>bold</b> https://example.com/a%20b <script>alert(1)</script> tail',
				'bold https://example.com/ab tail',
				'bold https://example.com/a%20b alert(1) tail',
			),
			'control characters'            => array(
				"nul\x00esc\x1bbell\x07 line\r\nbreak\ttab https://example.com/a%20b",
				"nul\x00esc\x1bbell\x07 line break tab https://example.com/ab",
				"nulescbell line\r\nbreak\ttab https://example.com/a%20b",
			),
			'percent-encoded script tag'    => array(
				'%3Cscript%3Ealert(1)%3C/script%3E https://example.com/',
				'scriptalert(1)/script https://example.com/',
				'%3Cscript%3Ealert(1)%3C/script%3E https://example.com/',
			),
			'entity-encoded script tag'     => array(
				'&lt;script&gt;alert(1)&lt;/script&gt; https://example.com/a%20b',
				'&lt;script&gt;alert(1)&lt;/script&gt; https://example.com/ab',
				'&lt;script&gt;alert(1)&lt;/script&gt; https://example.com/a%20b',
			),
			'literal entities'              => array(
				'AT&amp;T &#039;quoted&#039; https://example.com/?a=1&amp;b=2',
				'AT&amp;T &#039;quoted&#039; https://example.com/?a=1&amp;b=2',
				'AT&amp;T &#039;quoted&#039; https://example.com/?a=1&amp;b=2',
			),
		);
	}

	/**
	 * @dataProvider recorded_core_outputs
	 */
	public function test_sanitize_text_field_model_matches_core( string $input, string $text_field, string $kses ): void {
		$this->assertSame( $text_field, CoreSanitizerMocks::sanitize_text_field( $input ) );
	}

	/**
	 * @dataProvider recorded_core_outputs
	 */
	public function test_wp_kses_model_matches_core( string $input, string $text_field, string $kses ): void {
		$this->assertSame( $kses, CoreSanitizerMocks::wp_kses( $input, array() ) );
	}

	public function test_invalid_utf8_matches_core(): void {
		$invalid = "bad \xC3\x28 seq https://example.com/a%20b";

		$this->assertSame( '', CoreSanitizerMocks::sanitize_text_field( $invalid ) );
		$this->assertSame( '', CoreSanitizerMocks::wp_check_invalid_utf8( $invalid ) );
		$this->assertSame( $invalid, CoreSanitizerMocks::wp_kses( $invalid, array() ), 'wp_kses() does not check encoding.' );
	}

	public function test_sanitize_textarea_field_keeps_newlines_but_still_deletes_octets(): void {
		$this->assertSame(
			"a\nb https://example.com/ab",
			CoreSanitizerMocks::sanitize_textarea_field( "a\nb https://example.com/a%20b" )
		);
	}

	public function test_non_string_input_matches_core(): void {
		$this->assertSame( '', CoreSanitizerMocks::sanitize_text_field( array( 'x' ) ) );
	}

	public function test_wp_kses_is_a_type_error_on_an_array_like_core(): void {
		$this->expectException( \TypeError::class );
		CoreSanitizerMocks::wp_kses( array( 'x' ), array() );
	}

	public function test_esc_url_raw_is_a_type_error_on_an_array_like_core(): void {
		$this->expectException( \TypeError::class );
		CoreSanitizerMocks::esc_url_raw( array( 'x' ) );
	}
}
