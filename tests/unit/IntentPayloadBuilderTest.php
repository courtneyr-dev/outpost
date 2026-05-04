<?php
/**
 * Unit tests for Outpost_Manual_Share_Intent_Payload_Builder (F10).
 *
 * Verifies platform-config-driven behavior: each F9 platform's
 * Android intent payload includes the right strategy, fallback URL,
 * and EXTRA_TEXT presence/absence per the per-platform quirks
 * documented in F9 + F10.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Manual_Share_Intent_Payload_Builder;
use Outpost_Manual_Share_Platform_Config;
use Outpost_Manual_Share_Platform_Registry;
use Outpost_Manual_Share_Audit_Log;
use WP_Mock;

final class IntentPayloadBuilderTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $meta_store;

	private string $post_title    = 'My evening view';
	private string $post_content  = 'Watching the sunset over the bay';
	/** @var int[] Attachment IDs returned by get_attached_media('image', $post_id). */
	private array $attached_image_ids = array( 101, 102 );

	public function setUp(): void {
		WP_Mock::setUp();
		$this->meta_store         = array();
		$this->post_title         = 'My evening view';
		$this->post_content       = 'Watching the sunset over the bay';
		$this->attached_image_ids = array( 101, 102 );
		Outpost_Manual_Share_Platform_Registry::reset_for_tests();

		WP_Mock::userFunction( 'wp_generate_uuid4' )->andReturnUsing(
			static fn (): string => 'uuid-' . bin2hex( random_bytes( 4 ) )
		);
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn ( string $u ): string => $u );
		WP_Mock::userFunction( 'wp_strip_all_tags' )->andReturnUsing( static fn ( string $s ): string => strip_tags( $s ) );
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			function ( int $post_id, string $key, bool $single ) {
				return $this->meta_store[ $post_id ][ $key ] ?? '';
			}
		);
		WP_Mock::userFunction( 'update_post_meta' )->andReturnUsing(
			function ( int $post_id, string $key, $value ): bool {
				if ( ! isset( $this->meta_store[ $post_id ] ) ) {
					$this->meta_store[ $post_id ] = array();
				}
				$this->meta_store[ $post_id ][ $key ] = $value;
				return true;
			}
		);

		// Per-test data lives in class properties so individual tests
		// can override before invoking the SUT — without re-registering
		// WP_Mock function stubs mid-test (which is brittle).
		WP_Mock::userFunction( 'get_post' )->andReturnUsing( function ( int $post_id ) {
			return new \WP_Post( array(
				'ID'           => $post_id,
				'post_title'   => $this->post_title,
				'post_content' => $this->post_content,
			) );
		} );
		WP_Mock::userFunction( 'get_permalink' )->andReturnUsing(
			static fn ( int $post_id ): string => 'https://example.com/posts/' . $post_id
		);
		WP_Mock::userFunction( 'get_attached_media' )->andReturnUsing(
			function ( string $type, int $post_id ): array {
				if ( 'image' !== $type ) {
					return array();
				}
				return array_map(
					static fn ( int $id ): \WP_Post => new \WP_Post( array( 'ID' => $id ) ),
					$this->attached_image_ids
				);
			}
		);
		WP_Mock::userFunction( 'wp_get_attachment_url' )->andReturnUsing(
			static fn ( int $id ): string => 'https://example.com/img-' . $id . '.jpg'
		);
		WP_Mock::userFunction( 'get_post_mime_type' )->andReturn( 'image/jpeg' );
		// First call to _wp_attachment_image_alt for each attachment.
		// We just return a deterministic alt per ID.
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Manual_Share_Platform_Registry::reset_for_tests();
	}

	private function platform_config_for( string $id ): Outpost_Manual_Share_Platform_Config {
		foreach ( Outpost_Manual_Share_Platform_Registry::default_configs() as $config ) {
			if ( ( $config['id'] ?? '' ) === $id ) {
				return new Outpost_Manual_Share_Platform_Config( $config );
			}
		}
		throw new \RuntimeException( 'Platform not found in defaults: ' . $id );
	}

	// =====================================================================
	// Stub response (iOS / desktop)
	// =====================================================================

	public function test_stub_response_shape_matches_f9(): void {
		$stub = Outpost_Manual_Share_Intent_Payload_Builder::build_stub_response( 'instagram-feed', 42 );

		$this->assertSame( 'stub', $stub['status'] );
		$this->assertSame( 'instagram-feed', $stub['platform_id'] );
		$this->assertSame( 42, $stub['post_id'] );
		$this->assertStringContainsString( 'F11', $stub['message'] );
	}

	// =====================================================================
	// Android payload — basic shape
	// =====================================================================

	public function test_android_payload_includes_files_caption_and_clipboard(): void {
		WP_Mock::userFunction( '_wp_attachment_image_alt' )->andReturn( '' );
		// Stub alt-meta for both images.
		// (We added get_post_meta dispatch by falling through above;
		// alt comes via the same get_post_meta we already mocked.)
		$payload = Outpost_Manual_Share_Intent_Payload_Builder::build_for_android(
			$this->platform_config_for( 'instagram-feed' ),
			42
		);

		$this->assertSame( 'instagram-feed', $payload['platform'] );
		$this->assertSame( 'Instagram', $payload['platform_label'] );
		$this->assertCount( 2, $payload['files'] );
		$this->assertSame( 'My evening view', $payload['caption'] );
		$this->assertNotEmpty( $payload['audit_log_id'] );
		$this->assertSame( 'https://example.com/posts/42', $payload['source_url'] );
		$this->assertSame( 'prompt_for_silo_url', $payload['after_share'] );
	}

	public function test_android_payload_writes_audit_entry(): void {
		$payload = Outpost_Manual_Share_Intent_Payload_Builder::build_for_android(
			$this->platform_config_for( 'instagram-feed' ),
			42
		);

		$entries = Outpost_Manual_Share_Audit_Log::get_entries( 42 );
		$this->assertCount( 1, $entries );
		$this->assertSame( $payload['audit_log_id'], $entries[0]['id'] );
		$this->assertSame( 'instagram-feed', $entries[0]['platform_id'] );
		$this->assertSame( 'navigator_share', $entries[0]['strategy'] );
	}

	// =====================================================================
	// Per-platform quirks (config-driven)
	// =====================================================================

	public function test_facebook_intent_omits_extra_text(): void {
		// Facebook's android_extras config declares EXTRA_STREAM only —
		// no EXTRA_TEXT. The builder reads the config and includes only
		// declared keys, so the intent:// URL has no
		// android.intent.extra.TEXT. EXTRA_STREAM still maps through.
		$payload = Outpost_Manual_Share_Intent_Payload_Builder::build_for_android(
			$this->platform_config_for( 'facebook' ),
			42
		);

		$this->assertSame( 'navigator_share', $payload['intent_strategy'] );
		$this->assertStringNotContainsString( 'android.intent.extra.TEXT', $payload['fallback_url'] );
		$this->assertStringContainsString( 'android.intent.extra.STREAM', $payload['fallback_url'] );
		$this->assertStringContainsString( 'package=com.facebook.katana', $payload['fallback_url'] );
	}

	public function test_threads_intent_uses_barcelona_pkg_in_web_intent(): void {
		// Threads declares caption_via=web_intent + web_intent_url; the
		// strategy is 'intent_url' not 'navigator_share'. The fallback_url
		// is the threads.net web intent URL with caption substituted.
		$payload = Outpost_Manual_Share_Intent_Payload_Builder::build_for_android(
			$this->platform_config_for( 'threads' ),
			42
		);

		$this->assertSame( 'intent_url', $payload['intent_strategy'] );
		$this->assertStringContainsString( 'threads.net/intent/post', $payload['fallback_url'] );
		$this->assertStringContainsString( 'My%20evening%20view', $payload['fallback_url'] );
	}

	public function test_tiktok_intent_uses_zhiliaoapp_pkg(): void {
		$payload = Outpost_Manual_Share_Intent_Payload_Builder::build_for_android(
			$this->platform_config_for( 'tiktok' ),
			42
		);

		$this->assertSame( 'navigator_share', $payload['intent_strategy'] );
		$this->assertStringContainsString( 'package=com.zhiliaoapp.musically', $payload['fallback_url'] );
	}

	public function test_pinterest_uses_navigator_share_with_intent_url_fallback_on_android(): void {
		// Pinterest declares caption_via=intent + web_intent_url. The
		// web_intent_url is the iOS-only path (F11). On Android, strategy
		// is navigator_share + the intent:// fallback URL — Pinterest
		// honors EXTRA_TEXT on Android.
		$payload = Outpost_Manual_Share_Intent_Payload_Builder::build_for_android(
			$this->platform_config_for( 'pinterest' ),
			42
		);

		$this->assertSame( 'navigator_share', $payload['intent_strategy'] );
		$this->assertStringContainsString( 'package=com.pinterest', $payload['fallback_url'] );
		$this->assertStringContainsString( 'android.intent.extra.TEXT', $payload['fallback_url'] );
	}

	public function test_reddit_manual_uses_intent_url_strategy(): void {
		$payload = Outpost_Manual_Share_Intent_Payload_Builder::build_for_android(
			$this->platform_config_for( 'reddit-manual' ),
			42
		);

		$this->assertSame( 'intent_url', $payload['intent_strategy'] );
		$this->assertStringContainsString( 'reddit.com/submit', $payload['fallback_url'] );
		$this->assertStringContainsString( 'url=https%3A%2F%2Fexample.com%2Fposts%2F42', $payload['fallback_url'] );
	}

	public function test_x_twitter_uses_navigator_share_with_extra_text_extra(): void {
		// X honors EXTRA_TEXT on Android; android_extras includes it.
		// caption_via=intent, NOT web_intent — strategy is navigator_share
		// with the intent:// URL as fallback (which carries EXTRA_TEXT).
		$payload = Outpost_Manual_Share_Intent_Payload_Builder::build_for_android(
			$this->platform_config_for( 'x-twitter' ),
			42
		);

		$this->assertSame( 'navigator_share', $payload['intent_strategy'] );
		$this->assertStringContainsString( 'package=com.twitter.android', $payload['fallback_url'] );
		$this->assertStringContainsString( 'android.intent.extra.TEXT', $payload['fallback_url'] );
	}

	public function test_instagram_feed_intent_includes_extra_text(): void {
		// Instagram's config declares EXTRA_TEXT (which the app has
		// historically ignored, but the platform config still declares
		// it; the caveat string warns the user). The intent:// URL
		// faithfully reflects the config and expands EXTRA_TEXT to the
		// canonical android.intent.extra.TEXT name.
		$payload = Outpost_Manual_Share_Intent_Payload_Builder::build_for_android(
			$this->platform_config_for( 'instagram-feed' ),
			42
		);

		$this->assertStringContainsString( 'package=com.instagram.android', $payload['fallback_url'] );
		$this->assertStringContainsString( 'android.intent.extra.TEXT', $payload['fallback_url'] );
	}

	// =====================================================================
	// Caption + clipboard
	// =====================================================================

	public function test_clipboard_text_includes_caption_and_alt_text(): void {
		// Pre-populate alt-text meta for the two default attachments.
		$this->meta_store[101]['_wp_attachment_image_alt'] = 'Sunset over bay';
		$this->meta_store[102]['_wp_attachment_image_alt'] = 'Mountain silhouette';

		$payload = Outpost_Manual_Share_Intent_Payload_Builder::build_for_android(
			$this->platform_config_for( 'instagram-feed' ),
			42
		);

		$this->assertStringContainsString( 'My evening view', $payload['clipboard_text'] );
		$this->assertStringContainsString( 'Sunset over bay', $payload['clipboard_text'] );
		$this->assertStringContainsString( 'Mountain silhouette', $payload['clipboard_text'] );
	}

	public function test_long_caption_truncated_in_caption_field_but_full_in_clipboard(): void {
		$this->post_title   = str_repeat( 'A', 400 );
		$this->post_content = '';

		$payload = Outpost_Manual_Share_Intent_Payload_Builder::build_for_android(
			$this->platform_config_for( 'instagram-feed' ),
			42
		);

		// Caption truncated at 280 grapheme-equivalent chars + ellipsis.
		// strlen reports bytes; the multi-byte ellipsis (3 bytes in UTF-8)
		// pushes byte count above 280, but mb_strlen reflects 280 chars.
		$this->assertLessThanOrEqual( 280, mb_strlen( $payload['caption'] ) );
		$this->assertStringContainsString( '…', $payload['caption'] );
		// Clipboard preserves the full untruncated text.
		$this->assertGreaterThan( 280, mb_strlen( $payload['clipboard_text'] ) );
	}

	// =====================================================================
	// Gallery
	// =====================================================================

	public function test_gallery_post_with_four_images_returns_four_files(): void {
		$this->attached_image_ids = array( 201, 202, 203, 204 );

		$payload = Outpost_Manual_Share_Intent_Payload_Builder::build_for_android(
			$this->platform_config_for( 'instagram-feed' ),
			42
		);

		$this->assertCount( 4, $payload['files'] );
	}

	public function test_post_without_attachments_returns_empty_files_array(): void {
		$this->attached_image_ids = array();

		$payload = Outpost_Manual_Share_Intent_Payload_Builder::build_for_android(
			$this->platform_config_for( 'instagram-feed' ),
			42
		);

		$this->assertSame( array(), $payload['files'] );
	}
}
