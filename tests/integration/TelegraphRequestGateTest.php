<?php
/**
 * Telegraph's own request gates (Phase 4 review P4A-02, P4A-06, P4B-05).
 *
 * `syndicate()` is public, so it repeats the publish hook's gates itself:
 * Telegraph enabled, a published post of an allowed type, no password, no
 * skip flag. Both Telegraph requests, account creation and page creation,
 * follow no redirect and read at most 5 MiB, because the page request
 * carries the stored access token.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Credentials_Store;
use Outpost_Telegraph_Adapter;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class TelegraphRequestGateTest extends TestCase {

	private int $user_id = 0;

	/** @var list<int> */
	private array $posts = array();

	/** @var list<array{url:string,redirection:mixed,limit:mixed}> */
	private array $requests = array();

	/** @var callable */
	private $capture;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Telegraph_Adapter' ) ) {
			$this->markTestSkipped( 'Requires wp-env with Outpost loaded.' );
		}
		$this->user_id = (int) wp_insert_user(
			array(
				'user_login' => 'tg_gate_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'tg_gate_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		update_option( Outpost_Telegraph_Adapter::ENABLED_OPTION, true );
		$this->requests = array();
		$this->capture  = function ( $preempt, $args, $url ) {
			$this->requests[] = array(
				'url'         => (string) $url,
				'redirection' => $args['redirection'] ?? null,
				'limit'       => $args['limit_response_size'] ?? null,
			);
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => (string) wp_json_encode(
					array(
						'ok'     => true,
						'result' => array(
							'access_token' => 'telegraph_gate_token',
							'url'          => 'https://telegra.ph/gate-page',
							'path'         => 'gate-page',
						),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $this->capture, 1, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', $this->capture, 1 );
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
		}
		Outpost_Credentials_Store::delete( 'telegraph', $this->user_id );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->user_id );
		delete_option( Outpost_Telegraph_Adapter::ENABLED_OPTION );
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $fields Post fields.
	 */
	private function post( array $fields = array() ): \WP_Post {
		$id            = (int) wp_insert_post(
			array_merge(
				array(
					'post_title'   => 'Gate test',
					'post_content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
					'post_status'  => 'publish',
					'post_author'  => $this->user_id,
				),
				$fields
			)
		);
		$this->posts[] = $id;
		$this->requests = array();
		return get_post( $id );
	}

	/**
	 * @test
	 */
	public function both_requests_follow_no_redirect_and_cap_the_response(): void {
		$result = Outpost_Telegraph_Adapter::syndicate( $this->post() );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $this->requests, 'createAccount, then createPage.' );
		foreach ( $this->requests as $request ) {
			$this->assertSame( 0, $request['redirection'], $request['url'] . ' follows no redirect.' );
			$this->assertSame( 5 * 1024 * 1024, $request['limit'], $request['url'] . ' caps the response at 5 MiB.' );
		}
	}

	/**
	 * @return array<string, array{0: array<string,mixed>, 1: callable(\WP_Post): void}>
	 */
	public static function refused(): array {
		$none = static function (): void {};
		return array(
			'draft'           => array( array( 'post_status' => 'draft' ), $none ),
			'private'         => array( array( 'post_status' => 'private' ), $none ),
			'password'        => array( array( 'post_password' => 'secret' ), $none ),
			'page'            => array( array( 'post_type' => 'page' ), $none ),
			'skip flag'       => array( array(), static fn ( \WP_Post $p ) => update_post_meta( $p->ID, '_outpost_skip_telegraph', '1' ) ),
			'telegraph off'   => array( array(), static fn () => delete_option( Outpost_Telegraph_Adapter::ENABLED_OPTION ) ),
		);
	}

	/**
	 * @test
	 * @dataProvider refused
	 *
	 * @param array<string,mixed>      $fields Post fields.
	 * @param callable(\WP_Post): void $before Extra setup.
	 */
	public function a_post_the_publish_hook_would_skip_is_refused_with_no_request( array $fields, callable $before ): void {
		Outpost_Credentials_Store::set( 'telegraph', array( 'access_token' => 'telegraph_gate_token' ), $this->user_id );
		$post = $this->post( $fields );
		$before( $post );

		$result = Outpost_Telegraph_Adapter::syndicate( $post );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'outpost_telegraph_not_allowed', $result->get_error_code() );
		$this->assertSame( array(), $this->requests, 'No request leaves the site.' );
		$this->assertSame( '', (string) get_post_meta( $post->ID, 'outpost_telegraph_post_url', true ) );
	}
}
