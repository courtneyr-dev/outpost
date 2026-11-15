<?php
/**
 * Issue #242: every outbound sender sends Post Kinds' safe title.
 *
 * A check-in imported as "Checked in at <venue>" keeps that string in
 * post_title. When the venue is hidden, Post Kinds prints "Check-in, <date>"
 * on the site, and every Outpost sender that copies the title to another
 * service has to send the same safe title. This test captures each sender's
 * outbound request and checks the venue never leaves.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Credentials_Store;
use Outpost_Encryption;
use Outpost_POSSE_Destination_Beehiiv;
use Outpost_POSSE_Destination_Buttondown;
use Outpost_POSSE_Destination_Kit;
use Outpost_POSSE_Destination_WriteAs;
use Outpost_Settings_Registry;
use Outpost_Telegraph_Adapter;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/_helpers/pkiw-safe-title-stub.php';

/**
 * @coversNothing
 */
final class OutboundSafeTitleTest extends TestCase {

	private const VENUE      = 'Secret Cafe Hideaway';
	private const SAFE_TITLE = 'Check-in, November 14, 2026';

	private int $user_id = 0;
	private int $post_id = 0;

	/**
	 * Captured outbound requests: list of [url, body string].
	 *
	 * @var list<array{0:string,1:string}>
	 */
	private array $captured = array();

	/** @var callable */
	private $capture;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Telegraph_Adapter' ) || ! class_exists( 'Outpost_Credentials_Store' ) ) {
			$this->markTestSkipped( 'Requires wp-env with Outpost loaded.' );
		}

		$this->user_id = (int) wp_insert_user(
			array(
				'user_login' => 'safe_title_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'safe_title_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		wp_set_current_user( $this->user_id );
		$this->post_id = (int) wp_insert_post(
			array(
				'post_title'   => 'Checked in at ' . self::VENUE,
				// Post Kinds builds a hidden title's slug from the safe title
				// (Title_Privacy::filter_derived_slug); the stub doesn't, so
				// the test sets that slug itself.
				'post_name'    => 'check-in-november-14-2026',
				'post_content' => "<!-- wp:paragraph -->\n<p>Coffee.</p>\n<!-- /wp:paragraph -->",
				'post_status'  => 'publish',
				'post_author'  => $this->user_id,
			)
		);
		update_post_meta( $this->post_id, '_outpost_test_pkiw_safe_title', self::SAFE_TITLE );

		update_option(
			Outpost_Settings_Registry::option_name_for_tab( 'api_keys' ),
			array(
				'kit_enabled'              => true,
				'kit_api_secret'           => array( 'encrypted' => Outpost_Encryption::encrypt( 'kit_test_secret_aaaaaaaaaaaa' ) ),
				'beehiiv_enabled'          => true,
				'beehiiv_api_key'          => array( 'encrypted' => Outpost_Encryption::encrypt( 'beehiiv_test_secret_aaaaaaaaaaaa' ) ),
				'beehiiv_publication_id'   => 'pub_000000000000000000000000',
				'buttondown_enabled'       => true,
				'buttondown_api_key'       => array( 'encrypted' => Outpost_Encryption::encrypt( 'buttondown_test_secret_aaaaaaaa' ) ),
				'buttondown_send_as_draft' => false,
				'write_as_enabled'         => true,
				'write_as_api_token'       => array( 'encrypted' => Outpost_Encryption::encrypt( 'writeas_test_token_aaaaaaaaaaa' ) ),
				'write_as_collection'      => '',
			)
		);
		update_option( Outpost_Telegraph_Adapter::ENABLED_OPTION, true );
		Outpost_Credentials_Store::set( 'telegraph', array( 'access_token' => 'telegraph_test_token' ), $this->user_id );

		$this->captured = array();
		$this->capture  = function ( $preempt, $args, $url ) {
			$body             = $args['body'] ?? '';
			$this->captured[] = array( (string) $url, is_array( $body ) ? (string) wp_json_encode( $body ) : (string) $body );
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => (string) wp_json_encode(
					array(
						'ok'     => true,
						'result' => array(
							'url'  => 'https://telegra.ph/test-page',
							'path' => 'test-page',
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
		// Priority 1: before the test-only mock-server rewrite at 10.
		add_filter( 'pre_http_request', $this->capture, 1, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', $this->capture, 1 );
		if ( $this->post_id > 0 ) {
			wp_delete_post( $this->post_id, true );
		}
		if ( $this->user_id > 0 ) {
			Outpost_Credentials_Store::delete( 'telegraph', $this->user_id );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->user_id );
		}
		wp_set_current_user( 0 );
		delete_option( Outpost_Settings_Registry::option_name_for_tab( 'api_keys' ) );
		delete_option( Outpost_Telegraph_Adapter::ENABLED_OPTION );
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: callable(int): mixed}>
	 */
	public static function senders(): array {
		return array(
			'telegraph'  => array( static fn ( int $id ) => Outpost_Telegraph_Adapter::syndicate( get_post( $id ) ) ),
			'kit'        => array( static fn ( int $id ) => ( new Outpost_POSSE_Destination_Kit() )->dispatch( $id ) ),
			'beehiiv'    => array( static fn ( int $id ) => ( new Outpost_POSSE_Destination_Beehiiv() )->dispatch( $id ) ),
			'buttondown' => array( static fn ( int $id ) => ( new Outpost_POSSE_Destination_Buttondown() )->dispatch( $id ) ),
			'write.as'   => array( static fn ( int $id ) => ( new Outpost_POSSE_Destination_WriteAs() )->dispatch( $id ) ),
		);
	}

	/**
	 * @test
	 * @dataProvider senders
	 *
	 * @param callable(int): mixed $send Sends the post.
	 */
	public function sender_sends_the_safe_title_and_never_the_hidden_venue( callable $send ): void {
		$send( $this->post_id );

		$this->assertCount( 1, $this->captured, 'Exactly one outbound request.' );
		[ $url, $body ] = $this->captured[0];
		$this->assertStringContainsString( self::SAFE_TITLE, $body, "Request to {$url} carries the safe title." );
		$this->assertStringNotContainsString( self::VENUE, $body, "Request to {$url} must not carry the hidden venue." );
	}

	/**
	 * The manual-share caption lands in another app's share sheet, so it
	 * leaves the site the same way.
	 *
	 * @test
	 */
	public function manual_share_caption_uses_the_safe_title(): void {
		\Outpost_Manual_Share_Platform_Registry::reset_for_tests();
		$platforms = array_values( \Outpost_Manual_Share_Platform_Registry::all_platforms() );
		$this->assertNotEmpty( $platforms );

		$android = \Outpost_Manual_Share_Intent_Payload_Builder::build_for_android( $platforms[0], $this->post_id );
		$ios     = \Outpost_Manual_Share_Intent_Payload_Builder::build_for_ios( $platforms[0], $this->post_id, true );

		foreach ( array(
			'android' => $android,
			'ios'     => $ios,
		) as $os => $payload ) {
			$json = (string) wp_json_encode( $payload );
			$this->assertStringContainsString( self::SAFE_TITLE, (string) ( $payload['caption'] ?? '' ), "{$os} caption is the safe title." );
			$this->assertStringNotContainsString( self::VENUE, $json, "{$os} payload must not carry the hidden venue." );
		}
	}

	/**
	 * @test
	 * @dataProvider senders
	 *
	 * @param callable(int): mixed $send Sends the post.
	 */
	public function sender_sends_the_stored_title_when_post_kinds_keeps_it( callable $send ): void {
		delete_post_meta( $this->post_id, '_outpost_test_pkiw_safe_title' );

		$send( $this->post_id );

		$this->assertCount( 1, $this->captured );
		$this->assertStringContainsString( 'Checked in at ' . self::VENUE, $this->captured[0][1] );
	}
}
