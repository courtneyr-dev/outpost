<?php
/**
 * Integration test stubs for Outpost_Manual_Share_Controller (F9).
 *
 * Documents the wp-env-pending end-to-end paths for the manual-share
 * REST surface. Skipped until wp-env is wired up; the docblocks spell
 * out the assertions the future session will fill in.
 *
 * Pipeline being verified:
 *
 *     POST /wp-json/outpost/v1/manual-share/intent
 *           ↓
 *     Outpost_Manual_Share_Controller::handle_request
 *           ↓
 *     200 { status: 'stub', message, platform_id, post_id }
 *
 *     GET /wp-json/outpost/v1/manual-share-chips?mode=photo
 *           ↓
 *     Outpost_Manual_Share_Controller::handle_chips_request
 *           ↓
 *     200 { mode_requested, mode_applied, chips: [10 manual-share platforms] }
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ManualShareIntegrationTest extends TestCase {

	/**
	 * @test
	 */
	public function intent_endpoint_returns_stub_response_for_known_platform(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Steps: log in as a test user; POST to ' .
			'/wp-json/outpost/v1/manual-share/intent with body ' .
			'{ post_id: <existing post id>, platform_id: "instagram-feed" }. ' .
			'Assert response is 200 JSON with status="stub", platform_id="instagram-feed", ' .
			'post_id matches request, message contains "F10".'
		);
	}

	/**
	 * @test
	 */
	public function intent_endpoint_rejects_unknown_platform_with_400(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST /manual-share/intent with platform_id="totally-fake". ' .
			'Assert response is 400 with error code "unknown_platform_id" and known_ids list ' .
			'containing all 10 default platforms.'
		);
	}

	/**
	 * @test
	 */
	public function intent_endpoint_rejects_unauthenticated_request(): void {
		$this->markTestSkipped(
			'wp-env setup pending. POST without auth (no cookie, no bearer). ' .
			'Assert 401 with error code "rest_forbidden".'
		);
	}

	/**
	 * @test
	 */
	public function chips_endpoint_for_photo_returns_10_default_platforms(): void {
		$this->markTestSkipped(
			'wp-env setup pending. GET /manual-share-chips?mode=photo. ' .
			'Assert response is 200 with chips array of length 10. Verify each chip ' .
			'has id, label, detected=true, manual_share key with icon/caption_via/' .
			'ios_strategy/android_pkg.'
		);
	}

	/**
	 * @test
	 */
	public function chips_endpoint_for_listen_returns_empty_array(): void {
		$this->markTestSkipped(
			'wp-env setup pending. GET /manual-share-chips?mode=listen. ' .
			'Assert chips array is empty (none of the 10 default platforms accept Listen).'
		);
	}

	/**
	 * @test
	 */
	public function chips_endpoint_unknown_mode_fails_open_to_all_chips(): void {
		$this->markTestSkipped(
			'wp-env setup pending. GET /manual-share-chips?mode=totally-fake. ' .
			'Assert mode_recognized=false, mode_applied=null, chips contains all 10 ' .
			'manual-share platforms.'
		);
	}

	/**
	 * @test
	 */
	public function chips_endpoint_does_not_include_activitypub_chip(): void {
		$this->markTestSkipped(
			'wp-env setup pending. With the ActivityPub plugin active, GET ' .
			'/manual-share-chips?mode=photo. Assert no chip in the response has ' .
			'id="activitypub" — the chips endpoint server-filters to entries with ' .
			'the manual_share extension key, so AP chips drop out.'
		);
	}

	/**
	 * @test
	 */
	public function chips_endpoint_hides_reddit_flickr_when_bridgy_filter_returns_true(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Hook outpost_manual_share_defer_to_bridgy to return true. ' .
			'GET /manual-share-chips?mode=photo. Assert chips array length is 8 (no reddit-manual, ' .
			'no flickr-manual). Other 8 platforms still present.'
		);
	}

	/**
	 * @test
	 */
	public function platforms_filter_can_register_custom_platform_for_chip_listing(): void {
		$this->markTestSkipped(
			'wp-env setup pending. Hook outpost_manual_share_platforms to append a ' .
			'custom config (id="custom-vsco", accepts_modes=["photo"], ...). GET ' .
			'/manual-share-chips?mode=photo. Assert custom-vsco appears in the chips array.'
		);
	}
}
