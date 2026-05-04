<?php
/**
 * Integration test stub for Outpost's Micropub photo write-shape
 * contract — runs without the ActivityPub plugin loaded.
 *
 * Purpose: even when nobody is reading `_wp_attachment_image_alt` (no
 * AP plugin, no other consumer), Outpost's bridge must still write it
 * correctly. This guards against future write-path refactors silently
 * breaking the AP contract.
 *
 * Skipped until wp-env (planned alongside RouteHandlerIntegrationTest)
 * is wired up.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class MicropubPhotoWriteShapeTest extends TestCase {

	/**
	 * Outpost bridge writes `_wp_attachment_image_alt` on every
	 * attachment whose URL appears in the photo property of the
	 * create request, regardless of whether AP is loaded.
	 *
	 * Steps for the future wp-env-backed run:
	 *   1. Bootstrap WP test env with the Micropub plugin active and
	 *      the ActivityPub plugin DEACTIVATED (or absent).
	 *   2. POST a multipart Micropub create with a photo file +
	 *      structured `{ value, alt }` photo property.
	 *   3. Assert the resulting attachment post has
	 *      `_wp_attachment_image_alt` set to the alt text.
	 *   4. Assert the resulting post_content contains canonical WP
	 *      gallery markup (the `[gallery]` shortcode the Micropub
	 *      plugin emits).
	 *   5. Re-run on a 4-image gallery; assert all 4 attachments have
	 *      their alt strings persisted in the canonical post-meta key.
	 *
	 * @test
	 */
	public function attachment_alt_text_persists_independent_of_activitypub(): void {
		$this->markTestSkipped(
			'wp-env setup lands in a later session. Outpost write-shape ' .
			'assertions are documented in the test method body.'
		);
	}

	/**
	 * Outpost bridge handles legacy parallel-array shape
	 * (`photo[]=...&mp-photo-alt[]=...`) as well as the structured
	 * shape. Older clients (and the existing Outpost client before
	 * F3's update) emit the parallel-array form.
	 *
	 * Steps:
	 *   1. POST a Micropub create using parallel-array shape with two
	 *      photo URLs and two parallel alt entries.
	 *   2. Assert each attachment carries the alt at the matching index.
	 *
	 * @test
	 */
	public function bridge_handles_parallel_array_shape_for_legacy_clients(): void {
		$this->markTestSkipped( 'wp-env setup pending; see class docblock.' );
	}
}
