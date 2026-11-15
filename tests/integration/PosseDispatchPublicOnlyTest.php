<?php
/**
 * POSSE sends only public posts (F-48).
 *
 * The dispatcher schedules a send only for a `post` (or a type the
 * `outpost_posse_post_types` filter allows) that moves into `publish` with
 * no password. At every dispatch attempt, retries included, it re-reads the
 * post and stops, clearing that destination's in-flight entry and recording
 * the non-retrying reason `post_not_public`, unless the post is still
 * published, unprotected and of an allowed type. Kit, Beehiiv, Buttondown
 * and Write.as each refuse a non-public post on their own as well.
 *
 * Built from the T11 Phase 2 P2-C6 harness. Real wp_insert_post /
 * wp_update_post / wp_trash_post calls fire the real transition hook;
 * scheduled events run the way wp-cron.php runs them (unschedule, then
 * do_action_ref_array) with no current user. Destinations:
 *
 * - an in-process collector registered through `outpost_posse_destinations`
 *   that applies no gate of its own and records every call;
 * - the four shipped destinations with synthetic settings, whose HTTP is
 *   answered locally by a `pre_http_request` collector at priority 1.
 *
 * A PHP_INT_MAX backstop refuses anything the collector missed and an
 * `http_api_debug` counter records any request that reached a transport:
 * both must stay at 0.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_POSSE_Content_Transformer;
use Outpost_POSSE_Destination_Base;
use Outpost_POSSE_Dispatcher;
use Outpost_POSSE_Meta;
use Outpost_POSSE_Registry;
use Outpost_Settings_Handler;
use Outpost_Settings_Registry;
use PHPUnit\Framework\TestCase;

final class PosseDispatchPublicOnlyTest extends TestCase {

	public const COLLECTOR_ID = 'f48-collector';

	private const SHIPPED = array( 'kit', 'beehiiv', 'buttondown', 'write-as' );

	private const HOSTS = array(
		'api.convertkit.com'   => 'kit',
		'api.beehiiv.com'      => 'beehiiv',
		'api.buttondown.email' => 'buttondown',
		'write.as'             => 'write-as',
	);

	/** @var array<int, array{dest: string, url: string, body: string}> */
	public static array $http_log = array();

	/** @var array<int, array{post_id: int, status: ?string, payload: string}> */
	public static array $collector_log = array();

	public static bool $fail = false;

	private static int $backstop_hits = 0;
	private static int $transport_hits = 0;

	private int $editor_id = 0;

	private bool $mock_filter_was_on = false;

	/** @var int[] */
	private array $post_ids = array();

	/** @var array<string, callable> */
	private array $hooks = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_insert_user' ) || ! class_exists( 'Outpost_POSSE_Dispatcher' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap. Run via `npm run test:integration` inside wp-env tests-cli.' );
		}
		$this->mock_filter_was_on = class_exists( '\Outpost_Mock_Server_Filter' )
			&& false !== has_filter( 'pre_http_request', array( '\Outpost_Mock_Server_Filter', 'maybe_rewrite' ) );
		if ( $this->mock_filter_was_on ) {
			remove_filter( 'pre_http_request', array( '\Outpost_Mock_Server_Filter', 'maybe_rewrite' ), 10 );
		}
		self::$http_log       = array();
		self::$collector_log  = array();
		self::$fail           = false;
		self::$backstop_hits  = 0;
		self::$transport_hits = 0;

		$this->hooks['capture'] = static function ( $preempt, $args, $url ) {
			unset( $preempt );
			$host             = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
			$body             = $args['body'] ?? '';
			$n                = count( self::$http_log ) + 1;
			self::$http_log[] = array(
				'dest' => self::HOSTS[ $host ] ?? 'other',
				'url'  => (string) $url,
				'body' => is_array( $body ) ? (string) wp_json_encode( $body ) : (string) $body,
			);
			if ( self::$fail ) {
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => '{"error":"f48 synthetic unavailable"}',
					'response' => array(
						'code'    => 503,
						'message' => 'Service Unavailable',
					),
					'cookies'  => array(),
				);
			}
			$u = 'https://collector.example.test/' . $n;
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => (string) wp_json_encode(
					array(
						'broadcast' => array(
							'id'         => 'f48-' . $n,
							'public_url' => $u,
						),
						'data'      => array(
							'id'      => 'f48-' . $n,
							'slug'    => 'f48-' . $n,
							'web_url' => $u,
							'url'     => $u,
						),
						'web_url'   => $u,
						'result'    => array(
							'url'  => $u,
							'path' => 'f48-' . $n,
						),
					)
				),
				'response' => array(
					'code'    => 201,
					'message' => 'Created',
				),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $this->hooks['capture'], 1, 3 );

		$this->hooks['backstop'] = static function ( $preempt, $args, $url ) {
			unset( $args );
			if ( false === $preempt ) {
				++self::$backstop_hits;
				return new \WP_Error( 'f48_blocked', 'Blocked by the F-48 backstop: ' . $url );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $this->hooks['backstop'], PHP_INT_MAX, 3 );

		$this->hooks['transport'] = static function (): void {
			++self::$transport_hits;
		};
		add_action( 'http_api_debug', $this->hooks['transport'], 10, 0 );

		$this->hooks['registry'] = static function ( $destinations ) {
			$destinations[ self::COLLECTOR_ID ] = new F48_Collector_Destination();
			return $destinations;
		};
		add_filter( 'outpost_posse_destinations', $this->hooks['registry'] );

		$this->editor_id = (int) wp_insert_user(
			array(
				'user_login' => 'f48_editor_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'f48_editor_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		wp_set_current_user( $this->editor_id );
		Outpost_Settings_Handler::save_submission(
			'api_keys',
			array(
				'kit_enabled'            => '1',
				'kit_api_secret'         => 'f48-synthetic-kit-value',
				'beehiiv_enabled'        => '1',
				'beehiiv_api_key'        => 'f48-synthetic-beehiiv-value',
				'beehiiv_publication_id' => 'pub_f48synthetic',
				'buttondown_enabled'     => '1',
				'buttondown_api_key'     => 'f48-synthetic-buttondown-value',
				'write_as_enabled'       => '1',
				'write_as_api_token'     => 'f48-synthetic-writeas-value',
				'write_as_collection'    => '',
			),
			array()
		);
		foreach ( array_merge( array( self::COLLECTOR_ID ), self::SHIPPED ) as $id ) {
			$this->assertNotNull( Outpost_POSSE_Registry::get( $id ), "Precondition: $id is registered." );
		}
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( $this->post_ids as $id ) {
			foreach ( $this->events_for( $id ) as $e ) {
				wp_unschedule_event( $e['ts'], Outpost_POSSE_Dispatcher::CRON_HOOK, $e['args'] );
			}
			if ( get_post( $id ) ) {
				wp_delete_post( $id, true );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", $id ) );
		}
		remove_filter( 'pre_http_request', $this->hooks['capture'], 1 );
		remove_filter( 'pre_http_request', $this->hooks['backstop'], PHP_INT_MAX );
		remove_action( 'http_api_debug', $this->hooks['transport'], 10 );
		remove_filter( 'outpost_posse_destinations', $this->hooks['registry'] );
		remove_all_filters( 'outpost_posse_post_types' );
		delete_option( Outpost_Settings_Registry::option_name_for_tab( 'api_keys' ) );
		if ( $this->mock_filter_was_on ) {
			\Outpost_Mock_Server_Filter::register();
		}
		wp_set_current_user( 0 );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->editor_id );
		parent::tearDown();
	}

	// ----- Helpers -------------------------------------------------------

	private static function secret( string $case, string $tag ): string {
		return 'F48-SECRET-' . $case . '-' . $tag;
	}

	/**
	 * @param array<string, mixed> $o Overrides: type, status, password, date.
	 */
	private function make_post( string $case, array $o = array() ): int {
		$o    = array_merge(
			array(
				'type'     => 'post',
				'status'   => 'draft',
				'password' => '',
				'date'     => null,
			),
			$o
		);
		$args = array(
			'post_title'    => 'F48 ' . $case,
			'post_content'  => '<!-- wp:paragraph --><p>Body ' . self::secret( $case, 'A' ) . '</p><!-- /wp:paragraph -->',
			'post_status'   => $o['status'],
			'post_type'     => $o['type'],
			'post_author'   => $this->editor_id,
			'post_password' => $o['password'],
		);
		if ( null !== $o['date'] ) {
			$args['post_date']     = $o['date'];
			$args['post_date_gmt'] = get_gmt_from_date( $o['date'] );
		}
		$id = (int) wp_insert_post( $args, true );
		$this->assertGreaterThan( 0, $id, "Precondition: $case inserted." );
		$this->post_ids[] = $id;
		Outpost_POSSE_Meta::set_targets( $id, array_merge( array( self::COLLECTOR_ID ), self::SHIPPED ) );
		return $id;
	}

	private function publish( int $id ): void {
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		clean_post_cache( $id );
	}

	/**
	 * @return array<int, array{ts: int, args: array<int, mixed>}>
	 */
	private function events_for( int $post_id ): array {
		$out = array();
		foreach ( (array) _get_cron_array() as $ts => $hooks ) {
			foreach ( (array) ( $hooks[ Outpost_POSSE_Dispatcher::CRON_HOOK ] ?? array() ) as $ev ) {
				if ( (int) ( $ev['args'][0] ?? 0 ) === $post_id ) {
					$out[] = array(
						'ts'   => (int) $ts,
						'args' => $ev['args'],
					);
				}
			}
		}
		return $out;
	}

	/** Run the post's events the way wp-cron.php does, with no current user. */
	private function run_cron( int $post_id ): int {
		$events = $this->events_for( $post_id );
		$saved  = get_current_user_id();
		wp_set_current_user( 0 );
		foreach ( $events as $e ) {
			wp_unschedule_event( $e['ts'], Outpost_POSSE_Dispatcher::CRON_HOOK, $e['args'] );
			do_action_ref_array( Outpost_POSSE_Dispatcher::CRON_HOOK, $e['args'] );
		}
		wp_set_current_user( $saved );
		return count( $events );
	}

	private function change_state( int $id, string $case, string $change ): void {
		$content = (string) get_post_field( 'post_content', $id ) . '<!-- wp:paragraph --><p>Later ' . self::secret( $case, 'B' ) . '</p><!-- /wp:paragraph -->';
		switch ( $change ) {
			case 'draft':
			case 'private':
				wp_update_post(
					array(
						'ID'           => $id,
						'post_status'  => $change,
						'post_content' => $content,
					)
				);
				break;
			case 'trash':
				wp_update_post(
					array(
						'ID'           => $id,
						'post_content' => $content,
					)
				);
				wp_trash_post( $id );
				break;
			case 'password':
				wp_update_post(
					array(
						'ID'            => $id,
						'post_password' => 'f48-pw-' . $case,
						'post_content'  => $content,
					)
				);
				break;
		}
		clean_post_cache( $id );
	}

	/**
	 * Requests a shipped destination sent, per destination id.
	 *
	 * @return array<string, int>
	 */
	private static function requests_by_dest(): array {
		$out = array_fill_keys( self::SHIPPED, 0 );
		foreach ( self::$http_log as $r ) {
			$out[ $r['dest'] ] = ( $out[ $r['dest'] ] ?? 0 ) + 1;
		}
		return $out;
	}

	private static function anything_contains( string $needle ): bool {
		foreach ( self::$http_log as $r ) {
			if ( false !== strpos( $r['body'], $needle ) ) {
				return true;
			}
		}
		foreach ( self::$collector_log as $c ) {
			if ( false !== strpos( $c['payload'], $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private function assert_no_escape(): void {
		$this->assertSame( 0, self::$backstop_hits, 'No request got past the local collector.' );
		$this->assertSame( 0, self::$transport_hits, 'No request reached an HTTP transport.' );
	}

	/**
	 * After a stopped attempt: nothing sent, every destination recorded as
	 * post_not_public, nothing left in flight or scheduled.
	 */
	private function assert_stopped( int $id, string $label ): void {
		$this->assertSame( array_fill_keys( self::SHIPPED, 0 ), self::requests_by_dest(), "$label: 0 requests to each shipped destination" );
		$this->assertSame( array(), self::$collector_log, "$label: 0 calls to the in-process collector" );
		$this->assertSame( array(), Outpost_POSSE_Meta::get_in_flight( $id ), "$label: in-flight cleared" );
		$this->assertSame( array(), Outpost_POSSE_Meta::get_syndication_urls( $id ), "$label: no syndication URL" );
		$this->assertSame( array(), $this->events_for( $id ), "$label: no retry scheduled" );
		$failures = Outpost_POSSE_Meta::get_failures( $id );
		$this->assertCount( 5, $failures, "$label: one failure per destination" );
		foreach ( $failures as $row ) {
			$this->assertSame( 'post_not_public', $row['error'] ?? null, "$label: {$row['destination_id']} reason" );
		}
		$this->assert_no_escape();
	}

	// ----- Tests -----------------------------------------------------------

	/** Positive control: a public post goes to all five destinations. */
	public function test_public_post_is_sent_to_every_destination(): void {
		$id = $this->make_post( 'public' );
		$this->publish( $id );
		$this->assertCount( 5, $this->events_for( $id ), 'Five events scheduled.' );

		$this->assertSame( 5, $this->run_cron( $id ) );

		$this->assertSame( array_fill_keys( self::SHIPPED, 1 ), self::requests_by_dest(), 'One request to each shipped destination.' );
		$this->assertCount( 1, self::$collector_log, 'One collector call.' );
		$this->assertTrue( self::anything_contains( self::secret( 'public', 'A' ) ), 'The post body was sent.' );
		$this->assertCount( 5, Outpost_POSSE_Meta::get_syndication_urls( $id ) );
		$this->assertSame( array(), Outpost_POSSE_Meta::get_failures( $id ) );
		$this->assertSame( array(), Outpost_POSSE_Meta::get_in_flight( $id ) );
		$this->assert_no_escape();
	}

	public function test_password_protected_at_publish_is_not_scheduled(): void {
		$id = $this->make_post( 'pw-at-publish', array( 'password' => 'f48-pw' ) );
		$this->publish( $id );

		$this->assertSame( array(), $this->events_for( $id ), 'No event scheduled.' );
		$this->assertSame( array(), Outpost_POSSE_Meta::get_in_flight( $id ), 'Nothing in flight.' );
		$this->assertSame( 0, $this->run_cron( $id ) );
		$this->assertSame( array(), self::$http_log );
		$this->assertSame( array(), self::$collector_log );
		$this->assert_no_escape();
	}

	public function test_page_is_not_scheduled_unless_a_filter_allows_its_type(): void {
		$page = $this->make_post( 'page', array( 'type' => 'page' ) );
		$this->publish( $page );
		$this->assertSame( array(), $this->events_for( $page ), 'A page is not scheduled by default.' );

		add_filter(
			'outpost_posse_post_types',
			static function ( array $types ): array {
				$types[] = 'page';
				return $types;
			}
		);
		$allowed = $this->make_post( 'page-allowed', array( 'type' => 'page' ) );
		$this->publish( $allowed );
		$this->assertCount( 5, $this->events_for( $allowed ), 'A filter-allowed page is scheduled.' );
		$this->run_cron( $allowed );
		$this->assertSame( array_fill_keys( self::SHIPPED, 1 ), self::requests_by_dest(), 'The allowed page is sent.' );
		$this->assert_no_escape();
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function changes(): array {
		return array(
			'draft'          => array( 'draft' ),
			'private'        => array( 'private' ),
			'trash'          => array( 'trash' ),
			'password added' => array( 'password' ),
		);
	}

	/**
	 * A post that leaves the public state between scheduling and the first
	 * attempt sends nothing.
	 *
	 * @dataProvider changes
	 */
	public function test_state_change_before_the_first_attempt_sends_nothing( string $change ): void {
		$case = 'first-' . $change;
		$id   = $this->make_post( $case );
		$this->publish( $id );
		$this->assertCount( 5, $this->events_for( $id ), 'Precondition: scheduled while public.' );

		$this->change_state( $id, $case, $change );
		$this->assertSame( 5, $this->run_cron( $id ) );

		$this->assert_stopped( $id, "$change before attempt 1" );
		$this->assertFalse( self::anything_contains( self::secret( $case, 'A' ) ), 'The original body never left.' );
		$this->assertFalse( self::anything_contains( self::secret( $case, 'B' ) ), 'The protected edit never left.' );
	}

	/**
	 * A post that leaves the public state between a failed attempt and its
	 * retry sends nothing on the retry.
	 *
	 * @dataProvider changes
	 */
	public function test_state_change_before_a_retry_sends_nothing( string $change ): void {
		$case = 'retry-' . $change;
		$id   = $this->make_post( $case );
		$this->publish( $id );

		// Attempt 1 while public: every destination fails retryably.
		self::$fail                       = true;
		F48_Collector_Destination::$retry = true;
		$this->run_cron( $id );
		$this->assertSame( array_fill_keys( self::SHIPPED, 1 ), self::requests_by_dest(), 'Precondition: attempt 1 sent while public.' );
		$retries = $this->events_for( $id );
		$this->assertCount( 5, $retries, 'Precondition: five retries scheduled.' );
		foreach ( $retries as $e ) {
			$this->assertSame( 2, $e['args'][2], 'Precondition: the scheduled event is attempt 2.' );
		}

		// Leave the public state, then let attempt 2 run against healthy hosts.
		self::$fail                       = false;
		F48_Collector_Destination::$retry = false;
		$this->change_state( $id, $case, $change );
		self::$http_log      = array();
		self::$collector_log = array();
		$this->assertSame( 5, $this->run_cron( $id ) );

		$this->assert_stopped( $id, "$change before attempt 2" );
		$this->assertFalse( self::anything_contains( self::secret( $case, 'B' ) ), 'The protected edit never left.' );
	}

	/**
	 * Defense in depth: each shipped destination refuses a non-public post
	 * when its dispatch() is called directly, and still sends a public one.
	 */
	public function test_each_shipped_destination_refuses_a_non_public_post(): void {
		$future = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$states = array(
			'draft'     => array( 'status' => 'draft' ),
			'pending'   => array( 'status' => 'pending' ),
			'private'   => array( 'status' => 'private' ),
			'future'    => array(
				'status' => 'future',
				'date'   => $future,
			),
			'trash'     => array( 'status' => 'trash' ),
			'publish-pw' => array(
				'status'   => 'publish',
				'password' => 'f48-pw',
			),
		);
		foreach ( $states as $state => $o ) {
			$id = $this->make_post( 'direct-' . $state, $o );
			$this->assertSame( $o['status'], get_post_status( $id ), "Precondition: $state status." );
			foreach ( self::SHIPPED as $dest ) {
				$result = Outpost_POSSE_Registry::get( $dest )->dispatch( $id );
				$this->assertFalse( $result['success'], "$dest refuses $state" );
				$this->assertFalse( $result['retryable'], "$dest: $state is not retryable" );
			}
			$this->assertSame( array(), self::$http_log, "$state: 0 requests from the shipped destinations" );
		}

		$public = $this->make_post( 'direct-publish', array( 'status' => 'publish' ) );
		foreach ( self::SHIPPED as $dest ) {
			$this->assertTrue( Outpost_POSSE_Registry::get( $dest )->dispatch( $public )['success'], "$dest sends a public post" );
		}
		$this->assertSame( array_fill_keys( self::SHIPPED, 1 ), self::requests_by_dest(), 'Positive control: one request each.' );
		$this->assert_no_escape();
	}
}

/**
 * In-process destination with no gate of its own. Records each call and the
 * HTML a real destination would build.
 */
final class F48_Collector_Destination extends Outpost_POSSE_Destination_Base {

	public static bool $retry = false;

	public function id(): string {
		return PosseDispatchPublicOnlyTest::COLLECTOR_ID;
	}

	public function label(): string {
		return 'F48 collector';
	}

	public function provider_id(): string {
		return '';
	}

	public function dispatch( int $post_id ): array {
		$p                                        = get_post( $post_id );
		PosseDispatchPublicOnlyTest::$collector_log[] = array(
			'post_id' => $post_id,
			'status'  => $p ? $p->post_status : null,
			'payload' => ( $p ? (string) $p->post_title : '' ) . "\n" . Outpost_POSSE_Content_Transformer::to_html_with_canonical( $post_id ),
		);
		if ( self::$retry ) {
			return self::failure_result( 'F48 synthetic retryable failure', true );
		}
		return self::success_result( 'https://collector.example.test/f48/' . $post_id . '/' . count( PosseDispatchPublicOnlyTest::$collector_log ) );
	}
}
