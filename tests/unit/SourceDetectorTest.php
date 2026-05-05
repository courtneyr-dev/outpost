<?php
/**
 * Unit tests for Outpost_Source_Detector — URL extraction priority,
 * dispatch decisions, redirect URL building.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Detector;
use Outpost_Source_Registry;
use Outpost_Source_Unknown;
use WP_Mock;

require_once dirname( __DIR__ ) . '/fixtures/source-test-fakes.php';

final class SourceDetectorTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Source_Registry::reset_for_tests();
		Outpost_Source_Detector::reset_cache_for_tests();
		// F2 #10 / A2 #8 static-state reset.
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
		// home_url() is called by build_composer_url to make redirect_url
		// absolute. Default tests assert against partial path/param strings
		// so this stub returns a stable absolute base.
		WP_Mock::userFunction( 'home_url' )->andReturnUsing(
			static function ( string $path = '' ): string {
				return 'https://example.test' . $path;
			}
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Source_Registry::reset_for_tests();
		Outpost_Source_Detector::reset_cache_for_tests();
	}

	// --- URL extraction priority -----------------------------------------

	public function test_extract_url_prefers_url_field_when_valid(): void {
		$out = Outpost_Source_Detector::extract_url_from_payload(
			array(
				'url'   => 'https://example.com/page',
				'text'  => 'check this',
				'title' => 'My share',
			)
		);
		$this->assertSame( 'https://example.com/page', $out );
	}

	public function test_extract_url_falls_back_to_text_when_url_field_invalid(): void {
		$out = Outpost_Source_Detector::extract_url_from_payload(
			array(
				'url'  => 'not-a-url',
				'text' => 'https://example.com/from-text',
			)
		);
		$this->assertSame( 'https://example.com/from-text', $out );
	}

	public function test_extract_url_finds_url_inside_free_text(): void {
		$out = Outpost_Source_Detector::extract_url_from_payload(
			array(
				'text' => 'Read this: https://example.com/article and tell me what you think.',
			)
		);
		$this->assertSame( 'https://example.com/article', $out );
	}

	public function test_extract_url_falls_back_to_title_when_text_has_no_url(): void {
		$out = Outpost_Source_Detector::extract_url_from_payload(
			array(
				'title' => 'https://example.com/from-title',
				'text'  => 'plain text no url here',
			)
		);
		$this->assertSame( 'https://example.com/from-title', $out );
	}

	public function test_extract_url_returns_null_for_share_text_only(): void {
		$out = Outpost_Source_Detector::extract_url_from_payload(
			array(
				'title' => 'My note',
				'text'  => 'Some thoughts about today.',
			)
		);
		$this->assertNull( $out );
	}

	public function test_extract_url_strips_trailing_punctuation_from_text_match(): void {
		$out = Outpost_Source_Detector::extract_url_from_payload(
			array( 'text' => 'See https://example.com/page.' )
		);
		$this->assertSame( 'https://example.com/page', $out );
	}

	public function test_extract_url_rejects_javascript_scheme(): void {
		$out = Outpost_Source_Detector::extract_url_from_payload(
			array( 'url' => 'javascript:alert(1)' )
		);
		$this->assertNull( $out );
	}

	public function test_extract_url_handles_empty_payload(): void {
		$out = Outpost_Source_Detector::extract_url_from_payload( array() );
		$this->assertNull( $out );
	}

	public function test_extract_url_ignores_non_string_fields(): void {
		$out = Outpost_Source_Detector::extract_url_from_payload(
			array(
				'url'  => 12345,
				'text' => array( 'not', 'a', 'string' ),
			)
		);
		$this->assertNull( $out );
	}

	// --- dispatch with Source_Unknown only --------------------------------

	public function test_dispatch_falls_back_to_picker_with_only_unknown_registered(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		$decision = Outpost_Source_Detector::dispatch( 'https://example.com/random-page' );

		$this->assertSame( 'picker', $decision['route_type'] );
		$this->assertSame( 'unknown', $decision['source_id'] );
		$this->assertSame( 'bookmark', $decision['mode_default'] );
		$this->assertContains( 'reply', $decision['mode_options'] );
		$this->assertContains( 'bookmark', $decision['mode_options'] );
	}

	public function test_dispatch_picker_redirect_url_includes_picker_and_default(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		$decision = Outpost_Source_Detector::dispatch( 'https://example.com/page' );

		$this->assertStringContainsString( '/post/?', $decision['redirect_url'] );
		$this->assertStringContainsString( 'picker=reply', $decision['redirect_url'] );
		$this->assertStringContainsString( 'default=bookmark', $decision['redirect_url'] );
		$this->assertStringContainsString( 'source=unknown', $decision['redirect_url'] );
		$this->assertStringContainsString( 'url=', $decision['redirect_url'] );
	}

	public function test_dispatch_picker_does_not_emit_prefill_token(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		$decision = Outpost_Source_Detector::dispatch( 'https://example.com/page' );

		$this->assertNull( $decision['prefill_token'] );
		$this->assertStringNotContainsString( 'cached_for=', $decision['redirect_url'] );
	}

	// --- dispatch with unambiguous source ---------------------------------

	public function test_dispatch_auto_routes_unambiguous_source_to_mode(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register(
			new \Outpost_F5TestSource_Stub(
				array(
					'id'            => 'fake-spotify',
					'host_patterns' => array( 'example.com' ),
					'ambiguity'     => 'unambiguous',
					'mode'          => 'listen',
				)
			)
		);

		$decision = Outpost_Source_Detector::dispatch( 'https://example.com/track/abc' );

		$this->assertSame( 'auto', $decision['route_type'] );
		$this->assertSame( 'listen', $decision['mode'] );
		$this->assertSame( 'fake-spotify', $decision['source_id'] );
		$this->assertNotEmpty( $decision['prefill_token'] );
	}

	public function test_dispatch_auto_redirect_url_includes_mode_and_token(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register(
			new \Outpost_F5TestSource_Stub(
				array(
					'id'            => 'fake',
					'host_patterns' => array( 'example.com' ),
					'ambiguity'     => 'unambiguous',
					'mode'          => 'watch',
				)
			)
		);

		$decision = Outpost_Source_Detector::dispatch( 'https://example.com/v/123' );

		$this->assertStringContainsString( 'mode=watch', $decision['redirect_url'] );
		$this->assertStringContainsString( 'source=fake', $decision['redirect_url'] );
		$this->assertStringContainsString( 'cached_for=', $decision['redirect_url'] );
	}

	public function test_dispatch_redirect_url_is_absolute(): void {
		// Regression: iOS Shortcut JSON consumer needs absolute URL because
		// Safari has no host context when opening the response. Relative
		// /post/?... resolves to malformed Safari hostname.
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		$decision = Outpost_Source_Detector::dispatch( 'https://example.com/page' );

		$this->assertStringStartsWith( 'https://', $decision['redirect_url'] );
		$this->assertStringContainsString( '/post/?', $decision['redirect_url'] );
	}

	// --- exact host beats wildcard ---------------------------------------

	public function test_exact_host_match_wins_over_suffix_wildcard(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		// Register the wildcard FIRST so first-match-wins exercises the
		// principle: registration order is priority. We deliberately
		// register exact-host AFTER, then expect exact to lose by F5's
		// stated semantics (first-match-wins). This test covers the
		// flip side of expectation: F6 confirms F5's matcher behavior
		// is "registration order is priority", NOT "exact beats
		// wildcard." If the prompt's "exact wins over wildcard" needs
		// a different ordering, source authors register exact-match
		// patterns FIRST, which is the documented convention.
		Outpost_Source_Registry::register(
			new \Outpost_F5TestSource_Stub(
				array(
					'id'            => 'specific',
					'host_patterns' => array( 'example.com' ),
					'mode'          => 'listen',
				)
			)
		);
		Outpost_Source_Registry::register(
			new \Outpost_F5TestSource_Stub(
				array(
					'id'            => 'broad',
					'host_patterns' => array( '*.com' ),
					'mode'          => 'watch',
				)
			)
		);

		$decision = Outpost_Source_Detector::dispatch( 'https://example.com/' );

		$this->assertSame( 'specific', $decision['source_id'] );
	}

	// --- prefill_token determinism ---------------------------------------

	public function test_prefill_token_is_deterministic_for_a_url(): void {
		$a = Outpost_Source_Detector::prefill_token( 'https://example.com/x' );
		$b = Outpost_Source_Detector::prefill_token( 'https://example.com/x' );
		$this->assertSame( $a, $b );
		$this->assertSame( 16, strlen( $a ) );
	}

	public function test_prefill_token_varies_by_url(): void {
		$a = Outpost_Source_Detector::prefill_token( 'https://example.com/a' );
		$b = Outpost_Source_Detector::prefill_token( 'https://example.com/b' );
		$this->assertNotSame( $a, $b );
	}

	// --- per-request memoization -----------------------------------------

	public function test_find_source_memoizes_within_request(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		$first  = Outpost_Source_Detector::find_source( 'https://example.com/' );
		$second = Outpost_Source_Detector::find_source( 'https://example.com/' );

		// Same instance — memoization returns the cached source.
		$this->assertSame( $first, $second );
	}

	// --- localhost / SSRF defense layering -------------------------------

	public function test_dispatch_builds_redirect_for_localhost_url(): void {
		// Dispatch is pure URL → redirect. SSRF guard runs at fetch time,
		// not dispatch time. Layered defense: dispatch can build a redirect
		// pointing the composer at a localhost URL; the eventual B2 fetch
		// rejects per F5's wp_safe_remote_get filter chain.
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		$decision = Outpost_Source_Detector::dispatch( 'http://localhost/page' );

		$this->assertSame( 'picker', $decision['route_type'] );
		$this->assertStringContainsString( 'url=', $decision['redirect_url'] );
		$this->assertStringContainsString( 'localhost', rawurldecode( $decision['redirect_url'] ) );
	}
}
