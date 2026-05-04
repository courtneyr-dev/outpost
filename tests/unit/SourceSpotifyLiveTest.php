<?php
/**
 * Live test for Source_Spotify against real Spotify oEmbed (F8 Part B5).
 *
 * Runs only when `WP_TESTS_LIVE=1` is set (composer test:live target,
 * or directly with `WP_TESTS_LIVE=1 phpunit --group=live`). The
 * default `composer test` and CI exclude `@group live`, so this
 * test never makes a network request from CI.
 *
 * Purpose: quarterly verification that Spotify's oEmbed contract
 * hasn't drifted. If this fails, Spotify changed their oEmbed
 * response shape and `Outpost_Source_Spotify` may need updating
 * (capabilities mapping, extractor expectations, or both).
 *
 * Network independence: this file uses PHP-native HTTP
 * (`file_get_contents` with a stream context) rather than
 * `wp_safe_remote_get`, because the unit-test bootstrap doesn't load
 * WordPress core. The live test verifies the API contract; the
 * preview-endpoint integration (with SSRF defenses, content-type
 * validation, etc.) is verified by integration tests under wp-env.
 *
 * Override: set `OUTPOST_TEST_SPOTIFY_URL` to override the default
 * track URL. Useful when the durable default URL eventually goes away
 * — update the env var before updating the test source.
 *
 * @group live
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Spotify;
use Outpost_Source_Extractor_Oembed;
use PHPUnit\Framework\TestCase;

/**
 * @group live
 */
final class SourceSpotifyLiveTest extends TestCase {

	/**
	 * Default test URL — Daft Punk "One More Time" (2000), widely
	 * distributed and on Spotify since launch. Override via
	 * `OUTPOST_TEST_SPOTIFY_URL` env var if this resource ever moves.
	 */
	private const DEFAULT_TEST_URL = 'https://open.spotify.com/track/4iV5W9uYEdYUVa79Axb7Rh';

	private const FETCH_TIMEOUT_SECONDS = 10;

	public function setUp(): void {
		if ( '1' !== ( getenv( 'WP_TESTS_LIVE' ) ?: '' ) ) {
			$this->markTestSkipped(
				'Live tests skipped. Run `composer test:live` or set WP_TESTS_LIVE=1 to enable.'
			);
		}
	}

	public function test_live_oembed_returns_expected_contract(): void {
		$url       = $this->target_url();
		$source    = new Outpost_Source_Spotify();
		$ext       = new Outpost_Source_Extractor_Oembed();
		$fetch_url = $ext->compute_fetch_url( $url, $source->recipe_for_url( $url ) );

		$body = $this->fetch( $fetch_url );
		$this->assertNotEmpty( $body, 'Spotify oEmbed returned empty body for ' . $fetch_url );

		$decoded = $ext->parse( $body, $source->recipe_for_url( $url ) );

		// The contract Outpost depends on:
		$this->assertArrayHasKey( 'title', $decoded, 'Spotify oEmbed missing `title` field — contract drift' );
		$this->assertArrayHasKey( 'thumbnail_url', $decoded, 'Spotify oEmbed missing `thumbnail_url` — contract drift' );
		$this->assertArrayHasKey( 'provider_name', $decoded, 'Spotify oEmbed missing `provider_name` — contract drift' );
		$this->assertSame( 'Spotify', $decoded['provider_name'], 'Spotify oEmbed `provider_name` is no longer "Spotify" — contract drift' );

		$this->assertIsString( $decoded['title'] );
		$this->assertNotEmpty( $decoded['title'], 'Spotify oEmbed returned empty title for ' . $url );
		$this->assertIsString( $decoded['thumbnail_url'] );
		$this->assertStringStartsWith( 'https://', $decoded['thumbnail_url'], 'thumbnail_url should be HTTPS' );
	}

	public function test_live_mapping_produces_expected_h_entry_keys(): void {
		$url       = $this->target_url();
		$source    = new Outpost_Source_Spotify();
		$ext       = new Outpost_Source_Extractor_Oembed();
		$fetch_url = $ext->compute_fetch_url( $url, $source->recipe_for_url( $url ) );

		$body    = $this->fetch( $fetch_url );
		$decoded = $ext->parse( $body, $source->recipe_for_url( $url ) );
		$mapped  = $source->map_extracted( $decoded, $url );

		$this->assertArrayHasKey( 'p-name', $mapped );
		$this->assertArrayHasKey( 'u-photo', $mapped );
		$this->assertArrayHasKey( 'p-publication', $mapped );
		$this->assertArrayHasKey( 'u-listen-of', $mapped );
		$this->assertSame( $url, $mapped['u-listen-of'] );
		$this->assertSame( 'Spotify', $mapped['p-publication'] );
	}

	private function target_url(): string {
		$override = getenv( 'OUTPOST_TEST_SPOTIFY_URL' );
		return ( false !== $override && '' !== $override ) ? $override : self::DEFAULT_TEST_URL;
	}

	private function fetch( string $url ): string {
		$context = stream_context_create(
			array(
				'http' => array(
					'method'        => 'GET',
					'timeout'       => self::FETCH_TIMEOUT_SECONDS,
					'follow_location' => 1,
					'header'        => "User-Agent: outpost-test-suite/1.0 (Spotify oEmbed contract verification)\r\n",
				),
			)
		);
		$body = @file_get_contents( $url, false, $context );
		if ( false === $body ) {
			$this->markTestSkipped(
				'Live oEmbed fetch failed (network unavailable or Spotify blocked the request). URL: ' . $url
			);
		}
		return $body;
	}
}
