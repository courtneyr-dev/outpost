<?php
/**
 * Integration test stub for Outpost_Share_Target_Controller end-to-
 * end. Skipped until wp-env is wired up; documenting the assertions
 * so the future session knows what to flesh out.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ShareTargetDispatchTest extends TestCase {

	/**
	 * @test
	 */
	public function post_with_url_field_dispatches_and_303_redirects(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Steps for the future run: ' .
			'log in as a test user; POST multipart to /post/share-target ' .
			'with `url=https://example.com/page` field. Assert response ' .
			'is 303 with Location header pointing at /post/?picker=reply' .
			'&default=bookmark&source=unknown&url=... (Source_Unknown ' .
			'fallback as the only registered source).'
		);
	}

	/**
	 * @test
	 */
	public function post_unauthenticated_returns_401(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Assert: POST without bearer token ' .
			'or session cookie returns 401, no redirect.'
		);
	}

	/**
	 * @test
	 */
	public function post_with_text_only_redirects_to_note_mode(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Assert: POST with title="Note" ' .
			'text="A thought" and no url field redirects to ' .
			'/post/?mode=note&title=Note&text=A%20thought (composer ' .
			'opens to Note mode with body pre-filled, no source).'
		);
	}

	/**
	 * @test
	 */
	public function unambiguous_dispatch_writes_prefill_transient(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Steps: register a fake unambiguous ' .
			'source claiming example.com via outpost_sources_init. ' .
			'POST /post/share-target { url: https://example.com/track }. ' .
			'Assert get_transient( "outpost_prefill_{user_id}_{token}" ) ' .
			'returns the pending-status array within the same request.'
		);
	}

	/**
	 * @test
	 */
	public function localhost_url_dispatches_but_b2_blocks_fetch(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Layered SSRF defense: dispatch is ' .
			'pure URL → redirect, doesn\'t fetch. POST /post/share-target ' .
			'{ url: http://localhost/page }. Assert 303 redirect built. ' .
			'Then assert the eventual /preview fetch (the composer would ' .
			'make) returns a WP_Error fetch_failed because ' .
			'wp_safe_remote_get blocks loopback at the HTTP layer.'
		);
	}
}
