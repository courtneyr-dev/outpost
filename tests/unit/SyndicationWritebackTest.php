<?php
/**
 * Unit tests for Outpost_Manual_Share_Syndication_Writeback (F12).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Manual_Share_Syndication_Writeback;
use WP_Error;
use WP_Mock;

final class SyndicationWritebackTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $meta_store = array();

	public function setUp(): void {
		WP_Mock::setUp();
		$this->meta_store = array();
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn ( string $u ): string => $u );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static fn ( string $u ) => parse_url( $u )
		);
		WP_Mock::userFunction( 'wp_http_validate_url' )->andReturnUsing(
			static function ( string $url ) {
				$parts = parse_url( $url );
				if ( false === $parts || empty( $parts['host'] ) ) {
					return false;
				}
				$host = strtolower( (string) $parts['host'] );
				// Reject loopback + RFC 1918 + link-local.
				if (
					in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true )
					|| 1 === preg_match( '/^10\./', $host )
					|| 1 === preg_match( '/^192\.168\./', $host )
					|| 1 === preg_match( '/^169\.254\./', $host )
				) {
					return false;
				}
				return $url;
			}
		);
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

	// =====================================================================
	// validate_url
	// =====================================================================

	public function test_validate_accepts_https_url(): void {
		$result = Outpost_Manual_Share_Syndication_Writeback::validate_url(
			'https://example.com/posts/abc'
		);
		$this->assertTrue( $result );
	}

	public function test_validate_accepts_http_url(): void {
		$result = Outpost_Manual_Share_Syndication_Writeback::validate_url(
			'http://example.com/posts/abc'
		);
		$this->assertTrue( $result );
	}

	public function test_validate_rejects_empty_string(): void {
		$result = Outpost_Manual_Share_Syndication_Writeback::validate_url( '' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'empty_url', $result->get_error_code() );
	}

	public function test_validate_rejects_whitespace_only(): void {
		$result = Outpost_Manual_Share_Syndication_Writeback::validate_url( '   ' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'empty_url', $result->get_error_code() );
	}

	public function test_validate_rejects_javascript_scheme(): void {
		$result = Outpost_Manual_Share_Syndication_Writeback::validate_url(
			'javascript:alert(1)'
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		// May report as malformed_url (no host) OR invalid_scheme;
		// either is acceptable.
		$this->assertContains(
			$result->get_error_code(),
			array( 'malformed_url', 'invalid_scheme' )
		);
	}

	public function test_validate_rejects_file_scheme(): void {
		$result = Outpost_Manual_Share_Syndication_Writeback::validate_url(
			'file:///etc/passwd'
		);
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_validate_rejects_data_scheme(): void {
		$result = Outpost_Manual_Share_Syndication_Writeback::validate_url(
			'data:text/html,<script>alert(1)</script>'
		);
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_validate_rejects_malformed_url(): void {
		$result = Outpost_Manual_Share_Syndication_Writeback::validate_url(
			'not a url'
		);
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_validate_rejects_localhost(): void {
		$result = Outpost_Manual_Share_Syndication_Writeback::validate_url(
			'http://localhost/post'
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unsafe_url', $result->get_error_code() );
	}

	public function test_validate_rejects_rfc1918_host(): void {
		$result = Outpost_Manual_Share_Syndication_Writeback::validate_url(
			'http://192.168.1.1/admin'
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unsafe_url', $result->get_error_code() );
	}

	public function test_validate_rejects_url_too_long(): void {
		$long = 'https://example.com/' . str_repeat( 'a', 3000 );
		$result = Outpost_Manual_Share_Syndication_Writeback::validate_url( $long );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'url_too_long', $result->get_error_code() );
	}

	// =====================================================================
	// is_platform_mismatch
	// =====================================================================

	public function test_mismatch_false_for_exact_domain_match(): void {
		$this->assertFalse(
			Outpost_Manual_Share_Syndication_Writeback::is_platform_mismatch(
				'instagram-feed',
				'https://www.instagram.com/p/abc'
			)
		);
	}

	public function test_mismatch_false_for_subdomain_match(): void {
		$this->assertFalse(
			Outpost_Manual_Share_Syndication_Writeback::is_platform_mismatch(
				'x-twitter',
				'https://mobile.twitter.com/user/status/1'
			)
		);
	}

	public function test_mismatch_true_for_different_domain(): void {
		$this->assertTrue(
			Outpost_Manual_Share_Syndication_Writeback::is_platform_mismatch(
				'instagram-feed',
				'https://twitter.com/user/status/1'
			)
		);
	}

	public function test_mismatch_false_for_unknown_platform(): void {
		// Custom platform without an expected-domain entry — we don't
		// have a basis for warning.
		$this->assertFalse(
			Outpost_Manual_Share_Syndication_Writeback::is_platform_mismatch(
				'custom-vsco',
				'https://vsco.co/some-user/journal/abc'
			)
		);
	}

	public function test_mismatch_handles_threads_domain(): void {
		$this->assertFalse(
			Outpost_Manual_Share_Syndication_Writeback::is_platform_mismatch(
				'threads',
				'https://www.threads.net/@user/post/1'
			)
		);
	}

	// =====================================================================
	// add_or_update_link (idempotence + storage shape)
	// =====================================================================

	public function test_add_link_persists_full_entry_shape(): void {
		$result = Outpost_Manual_Share_Syndication_Writeback::add_or_update_link(
			42,
			'instagram-feed',
			'https://www.instagram.com/p/abc'
		);

		$this->assertCount( 1, $result );
		$entry = $result[0];
		$this->assertSame( 'instagram-feed', $entry['platform_id'] );
		$this->assertSame( 'https://www.instagram.com/p/abc', $entry['url'] );
		$this->assertSame( 'manual_share', $entry['source'] );
		$this->assertNotEmpty( $entry['added_at'] );
	}

	public function test_add_link_appends_distinct_platforms(): void {
		Outpost_Manual_Share_Syndication_Writeback::add_or_update_link(
			42,
			'instagram-feed',
			'https://www.instagram.com/p/abc'
		);
		$result = Outpost_Manual_Share_Syndication_Writeback::add_or_update_link(
			42,
			'facebook',
			'https://www.facebook.com/posts/1'
		);

		$this->assertCount( 2, $result );
	}

	public function test_add_same_pair_twice_does_not_duplicate(): void {
		Outpost_Manual_Share_Syndication_Writeback::add_or_update_link(
			42,
			'instagram-feed',
			'https://www.instagram.com/p/abc'
		);
		$result = Outpost_Manual_Share_Syndication_Writeback::add_or_update_link(
			42,
			'instagram-feed',
			'https://www.instagram.com/p/abc'
		);

		$this->assertCount( 1, $result );
	}

	public function test_add_same_url_different_platform_appends(): void {
		// User syndicated the same content to multiple platforms with
		// the same URL (rare but possible — e.g. cross-bridge).
		Outpost_Manual_Share_Syndication_Writeback::add_or_update_link(
			42,
			'instagram-feed',
			'https://example.com/p/abc'
		);
		$result = Outpost_Manual_Share_Syndication_Writeback::add_or_update_link(
			42,
			'instagram-stories',
			'https://example.com/p/abc'
		);

		$this->assertCount( 2, $result );
	}

	public function test_get_links_drops_malformed_entries(): void {
		$this->meta_store[42]['outpost_syndication_links'] = array(
			array(
				'platform_id' => 'instagram-feed',
				'url'         => 'https://example.com/p/abc',
				'added_at'    => '2026-05-04T00:00:00+00:00',
				'source'      => 'manual_share',
			),
			'not-an-array',
			array( 'platform_id' => 'no-url-key' ),
			array( 'url' => 'https://x.example/no-pid' ),
		);

		$links = Outpost_Manual_Share_Syndication_Writeback::get_links( 42 );
		$this->assertCount( 1, $links );
		$this->assertSame( 'instagram-feed', $links[0]['platform_id'] );
	}

	public function test_get_links_returns_empty_for_post_with_no_meta(): void {
		$this->assertSame(
			array(),
			Outpost_Manual_Share_Syndication_Writeback::get_links( 999 )
		);
	}
}
