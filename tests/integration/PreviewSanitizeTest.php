<?php
/**
 * Integration test: preview HTML sanitizer against REAL wp_kses.
 *
 * Item 8 — the regex blacklist that `Outpost_Preview_Endpoint::strip_dangerous_html()`
 * used was bypassable (`<svg/onload=…>`, `<body/onload=…>`, unquoted
 * `href=javascript:…`, `formaction=…`, mixed-case and encoded payloads survived).
 * It is now a `wp_kses` allowlist that keeps only the semantic + microformats
 * markup the Reply preview parser needs. These cases run against real WordPress
 * so the assertions reflect `wp_kses`'s actual behavior, and prove the returned
 * HTML is inert data.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class PreviewSanitizeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_insert_user' ) || ! function_exists( 'wp_kses' ) || ! class_exists( 'Outpost_Preview_Endpoint' ) ) {
			$this->markTestSkipped( 'Needs real WordPress (wp_kses). Run via `npm run test:integration`.' );
		}
	}

	private function sanitize( string $html ): string {
		$ref = new \ReflectionMethod( \Outpost_Preview_Endpoint::class, 'strip_dangerous_html' );
		return (string) $ref->invoke( null, $html );
	}

	/**
	 * @dataProvider hostile
	 */
	public function test_hostile_markup_is_neutralized( string $html, array $must_absent ): void {
		$out = strtolower( $this->sanitize( $html ) );
		foreach ( $must_absent as $needle ) {
			$this->assertStringNotContainsString( strtolower( $needle ), $out, "must strip: $needle" );
		}
	}

	/**
	 * @return array<string, array{0:string,1:string[]}>
	 */
	public function hostile(): array {
		return array(
			'script block + content' => array( '<p>ok</p><script>alert(1)</script>', array( '<script', 'alert(1)' ) ),
			'mixed-case script'      => array( '<ScRiPt>alert(1)</ScRiPt>', array( 'script', 'alert(1)' ) ),
			'svg onload'             => array( '<svg/onload=alert(1)>', array( 'svg', 'onload' ) ),
			'body onload'            => array( '<body/onload=alert(1)>x</body>', array( 'onload' ) ),
			'unquoted js href'       => array( '<a href=javascript:alert(1)>x</a>', array( 'javascript:' ) ),
			'quoted js href'         => array( '<a href="javascript:alert(1)">x</a>', array( 'javascript:' ) ),
			'button formaction'      => array( '<button formaction=javascript:alert(1)>go</button>', array( 'formaction', 'javascript:' ) ),
			'img onerror'            => array( '<img src=x onerror=alert(1)>', array( 'onerror' ) ),
			'iframe'                 => array( '<iframe src="https://evil.test"></iframe>', array( '<iframe' ) ),
			'object embed'           => array( '<object data="x"></object><embed src="y">', array( '<object', '<embed' ) ),
			'style block'            => array( '<style>body{background:url(javascript:1)}</style><p>ok</p>', array( '<style', 'javascript:' ) ),
			'data uri img'           => array( '<img src="data:text/html;base64,PHNjcmlwdD4=">', array( 'data:text/html' ) ),
			'onmouseover attr'       => array( '<div class="h-entry" onmouseover="steal()">x</div>', array( 'onmouseover' ) ),
			'encoded onerror'        => array( '<img src=x on&#101;rror=alert(1)>', array( 'onerror', 'alert(1)' ) ),
		);
	}

	public function test_preserves_title_for_client_extraction(): void {
		$out = $this->sanitize( '<html><head><title lang="en">Tom &amp; Jerry</title></head><body onload="x()"><p>hi</p></body></html>' );
		$this->assertStringContainsString( '<title', $out, 'The client extracts the page title from this.' );
		$this->assertStringContainsString( 'Tom', $out );
		$this->assertStringNotContainsString( 'onload', $out );
	}

	public function test_preserves_microformats_markup() : void {
		$html = '<div class="h-entry">'
			. '<a class="u-url p-name" href="https://example.test/post" rel="bookmark">A Post</a>'
			. '<time class="dt-published" datetime="2026-09-01">Sept 1</time>'
			. '<div class="e-content"><p>Body <strong>text</strong> and <em>emphasis</em>.</p></div>'
			. '</div>';
		$out = $this->sanitize( $html );

		$this->assertStringContainsString( 'class="h-entry"', $out );
		$this->assertStringContainsString( 'class="u-url p-name"', $out );
		$this->assertStringContainsString( 'href="https://example.test/post"', $out );
		$this->assertStringContainsString( 'datetime="2026-09-01"', $out );
		$this->assertStringContainsString( 'class="e-content"', $out );
		$this->assertStringContainsString( '<strong>text</strong>', $out );
	}

	public function test_safe_anchor_survives(): void {
		$out = $this->sanitize( '<a href="https://example.test" class="u-url">link</a>' );
		$this->assertStringContainsString( 'href="https://example.test"', $out );
		$this->assertStringContainsString( '>link</a>', $out );
	}
}
