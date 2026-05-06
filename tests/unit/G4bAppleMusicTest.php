<?php
/**
 * G4b — Apple Music composite-primitive demo unit tests.
 *
 * Exercises Outpost_Apple_Music_Adapter::parse_url_identity (pure
 * URL-parsing logic) without hitting Composite_Inbound's network paths.
 * The composite end-to-end test lives in the wp-env-pending integration
 * stub at G4bAppleMusicIntegrationTest.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Apple_Music_Adapter;
use WP_Mock\Tools\TestCase;

final class G4bAppleMusicTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		// `wp_parse_url` is in tests/bootstrap.php.
	}

	public function test_album_url_parses_to_album_identity(): void {
		$id = Outpost_Apple_Music_Adapter::parse_url_identity(
			'https://music.apple.com/us/album/some-album/1234567890'
		);
		$this->assertNotNull( $id );
		$this->assertSame( 'us', $id['country'] );
		$this->assertSame( 'album', $id['kind'] );
		$this->assertSame( '1234567890', $id['id'] );
	}

	public function test_song_url_parses_to_song_identity(): void {
		$id = Outpost_Apple_Music_Adapter::parse_url_identity(
			'https://music.apple.com/us/song/some-song/9876543210'
		);
		$this->assertNotNull( $id );
		$this->assertSame( 'song', $id['kind'] );
		$this->assertSame( '9876543210', $id['id'] );
	}

	public function test_album_url_with_track_query_upgrades_to_song(): void {
		$id = Outpost_Apple_Music_Adapter::parse_url_identity(
			'https://music.apple.com/us/album/some-album/1234567890?i=5555555555'
		);
		$this->assertNotNull( $id );
		$this->assertSame( 'song', $id['kind'] );
		$this->assertSame( '5555555555', $id['id'] );
	}

	public function test_country_code_lowercased(): void {
		$id = Outpost_Apple_Music_Adapter::parse_url_identity(
			'https://music.apple.com/GB/album/x/100'
		);
		$this->assertSame( 'gb', $id['country'] );
	}

	public function test_playlist_url_parses(): void {
		$id = Outpost_Apple_Music_Adapter::parse_url_identity(
			'https://music.apple.com/us/playlist/x/pl.123456'
		);
		// pl.* IDs are not pure-numeric, so the regex rejects them. This
		// matches the iTunes Lookup API which doesn't accept playlists.
		$this->assertNull( $id );
	}

	public function test_artist_url_does_not_match(): void {
		$id = Outpost_Apple_Music_Adapter::parse_url_identity(
			'https://music.apple.com/us/artist/some-artist/100'
		);
		// Artist URLs match the regex but iTunes Lookup doesn't enrich
		// them well; we accept here and let the enricher fall through.
		// Adjust this assertion if behavior changes.
		$this->assertNull( $id, 'artist URL should not match album/song/playlist' );
	}

	public function test_non_apple_music_host_rejected(): void {
		$this->assertNull( Outpost_Apple_Music_Adapter::parse_url_identity( 'https://example.com/album/x/100' ) );
		$this->assertNull( Outpost_Apple_Music_Adapter::parse_url_identity( 'https://itunes.apple.com/us/album/x/100' ) );
	}

	public function test_malformed_url_rejected(): void {
		$this->assertNull( Outpost_Apple_Music_Adapter::parse_url_identity( '' ) );
		$this->assertNull( Outpost_Apple_Music_Adapter::parse_url_identity( 'not a url' ) );
		$this->assertNull( Outpost_Apple_Music_Adapter::parse_url_identity( 'https://music.apple.com/' ) );
	}

	public function test_query_param_with_non_numeric_track_ignored(): void {
		$id = Outpost_Apple_Music_Adapter::parse_url_identity(
			'https://music.apple.com/us/album/x/100?i=abc'
		);
		// Falls through to album with original id.
		$this->assertNotNull( $id );
		$this->assertSame( 'album', $id['kind'] );
		$this->assertSame( '100', $id['id'] );
	}
}
