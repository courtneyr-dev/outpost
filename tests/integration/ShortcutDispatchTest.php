<?php
/**
 * Integration test stub for Outpost_Shortcut_Controller end-to-end.
 * The iOS Shortcut bridge is the only path to inbound share dispatch
 * on iOS (Web Share Target API never landed in iOS Safari per WebKit
 * bug 194593).
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ShortcutDispatchTest extends TestCase {

	/**
	 * @test
	 */
	public function post_json_with_url_dispatches_and_303_redirects(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Steps: POST /post/shortcut with ' .
			'Content-Type: application/json and body { "url": ' .
			'"https://example.com/article" }. Authenticated via ' .
			'Authorization: Bearer <token>. Assert 303 redirect to ' .
			'/post/?picker=... matching Source_Unknown ambiguous fallback.'
		);
	}

	/**
	 * @test
	 */
	public function get_method_returns_405(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Assert: GET /post/shortcut returns 405.'
		);
	}

	/**
	 * @test
	 */
	public function unauthenticated_returns_401(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Assert: POST without Bearer token ' .
			'returns 401.'
		);
	}

	/**
	 * @test
	 */
	public function malformed_json_body_returns_400(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Assert: POST with non-JSON body ' .
			'returns 400.'
		);
	}

	/**
	 * @test
	 */
	public function shared_text_field_routes_through_text_extraction(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Assert: POST /post/shortcut { ' .
			'"shared_text": "Read this https://example.com/x" } ' .
			'extracts the URL from the shared_text field and dispatches.'
		);
	}
}
