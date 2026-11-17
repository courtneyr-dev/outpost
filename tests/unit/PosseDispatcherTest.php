<?php
/**
 * Outpost_POSSE_Dispatcher unit tests (G3.5b).
 *
 * Covers the wp-cron scheduling on publish, retry semantics, and
 * idempotency invariants. Concrete destinations are mocked via
 * subclasses of Outpost_POSSE_Destination_Base — no live API calls.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_POSSE_Destination_Base;
use Outpost_POSSE_Dispatcher;
use Outpost_POSSE_Meta;
use Outpost_POSSE_Registry;
use ReflectionClass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Test-only destination: returns a fixed dispatch() result.
 */
final class G35bFakeDestination extends Outpost_POSSE_Destination_Base {

	/** @var array<string,mixed> */
	public static array $dispatch_result = array(
		'success'         => true,
		'syndication_url' => 'https://example.com/syndicated',
		'error'           => null,
		'retryable'       => false,
	);

	public static int $dispatch_calls = 0;

	private string $id;

	public function __construct( string $id = 'fake-destination' ) {
		$this->id = $id;
	}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return 'Fake Destination';
	}

	public function provider_id(): string {
		return '';
	}

	public function dispatch( int $post_id ): array {
		++self::$dispatch_calls;
		return self::$dispatch_result;
	}
}

final class PosseDispatcherTest extends TestCase {

	/** @var array<string, string>|null Fields of the post get_post() returns; null for a deleted post. */
	private ?array $post_fields = array(
		'post_status'   => 'publish',
		'post_password' => '',
		'post_type'     => 'post',
	);

	public function setUp(): void {
		WP_Mock::setUp();
		$ref  = new ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
		Outpost_POSSE_Registry::reset_for_tests();
		G35bFakeDestination::$dispatch_calls = 0;
		G35bFakeDestination::$dispatch_result = array(
			'success'         => true,
			'syndication_url' => 'https://example.com/syndicated',
			'error'           => null,
			'retryable'       => false,
		);
	}

	public function tearDown(): void {
		Outpost_POSSE_Registry::reset_for_tests();
		WP_Mock::tearDown();
	}

	// --- Scheduling on publish ---------------------------------------

	public function test_publish_schedules_dispatch_for_each_target(): void {
		$this->stub_wp_state();
		$this->stub_post_meta_store(
			array(
				'1|' . Outpost_POSSE_Meta::TARGETS => array( 'destination-a', 'destination-b' ),
			)
		);
		$scheduled = array();
		WP_Mock::userFunction( 'wp_schedule_single_event' )->andReturnUsing(
			static function ( $ts, $hook, $args ) use ( &$scheduled ) {
				$scheduled[] = array(
					'ts'   => $ts,
					'hook' => $hook,
					'args' => $args,
				);
				return true;
			}
		);
		WP_Mock::userFunction( 'user_can' )->andReturn( true );

		Outpost_POSSE_Dispatcher::maybe_schedule_on_publish(
			'publish',
			'draft',
			(object) array(
				'ID'            => 1,
				'post_author'   => 7,
				'post_status'   => 'publish',
				'post_password' => '',
				'post_type'     => 'post',
			)
		);

		$this->assertCount( 2, $scheduled );
		$this->assertSame( Outpost_POSSE_Dispatcher::CRON_HOOK, $scheduled[0]['hook'] );
		$this->assertSame( array( 1, 'destination-a', 1 ), $scheduled[0]['args'] );
		$this->assertSame( array( 1, 'destination-b', 1 ), $scheduled[1]['args'] );
	}

	public function test_skips_already_in_flight_targets(): void {
		$this->stub_wp_state();
		$this->stub_post_meta_store(
			array(
				'1|' . Outpost_POSSE_Meta::TARGETS   => array( 'destination-a', 'destination-b' ),
				'1|' . Outpost_POSSE_Meta::IN_FLIGHT => array( 'destination-a' ),
			)
		);
		$scheduled = 0;
		WP_Mock::userFunction( 'wp_schedule_single_event' )->andReturnUsing(
			static function () use ( &$scheduled ) {
				++$scheduled;
				return true;
			}
		);
		WP_Mock::userFunction( 'user_can' )->andReturn( true );

		Outpost_POSSE_Dispatcher::maybe_schedule_on_publish(
			'publish',
			'draft',
			(object) array(
				'ID'            => 1,
				'post_author'   => 7,
				'post_status'   => 'publish',
				'post_password' => '',
				'post_type'     => 'post',
			)
		);

		// Only destination-b scheduled; destination-a was already in-flight.
		$this->assertSame( 1, $scheduled );
	}

	public function test_post_modified_unchanged_skips_redispatch(): void {
		// transition_post_status fires with new=='publish' AND old=='publish'
		// when an already-published post is just updated. The dispatcher
		// must NOT re-fire in that case.
		$this->stub_wp_state();
		$schedules = $this->stub_schedule_counter();

		Outpost_POSSE_Dispatcher::maybe_schedule_on_publish(
			'publish',
			'publish',
			(object) array(
				'ID'            => 1,
				'post_author'   => 7,
				'post_status'   => 'publish',
				'post_password' => '',
				'post_type'     => 'post',
			)
		);

		$this->assertSame( 0, $schedules->count );
	}

	public function test_capability_gate_blocks_unprivileged_dispatch(): void {
		$this->stub_wp_state();
		$this->stub_post_meta_store(
			array(
				'1|' . Outpost_POSSE_Meta::TARGETS => array( 'destination-a' ),
			)
		);
		WP_Mock::userFunction( 'user_can' )->andReturn( false );
		$schedules = $this->stub_schedule_counter();

		Outpost_POSSE_Dispatcher::maybe_schedule_on_publish(
			'publish',
			'draft',
			(object) array(
				'ID'            => 1,
				'post_author'   => 7,
				'post_status'   => 'publish',
				'post_password' => '',
				'post_type'     => 'post',
			)
		);
		$this->assertSame( 0, $schedules->count );
	}

	public function test_outpost_posse_should_dispatch_filter_can_veto(): void {
		$this->stub_wp_state();
		$this->stub_post_meta_store(
			array(
				'1|' . Outpost_POSSE_Meta::TARGETS => array( 'destination-a' ),
			)
		);
		WP_Mock::userFunction( 'user_can' )->andReturn( true );
		WP_Mock::onFilter( 'outpost_posse_should_dispatch' )->withAnyArgs()->reply( false );
		$schedules = $this->stub_schedule_counter();

		Outpost_POSSE_Dispatcher::maybe_schedule_on_publish(
			'publish',
			'draft',
			(object) array(
				'ID'            => 1,
				'post_author'   => 7,
				'post_status'   => 'publish',
				'post_password' => '',
				'post_type'     => 'post',
			)
		);
		$this->assertSame( 0, $schedules->count );
	}

	// --- Backoff timing ---------------------------------------------

	public function test_backoff_attempt_one_is_thirty_seconds(): void {
		$now = time();
		$ts  = Outpost_POSSE_Dispatcher::next_retry_timestamp( 1 );
		$this->assertGreaterThanOrEqual( $now + 29, $ts );
		$this->assertLessThanOrEqual( $now + 31, $ts );
	}

	public function test_backoff_attempt_two_is_five_minutes(): void {
		$now = time();
		$ts  = Outpost_POSSE_Dispatcher::next_retry_timestamp( 2 );
		$this->assertGreaterThanOrEqual( $now + 5 * 60 - 1, $ts );
		$this->assertLessThanOrEqual( $now + 5 * 60 + 1, $ts );
	}

	public function test_backoff_attempt_three_is_thirty_minutes(): void {
		$now = time();
		$ts  = Outpost_POSSE_Dispatcher::next_retry_timestamp( 3 );
		$this->assertGreaterThanOrEqual( $now + 30 * 60 - 1, $ts );
		$this->assertLessThanOrEqual( $now + 30 * 60 + 1, $ts );
	}

	// --- Public-post gate (F-48) ---------------------------------------

	/**
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function non_public_posts(): array {
		return array(
			'password-protected' => array( array( 'post_status' => 'publish', 'post_password' => 'pw', 'post_type' => 'post' ) ),
			'page'               => array( array( 'post_status' => 'publish', 'post_password' => '', 'post_type' => 'page' ) ),
			'draft'              => array( array( 'post_status' => 'draft', 'post_password' => '', 'post_type' => 'post' ) ),
			'private'            => array( array( 'post_status' => 'private', 'post_password' => '', 'post_type' => 'post' ) ),
			'trash'              => array( array( 'post_status' => 'trash', 'post_password' => '', 'post_type' => 'post' ) ),
		);
	}

	/**
	 * @dataProvider non_public_posts
	 *
	 * @param array<string, string> $fields Post fields.
	 */
	public function test_non_public_post_is_not_scheduled( array $fields ): void {
		$this->stub_wp_state();
		$store     = $this->stub_post_meta_store(
			array(
				'1|' . Outpost_POSSE_Meta::TARGETS => array( 'destination-a' ),
			)
		);
		$schedules = $this->stub_schedule_counter();
		WP_Mock::userFunction( 'user_can' )->andReturn( true );

		Outpost_POSSE_Dispatcher::maybe_schedule_on_publish(
			'publish',
			'draft',
			(object) array_merge(
				array(
					'ID'          => 1,
					'post_author' => 7,
				),
				$fields
			)
		);

		$this->assertSame( 0, $schedules->count, 'Nothing scheduled.' );
		$this->assertArrayNotHasKey( '1|' . Outpost_POSSE_Meta::IN_FLIGHT, $store->meta, 'Nothing marked in flight.' );
	}

	/**
	 * @return array<string, array{0: array<string, string>, 1: int}>
	 */
	public static function non_public_posts_by_attempt(): array {
		$out = array();
		foreach ( self::non_public_posts() as $label => $row ) {
			foreach ( array( 1, 2, 3 ) as $attempt ) {
				$out[ "$label, attempt $attempt" ] = array( $row[0], $attempt );
			}
		}
		return $out;
	}

	/**
	 * @dataProvider non_public_posts_by_attempt
	 *
	 * @param array<string, string> $fields  Post fields.
	 * @param int                   $attempt Attempt number.
	 */
	public function test_dispatch_stops_for_a_non_public_post_on_any_attempt( array $fields, int $attempt ): void {
		$this->stub_wp_state();
		$store             = $this->stub_post_meta_store(
			array(
				'1|' . Outpost_POSSE_Meta::IN_FLIGHT => array( 'destination-a' ),
			)
		);
		$schedules         = $this->stub_schedule_counter();
		$this->post_fields = $fields;
		Outpost_POSSE_Registry::register( new G35bFakeDestination( 'destination-a' ) );

		Outpost_POSSE_Dispatcher::handle_dispatch( 1, 'destination-a', $attempt );

		$this->assertSame( 0, G35bFakeDestination::$dispatch_calls, 'The destination is never called.' );
		$this->assertSame( 0, $schedules->count, 'No retry.' );
		$this->assertSame( array(), $store->meta[ '1|' . Outpost_POSSE_Meta::IN_FLIGHT ] ?? null, 'In-flight cleared.' );
		$failures = $store->meta[ '1|' . Outpost_POSSE_Meta::FAILURES ] ?? array();
		$this->assertCount( 1, $failures, 'One failure.' );
		$this->assertSame( Outpost_POSSE_Dispatcher::NOT_PUBLIC, $failures[0]['error'], 'Reason.' );
		$this->assertSame( $attempt, $failures[0]['attempt_count'], 'Attempt recorded.' );
	}

	public function test_dispatch_for_a_deleted_post_writes_nothing(): void {
		$this->stub_wp_state();
		$store             = $this->stub_post_meta_store( array() );
		$schedules         = $this->stub_schedule_counter();
		$this->post_fields = null;
		Outpost_POSSE_Registry::register( new G35bFakeDestination( 'destination-a' ) );

		Outpost_POSSE_Dispatcher::handle_dispatch( 1, 'destination-a', 1 );

		$this->assertSame( 0, G35bFakeDestination::$dispatch_calls );
		$this->assertSame( 0, $schedules->count );
		$this->assertSame( array(), $store->meta, 'No orphan meta for a deleted post.' );
	}

	// --- Dispatch result handling ----------------------------------

	public function test_successful_dispatch_writes_syndication_url(): void {
		$this->stub_wp_state();
		$store = $this->stub_post_meta_store( array() );
		Outpost_POSSE_Registry::register( new G35bFakeDestination( 'destination-a' ) );
		G35bFakeDestination::$dispatch_result = array(
			'success'         => true,
			'syndication_url' => 'https://example.com/post-on-platform',
			'error'           => null,
			'retryable'       => false,
		);
		$schedules = $this->stub_schedule_counter();

		Outpost_POSSE_Dispatcher::handle_dispatch( 1, 'destination-a', 1 );

		$this->assertSame( 0, $schedules->count, 'success path must not reschedule' );
		$urls = $store->meta['1|' . Outpost_POSSE_Meta::SYNDICATION_URLS] ?? array();
		$this->assertCount( 1, $urls );
		$this->assertSame( 'destination-a', $urls[0]['destination_id'] );
		$this->assertSame( 'https://example.com/post-on-platform', $urls[0]['url'] );
	}

	public function test_non_retryable_failure_records_immediately(): void {
		$this->stub_wp_state();
		$store = $this->stub_post_meta_store( array() );
		Outpost_POSSE_Registry::register( new G35bFakeDestination( 'destination-a' ) );
		G35bFakeDestination::$dispatch_result = array(
			'success'         => false,
			'syndication_url' => null,
			'error'           => 'Auth failed (401)',
			'retryable'       => false,
		);
		$schedules = $this->stub_schedule_counter();

		Outpost_POSSE_Dispatcher::handle_dispatch( 1, 'destination-a', 1 );

		$this->assertSame( 0, $schedules->count, 'non-retryable failure must not reschedule' );
		$failures = $store->meta['1|' . Outpost_POSSE_Meta::FAILURES] ?? array();
		$this->assertCount( 1, $failures );
		$this->assertSame( 'Auth failed (401)', $failures[0]['error'] );
		$this->assertSame( 1, $failures[0]['attempt_count'] );
	}

	public function test_retryable_failure_schedules_next_attempt_with_backoff(): void {
		$this->stub_wp_state();
		$this->stub_post_meta_store( array() );
		Outpost_POSSE_Registry::register( new G35bFakeDestination( 'destination-a' ) );
		G35bFakeDestination::$dispatch_result = array(
			'success'         => false,
			'syndication_url' => null,
			'error'           => 'Service unavailable (503)',
			'retryable'       => true,
		);

		$captured = array();
		WP_Mock::userFunction( 'wp_schedule_single_event' )->andReturnUsing(
			static function ( $ts, $hook, $args ) use ( &$captured ) {
				$captured = array(
					'ts'   => $ts,
					'hook' => $hook,
					'args' => $args,
				);
				return true;
			}
		);

		Outpost_POSSE_Dispatcher::handle_dispatch( 1, 'destination-a', 1 );

		$this->assertSame( Outpost_POSSE_Dispatcher::CRON_HOOK, $captured['hook'] );
		$this->assertSame( array( 1, 'destination-a', 2 ), $captured['args'] );
		// Attempt 2's backoff: 5 minutes.
		$this->assertGreaterThanOrEqual( time() + 5 * 60 - 2, $captured['ts'] );
	}

	public function test_three_failures_marks_permanently_failed(): void {
		$this->stub_wp_state();
		$store = $this->stub_post_meta_store( array() );
		Outpost_POSSE_Registry::register( new G35bFakeDestination( 'destination-a' ) );
		G35bFakeDestination::$dispatch_result = array(
			'success'         => false,
			'syndication_url' => null,
			'error'           => 'Service unavailable (503)',
			'retryable'       => true,
		);
		// Capturing-counter rather than ->never() to survive WP_Mock 1.x
		// across-test mock-state leakage.
		$schedules = 0;
		WP_Mock::userFunction( 'wp_schedule_single_event' )->andReturnUsing(
			static function () use ( &$schedules ) {
				++$schedules;
				return true;
			}
		);

		// Attempt 3 with retryable=true must NOT reschedule a 4th —
		// MAX_ATTEMPTS guard kicks in.
		Outpost_POSSE_Dispatcher::handle_dispatch( 1, 'destination-a', 3 );

		$this->assertSame( 0, $schedules, 'Attempt 3 must not reschedule (MAX_ATTEMPTS guard)' );
		$failures = $store->meta['1|' . Outpost_POSSE_Meta::FAILURES] ?? array();
		$this->assertCount( 1, $failures );
		$this->assertSame( 3, $failures[0]['attempt_count'] );
	}

	public function test_handle_dispatch_with_unknown_destination_records_failure(): void {
		$this->stub_wp_state();
		$store = $this->stub_post_meta_store( array() );
		// No destination registered for 'destination-ghost'.
		$schedules = $this->stub_schedule_counter();

		Outpost_POSSE_Dispatcher::handle_dispatch( 1, 'destination-ghost', 1 );

		$this->assertSame( 0, $schedules->count );
		$failures = $store->meta['1|' . Outpost_POSSE_Meta::FAILURES] ?? array();
		$this->assertCount( 1, $failures );
		$this->assertStringContainsString( 'not registered', $failures[0]['error'] );
	}

	// --- Helpers --------------------------------------------------

	/**
	 * Capturing-counter mock for wp_schedule_single_event. Returns an
	 * object with a public `->count` property that increments per call.
	 * Survives WP_Mock 1.x's known mock-state leakage across tests
	 * better than `->never()`.
	 */
	private function stub_schedule_counter(): object {
		$counter        = new \stdClass();
		$counter->count = 0;
		WP_Mock::userFunction( 'wp_schedule_single_event' )->andReturnUsing(
			static function () use ( $counter ) {
				++$counter->count;
				return true;
			}
		);
		return $counter;
	}

	private function stub_wp_state( ?callable $apply_filters_override = null ): void {
		unset( $apply_filters_override );
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
		WP_Mock::userFunction( 'do_action' )->andReturn( null );
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		WP_Mock::userFunction( 'get_post' )->andReturnUsing( array( $this, 'post_fixture' ) );
	}

	/**
	 * The post handle_dispatch() re-reads. Public unless a test changes
	 * $this->post_fields.
	 *
	 * @param mixed $post_id Post id.
	 */
	public function post_fixture( $post_id ): ?object {
		if ( null === $this->post_fields ) {
			return null;
		}
		return (object) array_merge( array( 'ID' => (int) $post_id ), $this->post_fields );
	}

	/**
	 * Set up an in-memory post-meta store backed by closures.
	 *
	 * @param array<string,mixed> $seed Pre-populated `{$post_id}|{$key}` entries.
	 * @return object Has a public ->meta array reference for assertions.
	 */
	private function stub_post_meta_store( array $seed ): object {
		$store       = new \stdClass();
		$store->meta = $seed;

		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			static function ( $post_id, $key, $single ) use ( $store ) {
				$index = $post_id . '|' . $key;
				return $store->meta[ $index ] ?? '';
			}
		);
		WP_Mock::userFunction( 'update_post_meta' )->andReturnUsing(
			static function ( $post_id, $key, $value ) use ( $store ) {
				$store->meta[ $post_id . '|' . $key ] = $value;
				return true;
			}
		);
		return $store;
	}
}
