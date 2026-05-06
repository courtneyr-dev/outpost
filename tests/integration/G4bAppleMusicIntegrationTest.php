<?php
/**
 * G4b — integration test stub: Apple Music adapter end-to-end via
 * Composite_Inbound.
 *
 * Skipped until wp-env Docker network configuration with mock-server
 * routing lands.
 *
 * Test target: `test_apple_music_uses_composite_primitive`.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class G4bAppleMusicIntegrationTest extends TestCase {

	/**
	 * Full Apple Music composite flow: parse URL → primary OG fetch
	 * (succeeds) → enricher iTunes Lookup call (succeeds) → merged
	 * response carries both OG and iTunes fields.
	 *
	 * Steps for the future wp-env-backed run:
	 *   1. Bootstrap wp-env + mock server.
	 *   2. Configure mock at `https://music.apple.com/us/album/x/100` to
	 *      return an HTML page with og:title="Test Album" + og:image.
	 *   3. Configure mock at
	 *      `https://itunes.apple.com/lookup?id=100&country=us` to return
	 *      a JSON results array with collectionName, artworkUrl100,
	 *      primaryGenreName.
	 *   4. Call `Outpost_Apple_Music_Adapter::fetch( $album_url )`.
	 *   5. Assert response is array, not WP_Error.
	 *   6. Assert OG-side fields present: title === "Test Album", image
	 *      from og:image.
	 *   7. Assert iTunes enrichment fields present:
	 *      itunes_artwork_high_res ends with `1000x1000bb` (NOT 100x100bb),
	 *      itunes_collection_name === expected, itunes_genre === expected.
	 *   8. Assert `_composite_meta.primary` === 'apple_music_og'.
	 *   9. Assert `_composite_meta.sources.itunes_lookup.result` is array
	 *      (not WP_Error).
	 *
	 * @test
	 */
	public function apple_music_uses_composite_primitive(): void {
		$this->markTestSkipped(
			'wp-env setup with Docker mock-server routing lands in a later ' .
			'session. Integration assertions are documented in the test ' .
			'method body.'
		);
	}

	/**
	 * iTunes Lookup failure shouldn't break the response: primary OG
	 * succeeds, enricher returns 503, composite still returns the OG
	 * shape with no iTunes_* fields.
	 *
	 * @test
	 */
	public function apple_music_falls_back_to_og_only_when_itunes_fails(): void {
		$this->markTestSkipped( 'wp-env mock-server routing pending.' );
	}

	/**
	 * Album URL with `?i={track-id}` query upgrades to song lookup —
	 * iTunes Lookup hit URL carries the track-id, response shape is
	 * track-specific (collectionName + trackName both present).
	 *
	 * @test
	 */
	public function apple_music_album_url_with_track_query_upgrades_to_song_lookup(): void {
		$this->markTestSkipped( 'wp-env mock-server routing pending.' );
	}

	/**
	 * Non-Apple-Music URL rejected before any HTTP call. Asserts no
	 * mock server hits.
	 *
	 * @test
	 */
	public function apple_music_rejects_non_apple_music_urls_before_fetch(): void {
		$this->markTestSkipped( 'wp-env mock-server routing pending.' );
	}
}
