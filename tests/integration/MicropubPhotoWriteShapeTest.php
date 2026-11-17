<?php
/**
 * F3 — integration test: Outpost Micropub photo write-shape contract.
 *
 * Migrated 2026-05-08 from markTestSkipped stubs (2 of 2). Phase 3
 * cluster #2 of the overnight queue. Verifies `Outpost_Micropub_Bridges::apply_photo_alt_text`
 * writes `_wp_attachment_image_alt` correctly without the AP plugin
 * loaded — guards against future write-path refactors silently
 * breaking the F3 alt-text contract.
 *
 * Pre-readiness check (a/b/c/d) all passed:
 *
 *   (a) N/A — bridge test, not source/extractor
 *   (b) Outpost_Micropub_Bridges concrete (F3 shipped); attachment +
 *       post_meta APIs are core. All branches reach concrete code.
 *   (c) Docblocks reference real F3 shipped behavior (structured
 *       `{value, alt}` shape and parallel-array shape both supported).
 *   (d) No fetches; the bridge fires synchronously on `after_micropub`
 *       and writes post_meta locally.
 *
 * The bridge fires when the Micropub plugin emits the `after_micropub`
 * action. Tests fire that action directly with constructed input
 * arrays — a real Micropub HTTP POST is unnecessary for the bridge's
 * write-path contract.
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

	private int $test_user_id   = 0;
	private int $test_post_id   = 0;
	/** @var int[] */
	private array $attachment_ids = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! $this->integration_environment_ready() ) {
			$this->markTestSkipped(
				'Skipped under unit bootstrap or without OUTPOST_TEST_MOCK_SERVER_URL. '
				. 'Run via `npm run test:integration` inside wp-env tests-cli.'
			);
		}

		$this->test_user_id = (int) wp_insert_user(
			array(
				'user_login' => 'micropub_photo_test_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'mp_photo_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		wp_set_current_user( $this->test_user_id );

		$this->test_post_id = (int) wp_insert_post(
			array(
				'post_title'   => 'Photo post under test',
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_author'  => $this->test_user_id,
				'post_content' => '',
			),
			true
		);
		$this->assertGreaterThan( 0, $this->test_post_id );
	}

	protected function tearDown(): void {
		foreach ( $this->attachment_ids as $att_id ) {
			wp_delete_attachment( $att_id, true );
		}
		$this->attachment_ids = array();
		if ( $this->test_post_id > 0 ) {
			wp_delete_post( $this->test_post_id, true );
			$this->test_post_id = 0;
		}
		if ( $this->test_user_id > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->test_user_id );
			$this->test_user_id = 0;
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function integration_environment_ready(): bool {
		return function_exists( 'wp_insert_user' )
			&& class_exists( 'Outpost_Micropub_Bridges' )
			&& defined( 'OUTPOST_TEST_MOCK_SERVER_URL' );
	}

	/**
	 * Create an attachment parented to $this->test_post_id and return
	 * its ID + URL. The URL is the canonical guid that the bridge will
	 * reverse-resolve via `attachment_url_to_postid()`.
	 *
	 * @return array{id:int, url:string}
	 */
	private function make_attachment( string $title ): array {
		$upload_dir = wp_get_upload_dir();
		$filename   = 'fixture-' . uniqid() . '.jpg';
		$file_path  = $upload_dir['path'] . '/' . $filename;
		$file_url   = $upload_dir['url'] . '/' . $filename;

		// wp-env's uploads dir may not exist yet on a fresh test DB.
		wp_mkdir_p( $upload_dir['path'] );

		// Write a 1×1 JPEG so attachment_url_to_postid resolves the URL.
		// Reusing F3's tests/fixtures/sample-photo-200x200.jpg would also
		// work but a synthetic byte-stream avoids any fixture-coupling.
		$bytes = base64_decode(
			'/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAr/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AL+AAAAAAA//9k='
		);
		file_put_contents( $file_path, $bytes );

		$att_id = (int) wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$file_path,
			$this->test_post_id
		);
		$this->assertGreaterThan( 0, $att_id );
		$this->attachment_ids[] = $att_id;

		return array(
			'id'  => $att_id,
			'url' => $file_url,
		);
	}

	/**
	 * Test 1: structured `{value, alt}` photo property.
	 * Bridge writes alt to `_wp_attachment_image_alt` for each
	 * attachment whose URL appears in the photo property — regardless
	 * of whether the AP plugin is loaded.
	 *
	 * @test
	 */
	public function attachment_alt_text_persists_independent_of_activitypub(): void {
		$att = $this->make_attachment( 'Photo under test' );

		$input = array(
			'properties' => array(
				'photo' => array(
					array(
						'value' => $att['url'],
						'alt'   => 'A descriptive alt text for the photo',
					),
				),
			),
		);
		$args = array( 'ID' => $this->test_post_id );

		do_action( 'after_micropub', $input, $args );

		$stored = get_post_meta( $att['id'], '_wp_attachment_image_alt', true );
		$this->assertSame(
			'A descriptive alt text for the photo',
			$stored,
			'F3 bridge must write _wp_attachment_image_alt from structured shape, even without AP plugin loaded.'
		);
	}

	/**
	 * Test 2: parallel-array shape (legacy clients).
	 * `properties.photo = [url1, url2]` plus
	 * `properties.mp-photo-alt = [alt1, alt2]`. Bridge matches by index.
	 *
	 * @test
	 */
	public function bridge_handles_parallel_array_shape_for_legacy_clients(): void {
		$att1 = $this->make_attachment( 'First photo' );
		$att2 = $this->make_attachment( 'Second photo' );

		$input = array(
			'properties' => array(
				'photo'         => array( $att1['url'], $att2['url'] ),
				'mp-photo-alt'  => array( 'Alt for the first photo', 'Alt for the second photo' ),
			),
		);
		$args = array( 'ID' => $this->test_post_id );

		do_action( 'after_micropub', $input, $args );

		$this->assertSame(
			'Alt for the first photo',
			get_post_meta( $att1['id'], '_wp_attachment_image_alt', true ),
			'Parallel-array shape: alt[0] must persist on attachment[0].'
		);
		$this->assertSame(
			'Alt for the second photo',
			get_post_meta( $att2['id'], '_wp_attachment_image_alt', true ),
			'Parallel-array shape: alt[1] must persist on attachment[1].'
		);
	}

	/**
	 * Test 3: photo caption → attachment `post_excerpt`.
	 *
	 * A caption is prose shown under the photo, distinct from alt text.
	 * WordPress keeps it as the attachment post's excerpt, which is the
	 * field the media library shows and core's Image block renders. This
	 * fires the real `after_micropub` action, so it also proves the bridge
	 * is wired — a writer nothing calls is a feature that does not exist.
	 *
	 * @test
	 */
	public function photo_caption_is_written_to_the_attachment_excerpt(): void {
		$att = $this->make_attachment( 'Photo with a caption' );

		$input = array(
			'properties' => array(
				'photo' => array(
					array(
						'value'   => $att['url'],
						'alt'     => 'Three wild turkeys on a suburban lawn',
						'caption' => 'They own this street now',
					),
				),
			),
		);

		do_action( 'after_micropub', $input, array( 'ID' => $this->test_post_id ) );

		$this->assertSame(
			'They own this street now',
			get_post_field( 'post_excerpt', $att['id'] ),
			'Caption must land on the attachment excerpt.'
		);
		$this->assertSame(
			'Three wild turkeys on a suburban lawn',
			get_post_meta( $att['id'], '_wp_attachment_image_alt', true ),
			'Alt text must be unaffected by the caption write.'
		);
	}

	/**
	 * A photo with no caption leaves the attachment excerpt alone. Every
	 * post published before this feature took that path, so it must stay
	 * a no-op rather than blanking an excerpt set elsewhere.
	 *
	 * @test
	 */
	public function absent_caption_leaves_the_existing_excerpt_untouched(): void {
		$att = $this->make_attachment( 'Photo without a caption' );
		wp_update_post(
			array(
				'ID'           => $att['id'],
				'post_excerpt' => 'Set by hand in the media library',
			)
		);

		$input = array(
			'properties' => array(
				'photo' => array(
					array(
						'value' => $att['url'],
						'alt'   => 'Alt text but no caption',
					),
				),
			),
		);

		do_action( 'after_micropub', $input, array( 'ID' => $this->test_post_id ) );

		$this->assertSame(
			'Set by hand in the media library',
			get_post_field( 'post_excerpt', $att['id'] ),
			'A post with no caption must not touch the attachment excerpt.'
		);
	}

	/**
	 * Outpost's own composer sends the parallel-array shape — `photo[]` of
	 * URLs alongside `mp-photo-alt[]` — not the structured `{value, alt}`
	 * object. Captions must travel the same way or the bridge never fires
	 * for a post made in Outpost.
	 *
	 * @test
	 */
	public function caption_is_written_from_the_parallel_array_shape(): void {
		$one = $this->make_attachment( 'Parallel shape photo one' );
		$two = $this->make_attachment( 'Parallel shape photo two' );

		$input = array(
			'properties' => array(
				'photo'             => array( $one['url'], $two['url'] ),
				'mp-photo-alt'      => array( 'Alt for one', 'Alt for two' ),
				'mp-photo-caption'  => array( 'Caption for one', 'Caption for two' ),
			),
		);

		do_action( 'after_micropub', $input, array( 'ID' => $this->test_post_id ) );

		$this->assertSame(
			'Caption for one',
			get_post_field( 'post_excerpt', $one['id'] ),
			'Parallel-shape caption must pair to the first photo by index.'
		);
		$this->assertSame(
			'Caption for two',
			get_post_field( 'post_excerpt', $two['id'] ),
			'Parallel-shape caption must pair to the second photo by index.'
		);
	}
}
