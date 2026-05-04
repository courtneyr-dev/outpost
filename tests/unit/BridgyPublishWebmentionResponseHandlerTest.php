<?php
/**
 * Unit tests for Outpost_Bridgy_Publish_Webmention_Response_Handler (F14).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Bridgy_Publish_Webmention_Response_Handler;
use Outpost_Bridgy_Publish_Log;
use Outpost_Manual_Share_Syndication_Writeback;
use WP_Mock;

final class BridgyPublishWebmentionResponseHandlerTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $meta_store = array();

	public function setUp(): void {
		WP_Mock::setUp();
		$this->meta_store = array();
		WP_Mock::userFunction( 'wp_generate_uuid4' )->andReturnUsing(
			static fn (): string => 'uuid-' . bin2hex( random_bytes( 4 ) )
		);
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn ( string $u ): string => $u );
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static fn ( string $s ): string => preg_replace( '/[^a-z0-9_]/', '', strtolower( $s ) ) );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn ( string $s ): string => trim( $s ) );
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

	private function seed_pending( int $post_id, string $silo_chip_id, string $silo_id ): array {
		return Outpost_Bridgy_Publish_Log::add_entry( $post_id, $silo_chip_id, $silo_id );
	}

	// =====================================================================
	// Success path
	// =====================================================================

	public function test_success_with_json_url_field_writes_silo_url(): void {
		$entry = $this->seed_pending( 42, 'bridgy-mastodon', 'mastodon' );
		$body  = wp_json_encode( array( 'url' => 'https://mastodon.example/@user/123' ) );

		$ok = Outpost_Bridgy_Publish_Webmention_Response_Handler::process_response(
			42,
			$entry['id'],
			201,
			(string) $body
		);

		$this->assertTrue( $ok );
		$entries = Outpost_Bridgy_Publish_Log::get_entries( 42 );
		$this->assertSame( 'success', $entries[0]['outcome'] );
		$this->assertSame( 'https://mastodon.example/@user/123', $entries[0]['silo_url'] );
		$this->assertNotNull( $entries[0]['completed_at'] );

		// F12 syndication_links updated.
		$links = Outpost_Manual_Share_Syndication_Writeback::get_links( 42 );
		$this->assertCount( 1, $links );
		$this->assertSame( 'bridgy-mastodon', $links[0]['platform_id'] );
		$this->assertSame( 'https://mastodon.example/@user/123', $links[0]['url'] );
	}

	public function test_success_with_silo_url_field_alternative(): void {
		$entry = $this->seed_pending( 42, 'bridgy-bluesky', 'bluesky' );
		$body  = wp_json_encode( array( 'silo_url' => 'https://bsky.example/post/abc' ) );

		Outpost_Bridgy_Publish_Webmention_Response_Handler::process_response(
			42,
			$entry['id'],
			201,
			(string) $body
		);

		$entries = Outpost_Bridgy_Publish_Log::get_entries( 42 );
		$this->assertSame( 'https://bsky.example/post/abc', $entries[0]['silo_url'] );
	}

	public function test_success_with_plain_text_url_body_works(): void {
		$entry = $this->seed_pending( 42, 'bridgy-flickr', 'flickr' );

		Outpost_Bridgy_Publish_Webmention_Response_Handler::process_response(
			42,
			$entry['id'],
			200,
			'https://flickr.example/photos/abc'
		);

		$entries = Outpost_Bridgy_Publish_Log::get_entries( 42 );
		$this->assertSame( 'success', $entries[0]['outcome'] );
		$this->assertSame( 'https://flickr.example/photos/abc', $entries[0]['silo_url'] );
	}

	public function test_2xx_with_no_silo_url_marks_failure(): void {
		// Defensive case: Bridgy returns 200 but body has no URL.
		$entry = $this->seed_pending( 42, 'bridgy-mastodon', 'mastodon' );

		Outpost_Bridgy_Publish_Webmention_Response_Handler::process_response(
			42,
			$entry['id'],
			200,
			wp_json_encode( array( 'message' => 'no url in here' ) )
		);

		$entries = Outpost_Bridgy_Publish_Log::get_entries( 42 );
		$this->assertSame( 'failure', $entries[0]['outcome'] );
		$this->assertSame( 'no_silo_url', $entries[0]['error_code'] );
	}

	// =====================================================================
	// Failure paths
	// =====================================================================

	public function test_4xx_with_json_error_records_code_and_message(): void {
		$entry = $this->seed_pending( 42, 'bridgy-github', 'github' );
		$body  = wp_json_encode( array(
			'error'   => 'auth_required',
			'message' => 'Reauthorize at brid.gy.',
		) );

		Outpost_Bridgy_Publish_Webmention_Response_Handler::process_response(
			42,
			$entry['id'],
			401,
			(string) $body
		);

		$entries = Outpost_Bridgy_Publish_Log::get_entries( 42 );
		$this->assertSame( 'failure', $entries[0]['outcome'] );
		$this->assertSame( 'auth_required', $entries[0]['error_code'] );
		$this->assertSame( 'Reauthorize at brid.gy.', $entries[0]['error_message'] );
	}

	public function test_5xx_uses_http_status_as_error_code_fallback(): void {
		$entry = $this->seed_pending( 42, 'bridgy-mastodon', 'mastodon' );

		Outpost_Bridgy_Publish_Webmention_Response_Handler::process_response(
			42,
			$entry['id'],
			503,
			'Service unavailable'
		);

		$entries = Outpost_Bridgy_Publish_Log::get_entries( 42 );
		$this->assertSame( 'failure', $entries[0]['outcome'] );
		$this->assertSame( 'http_503', $entries[0]['error_code'] );
		$this->assertSame( 'Service unavailable', $entries[0]['error_message'] );
	}

	public function test_failure_does_not_write_to_syndication_links(): void {
		$entry = $this->seed_pending( 42, 'bridgy-reddit', 'reddit' );

		Outpost_Bridgy_Publish_Webmention_Response_Handler::process_response(
			42,
			$entry['id'],
			500,
			'whatever'
		);

		$links = Outpost_Manual_Share_Syndication_Writeback::get_links( 42 );
		$this->assertSame( array(), $links );
	}

	public function test_unknown_audit_log_id_returns_false(): void {
		$ok = Outpost_Bridgy_Publish_Webmention_Response_Handler::process_response(
			42,
			'totally-fake-id',
			201,
			'https://mastodon.example/abc'
		);

		$this->assertFalse( $ok );
	}

	// =====================================================================
	// URL sanitization
	// =====================================================================

	public function test_javascript_scheme_silo_url_drops_to_failure(): void {
		$entry = $this->seed_pending( 42, 'bridgy-mastodon', 'mastodon' );
		$body  = wp_json_encode( array( 'url' => 'javascript:alert(1)' ) );

		Outpost_Bridgy_Publish_Webmention_Response_Handler::process_response(
			42,
			$entry['id'],
			201,
			(string) $body
		);

		// URL rejected → no silo URL extracted → defensive failure.
		$entries = Outpost_Bridgy_Publish_Log::get_entries( 42 );
		$this->assertSame( 'failure', $entries[0]['outcome'] );
		$this->assertSame( 'no_silo_url', $entries[0]['error_code'] );
	}

	public function test_long_error_message_truncated(): void {
		$entry        = $this->seed_pending( 42, 'bridgy-mastodon', 'mastodon' );
		$long_message = str_repeat( 'A', 1000 );

		Outpost_Bridgy_Publish_Webmention_Response_Handler::process_response(
			42,
			$entry['id'],
			500,
			$long_message
		);

		$entries = Outpost_Bridgy_Publish_Log::get_entries( 42 );
		$this->assertLessThanOrEqual( 510, strlen( $entries[0]['error_message'] ) );
	}
}
