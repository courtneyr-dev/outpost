<?php
/**
 * POSSE failure and in-flight meta stay out of public REST reads (F-49).
 *
 * `_outpost_posse_failures` holds error text that can embed a destination's
 * HTTP response body, and `_outpost_posse_in_flight` shows pending sends.
 * Both are limited to `context=edit`, which the core posts route serves only
 * to a user who can edit the post. `_outpost_posse_targets` and
 * `_outpost_syndication_urls` keep working for the editor, and the two keys
 * that had no sanitize callback now have one.
 *
 * Requests go through rest_do_request() against the core
 * /wp/v2/posts/<id> route.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_POSSE_Meta;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class PosseMetaRestExposureTest extends TestCase {

	private const SENTINEL = 'F49-SENTINEL-destination-response-body';

	private int $editor_id = 0;
	private int $post_id   = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_insert_user' ) || ! class_exists( 'Outpost_POSSE_Meta' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap. Run via `npm run test:integration` inside wp-env tests-cli.' );
		}
		$this->editor_id = (int) wp_insert_user(
			array(
				'user_login' => 'f49_editor_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'f49_editor_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		$this->post_id   = (int) wp_insert_post(
			array(
				'post_title'  => 'F49 synthetic post',
				'post_status' => 'publish',
				'post_author' => $this->editor_id,
			)
		);
		Outpost_POSSE_Meta::set_targets( $this->post_id, array( 'kit', 'beehiiv' ) );
		Outpost_POSSE_Meta::add_syndication_url( $this->post_id, 'beehiiv', 'https://collector.example.test/f49/1' );
		Outpost_POSSE_Meta::add_failure( $this->post_id, 'kit', 'Kit returned HTTP 422: ' . self::SENTINEL, 1 );
		Outpost_POSSE_Meta::add_in_flight( $this->post_id, 'kit' );
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		if ( $this->post_id > 0 ) {
			wp_delete_post( $this->post_id, true );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->editor_id );
		parent::tearDown();
	}

	private function get_post_route( string $context = 'view' ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->post_id );
		$request->set_param( 'context', $context );
		return rest_do_request( $request );
	}

	public function test_anonymous_view_read_has_no_failures_or_in_flight(): void {
		$response = $this->get_post_route();
		$this->assertSame( 200, $response->get_status(), 'Precondition: the published post is publicly readable.' );
		$data = $response->get_data();
		$this->assertIsArray( $data['meta'] ?? null, 'Precondition: the response carries meta.' );
		$this->assertArrayHasKey( Outpost_POSSE_Meta::SYNDICATION_URLS, $data['meta'], 'Calibration: registered Outpost meta does appear.' );

		$this->assertArrayNotHasKey( Outpost_POSSE_Meta::FAILURES, $data['meta'], 'No failures in a public read.' );
		$this->assertArrayNotHasKey( Outpost_POSSE_Meta::IN_FLIGHT, $data['meta'], 'No in-flight list in a public read.' );
		$this->assertStringNotContainsString( self::SENTINEL, (string) wp_json_encode( $data ), 'The destination response text appears nowhere.' );
	}

	public function test_anonymous_edit_read_is_refused(): void {
		$response = $this->get_post_route( 'edit' );
		$this->assertSame( 401, $response->get_status() );
		$this->assertStringNotContainsString( self::SENTINEL, (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_editor_reads_failures_and_in_flight_in_edit_context(): void {
		wp_set_current_user( $this->editor_id );
		$response = $this->get_post_route( 'edit' );
		$this->assertSame( 200, $response->get_status() );
		$meta = $response->get_data()['meta'];
		$this->assertSame( array( 'kit', 'beehiiv' ), $meta[ Outpost_POSSE_Meta::TARGETS ] );
		$this->assertSame( 'https://collector.example.test/f49/1', $meta[ Outpost_POSSE_Meta::SYNDICATION_URLS ][0]['url'] );
		$this->assertStringContainsString( self::SENTINEL, $meta[ Outpost_POSSE_Meta::FAILURES ][0]['error'] );
		$this->assertSame( array( 'kit' ), $meta[ Outpost_POSSE_Meta::IN_FLIGHT ] );
	}

	public function test_editor_still_writes_targets(): void {
		wp_set_current_user( $this->editor_id );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $this->post_id );
		$request->set_body_params(
			array(
				'meta' => array(
					Outpost_POSSE_Meta::TARGETS => array( 'buttondown', 'write-as' ),
				),
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'buttondown', 'write-as' ), Outpost_POSSE_Meta::get_targets( $this->post_id ) );
		$this->assertSame( array( 'buttondown', 'write-as' ), $response->get_data()['meta'][ Outpost_POSSE_Meta::TARGETS ] );
	}

	public function test_syndication_urls_are_sanitized(): void {
		$good = array(
			'destination_id' => 'kit',
			'url'            => 'https://collector.example.test/f49/2',
			'posted_at'      => '2026-11-15T12:00:00+00:00',
		);
		update_post_meta(
			$this->post_id,
			Outpost_POSSE_Meta::SYNDICATION_URLS,
			array(
				$good,
				array(
					'destination_id' => 'bad',
					'url'            => 'javascript:alert(1)',
					'posted_at'      => 'x',
				),
				array( 'url' => 'https://collector.example.test/no-destination' ),
				'not-a-row',
			)
		);
		$this->assertSame( array( $good ), Outpost_POSSE_Meta::get_syndication_urls( $this->post_id ), 'Only the well-formed http(s) row survives.' );

		update_post_meta( $this->post_id, Outpost_POSSE_Meta::SYNDICATION_URLS, 'not-an-array' );
		$this->assertSame( array(), Outpost_POSSE_Meta::get_syndication_urls( $this->post_id ) );
	}

	public function test_failures_are_sanitized(): void {
		$good = array(
			'destination_id' => 'kit',
			'error'          => 'Kit returned HTTP 503: unavailable',
			'attempted_at'   => '2026-11-15T12:00:00+00:00',
			'attempt_count'  => 3,
		);
		update_post_meta(
			$this->post_id,
			Outpost_POSSE_Meta::FAILURES,
			array(
				$good,
				array(
					'destination_id' => 'beehiiv',
					'error'          => '<script>alert(1)</script>Beehiiv failed',
					'attempted_at'   => '2026-11-15T12:00:00+00:00',
					'attempt_count'  => '2',
				),
				array( 'error' => 'no destination' ),
				42,
			)
		);
		$this->assertSame(
			array(
				$good,
				array(
					'destination_id' => 'beehiiv',
					'error'          => 'Beehiiv failed',
					'attempted_at'   => '2026-11-15T12:00:00+00:00',
					'attempt_count'  => 2,
				),
			),
			Outpost_POSSE_Meta::get_failures( $this->post_id )
		);
	}
}
