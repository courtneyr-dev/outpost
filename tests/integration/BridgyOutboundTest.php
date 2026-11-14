<?php
/**
 * Outpost itself sends nothing to Bridgy (T10-S4-02, F-24).
 *
 * The Bridgy chips put a brid.gy, fed.brid.gy, or bsky.brid.gy target in
 * the post's `mp-syndicate-to`, which goes to the site's own Micropub
 * endpoint. Outpost ships no webmention sender, so the readme's External
 * services section must not list Bridgy as a service Outpost contacts.
 *
 * The test publishes a post with every Bridgy target, runs each hook
 * Outpost attaches to publishing (`after_micropub`, `micropub_syndication`,
 * `transition_post_status`, and the POSSE cron it schedules), and records
 * every outbound request at `pre_http_request`. A control request proves
 * the recorder sees a brid.gy request when one is made.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class BridgyOutboundTest extends TestCase {

	private int $user_id = 0;

	/** @var array<int, string> */
	private array $hosts = array();

	/** @var callable */
	private $recorder;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Bridgy_Publish_Settings' ) || ! function_exists( 'wp_insert_user' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap.' );
		}
		$this->user_id = (int) wp_insert_user(
			array(
				'user_login' => 's402_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 's402_' . uniqid() . '@example.test',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $this->user_id );
		foreach ( array( 'bridgy-mastodon', 'bridgy-bluesky', 'bridgy-flickr', 'bridgy-github', 'bridgy-reddit' ) as $silo ) {
			\Outpost_Bridgy_Publish_Settings::set_enabled( $silo, true );
		}
		$this->recorder = function ( $pre, $args, $url ) {
			$this->hosts[] = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $this->recorder, 0, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', $this->recorder, 0 );
		\Outpost_Bridgy_Publish_Settings::clear_for_tests();
		if ( $this->user_id > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->user_id );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** @return array<int, string> */
	private function bridgy_targets(): array {
		$targets = array();
		foreach ( \Outpost_Bridgy_Publish_Silo_Registry::all_silos() as $silo ) {
			$targets[] = $silo->bridgy_url();
		}
		return array_values( array_unique( array_merge( $targets, array( 'https://fed.brid.gy/', 'https://bsky.brid.gy/' ) ) ) );
	}

	private static function is_bridgy_host( string $host ): bool {
		return 'brid.gy' === $host || str_ends_with( $host, '.brid.gy' );
	}

	public function test_the_recorder_sees_a_bridgy_request_when_one_is_made(): void {
		wp_remote_post( 'https://brid.gy/publish/webmention' );

		$this->assertTrue( self::is_bridgy_host( $this->hosts[0] ?? '' ), 'Control: a brid.gy request is recorded.' );
	}

	public function test_publishing_with_every_bridgy_target_sends_nothing_to_bridgy(): void {
		$targets = $this->bridgy_targets();
		$this->assertContains( 'https://brid.gy/publish/mastodon', $targets, 'Every Bridgy Publish silo target is used.' );

		$post_id = (int) wp_insert_post(
			array(
				'post_title'   => 'Bridgy outbound check',
				'post_content' => 'Synthetic post for the Bridgy outbound check.',
				'post_status'  => 'publish',
				'post_author'  => $this->user_id,
			)
		);
		$this->assertGreaterThan( 0, $post_id );
		$input = array(
			'type'       => array( 'h-entry' ),
			'properties' => array(
				'content'         => array( 'Synthetic post for the Bridgy outbound check.' ),
				'mp-syndicate-to' => $targets,
			),
		);
		do_action( 'after_micropub', $input, array( 'ID' => $post_id ) );
		do_action( 'micropub_syndication', $post_id, $targets );
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				if ( 0 !== strpos( (string) $hook, 'outpost' ) ) {
					continue;
				}
				foreach ( (array) $events as $event ) {
					do_action_ref_array( (string) $hook, (array) ( $event['args'] ?? array() ) );
				}
			}
		}

		$bridgy = array_values( array_filter( $this->hosts, array( self::class, 'is_bridgy_host' ) ) );
		$this->assertSame( array(), $bridgy, 'Outpost makes no request to Bridgy when a post with Bridgy targets is published.' );
		wp_delete_post( $post_id, true );
	}
}
