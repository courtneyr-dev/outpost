<?php
/**
 * Integration test stub for end-to-end image alt-text passthrough
 * (Outpost → Micropub plugin → WP attachments → ActivityPub plugin
 * transformer).
 *
 * Skipped until wp-env (planned alongside RouteHandlerIntegrationTest)
 * is wired up. Documenting the assertions here so the next session
 * knows exactly what to flesh out.
 *
 * Investigation findings (F3, captured here so future sessions don't
 * re-derive them):
 *
 * 1. The ActivityPub plugin's `Activitypub\Transformer\Post::transform`
 *    parses post_content for `<img>` tags after running `do_shortcode()`
 *    on the content. For each img, it calls `attachment_url_to_postid()`
 *    to resolve the attachment, then reads
 *    `_wp_attachment_image_alt` post-meta to populate the AP
 *    attachment[].name field. (Source: Automattic/wordpress-activitypub
 *    `includes/transformer/class-base.php` and `class-post.php`.)
 *
 * 2. The Micropub plugin (indieweb/wordpress-micropub) emits a
 *    `[gallery size=full columns=1]` shortcode in post_content for
 *    Photo / Gallery posts — NOT plain `<img>` tags or
 *    `core/image` blocks. The AP plugin's `do_shortcode()` call renders
 *    the gallery, producing img tags that AP then parses.
 *
 * 3. The Micropub plugin recognizes structured photo entries
 *    `{ value, alt }` per the Micropub JSON syntax but writes the alt
 *    to attachment `post_title` (via `media_sideload_url`'s third arg
 *    being `$title`), NOT to `_wp_attachment_image_alt`. It also does
 *    not honor the `mp-photo-alt` parallel-array convention.
 *
 * 4. So without an Outpost-side bridge, every Outpost-originated Photo
 *    or Gallery post syndicates with empty image alt text via
 *    ActivityPub. Outpost ships the
 *    `Outpost_Micropub_Bridges::apply_photo_alt_text` bridge as the
 *    fix; this integration test proves the chain holds end-to-end.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class CompanionActivityPubPassthroughTest extends TestCase {

	/**
	 * Single-photo passthrough: Outpost client → Micropub plugin
	 * upload → attachment post → Outpost bridge writes alt → AP
	 * transformer reads alt → AP object's attachment[0].name matches.
	 *
	 * Steps for the future wp-env-backed run:
	 *   1. Bootstrap WP test env with both Micropub and ActivityPub
	 *      plugins active.
	 *   2. POST a multipart Micropub create with a photo file and
	 *      structured `{ value, alt }` photo property carrying alt
	 *      text "A red apple on a wooden table".
	 *   3. Assert the response has a Location header and the post was
	 *      created.
	 *   4. Assert the resulting attachment post has
	 *      `_wp_attachment_image_alt` = "A red apple on a wooden table"
	 *      (not just `post_title`).
	 *   5. Assert the post_content contains the gallery shortcode.
	 *   6. Run `\Activitypub\Transformer\Post::transform( $post )` and
	 *      assert the resulting AP object's
	 *      `attachment[0]['name']` equals the alt text.
	 *
	 * @test
	 */
	public function single_photo_alt_text_passes_through_to_ap_attachment_name(): void {
		$this->markTestSkipped(
			'wp-env setup lands in a later session. Integration assertions ' .
			'are documented in the class docblock and the test method bodies.'
		);
	}

	/**
	 * Four-image gallery: every image's alt text appears at the
	 * matching position in AP's attachment array.
	 *
	 * Steps:
	 *   1. POST a Micropub create with photo array of 4 distinct alt
	 *      strings ("First", "Second", "Third", "Fourth").
	 *   2. Assert 4 attachment posts exist, each with its own
	 *      _wp_attachment_image_alt.
	 *   3. Run AP transformer; assert attachment array length is 4 and
	 *      attachment[i].name == alts[i] for each i.
	 *
	 * @test
	 */
	public function four_image_gallery_passes_through_with_correct_alt_per_image(): void {
		$this->markTestSkipped( 'wp-env setup pending; see class docblock.' );
	}

	/**
	 * No-alt-text submission: AP attachment.name is empty string, NOT
	 * a missing field. The AP spec accepts empty strings, but missing
	 * fields are an accessibility regression because some downstream
	 * AP consumers default to the URL or filename when name is missing.
	 *
	 * Steps:
	 *   1. POST a Micropub create with a photo and no alt property.
	 *   2. Assert the attachment's _wp_attachment_image_alt is set to
	 *      empty string (the bridge persists empty rather than
	 *      skipping).
	 *   3. Run AP transformer; assert attachment[0]['name'] === "".
	 *
	 * @test
	 */
	public function missing_alt_text_persists_as_empty_string(): void {
		$this->markTestSkipped( 'wp-env setup pending; see class docblock.' );
	}

	/**
	 * Bridge-not-loaded regression check: with the Outpost plugin
	 * deactivated but Micropub + AP active, alt text DOES drop on the
	 * floor. Documents the upstream bug Outpost is currently working
	 * around. If/when the upstream Micropub plugin gains correct
	 * `_wp_attachment_image_alt` writing, this test should flip to
	 * passing — at which point Outpost's bridge becomes
	 * defense-in-depth rather than load-bearing.
	 *
	 * Steps:
	 *   1. Deactivate the Outpost plugin.
	 *   2. POST a Micropub create with structured `{ value, alt }`
	 *      photo property.
	 *   3. Assert the attachment's _wp_attachment_image_alt is empty
	 *      and `post_title` carries the alt instead.
	 *   4. Run AP transformer; assert attachment[0].name === "" because
	 *      AP reads _wp_attachment_image_alt, not post_title.
	 *
	 * @test
	 */
	public function without_outpost_bridge_alt_text_is_lost(): void {
		$this->markTestSkipped( 'wp-env setup pending; see class docblock.' );
	}
}
