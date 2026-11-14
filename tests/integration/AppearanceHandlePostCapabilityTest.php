<?php
/**
 * Appearance handle_post() gates on edit_posts itself (T10-S4-06, L6-05).
 *
 * The REST route and the admin settings page both check edit_posts before
 * calling handle_post(), but the method is public, so a future caller that
 * skips those gates would let any signed-in user write appearance
 * preferences. The method now refuses on its own.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class AppearanceHandlePostCapabilityTest extends TestCase {

	/** @var array<int, int> */
	private array $users = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Appearance_REST_Controller' ) || ! function_exists( 'wp_insert_user' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap.' );
		}
	}

	protected function tearDown(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->users as $id ) {
			wp_delete_user( $id );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function user( string $role ): int {
		$id            = (int) wp_insert_user(
			array(
				'user_login' => 's406_' . $role . '_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 's406_' . uniqid() . '@example.test',
				'role'       => $role,
			)
		);
		$this->users[] = $id;
		return $id;
	}

	private function post_as( int $user_id ) {
		wp_set_current_user( $user_id );
		$request = new \WP_REST_Request( 'POST', '/outpost/v1/appearance' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'mode_preference' => 'night' ) ) );
		return \Outpost_Appearance_REST_Controller::handle_post( $request );
	}

	/**
	 * A user without edit posts is refused and nothing is written.
	 */
	public function test_a_user_without_edit_posts_is_refused_and_nothing_is_written(): void {
		$subscriber = $this->user( 'subscriber' );
		$before     = get_user_meta( $subscriber );

		$result = $this->post_as( $subscriber );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
		$this->assertSame( $before, get_user_meta( $subscriber ), 'No preference is written.' );
	}

	/**
	 * A user with edit posts still saves.
	 */
	public function test_a_user_with_edit_posts_still_saves(): void {
		$author = $this->user( 'author' );

		$result = $this->post_as( $author );

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'night', \Outpost_Mode_Controller::get_mode( $author ) );
	}
}
