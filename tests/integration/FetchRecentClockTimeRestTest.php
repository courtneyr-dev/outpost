<?php
/**
 * F-44: no fetch-recent REST response carries a health event's clock time.
 *
 * Oura sleep used `fetched_at` = bedtime, Oura workouts and Polar sessions
 * used the start time, and WHOOP the cycle start or recovery time. Oura
 * workouts, Polar (without the exercise card) and WHOOP also copied those
 * times into `post_payload.post_meta`, which the picker hands to
 * `editPost()`.
 *
 * Every provider gets distinct sentinel times, and every clock form of
 * each (ISO, first 19 characters, H:i:s, H:i, URL-encoded H:i, 12-hour,
 * Unix seconds and milliseconds) is searched for in the whole REST
 * response, with Post Kinds' cards registered and unavailable. Positive
 * controls: the search finds the sentinels in the provider records it
 * was given, and each item's `fetched_at` is the event's date.
 *
 * Driven through the real route as a cookie editor with a REST nonce;
 * provider HTTP goes through the providers' resolver seams.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Credentials_Store;
use Outpost_Fetch_Recent_Oura;
use Outpost_Fetch_Recent_Polar;
use Outpost_Fetch_Recent_Whoop;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * @coversNothing
 */
final class FetchRecentClockTimeRestTest extends TestCase {

	private const SLEEP_CARD    = 'post-kinds-indieweb/sleep-card';
	private const EXERCISE_CARD = 'post-kinds-indieweb/exercise-card';

	private const OURA_SLEEP = array(
		'id'                   => 'f44-sleep',
		'day'                  => '2026-11-11',
		'bedtime_start'        => '2026-11-10T22:47:31+00:00',
		'bedtime_end'          => '2026-11-11T06:13:52+00:00',
		'total_sleep_duration' => 26000,
		'score'                => 79,
	);

	/** Two workouts on one day: the later one must sort first. */
	private const OURA_WORKOUTS = array(
		array(
			'id'             => 'f44-wk-early',
			'activity'       => 'walking',
			'start_datetime' => '2026-11-09T08:21:07+00:00',
			'end_datetime'   => '2026-11-09T08:58:44+00:00',
			'distance'       => 3100,
			'calories'       => 160,
		),
		array(
			'id'             => 'f44-wk-late',
			'activity'       => 'running',
			'start_datetime' => '2026-11-09T17:29:58+00:00',
			'end_datetime'   => '2026-11-09T18:04:21+00:00',
			'distance'       => 6200,
			'calories'       => 430,
		),
	);

	private const POLAR_RUN = array(
		'id'         => 'f44ex',
		'sport'      => 'RUNNING',
		'start-time' => '2026-11-08T05:13:27',
		'distance'   => 5234.0,
		'duration'   => 'PT29M12.6S',
		'calories'   => 412,
	);

	private const WHOOP_CYCLE = array(
		'id'    => 9441,
		'start' => '2026-11-07T04:38:16.123Z',
		'end'   => '2026-11-08T03:55:09.456Z',
		'score' => array( 'strain' => 12.4 ),
	);

	private const WHOOP_RECOVERY = array(
		'cycle_id'   => 9440,
		'created_at' => '2026-11-06T11:26:44.789Z',
		'updated_at' => '2026-11-06T11:31:02.002Z',
		'score'      => array( 'recovery_score' => 67 ),
	);

	private int $editor_id = 0;

	/** @var array<string,mixed> */
	private array $saved_server = array();

	/** @var mixed */
	private $saved_cookie = null;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Fetch_Recent_Whoop' ) || ! function_exists( 'rest_do_request' ) ) {
			$this->markTestSkipped( 'Requires wp-env with Outpost loaded.' );
		}
		$this->saved_server = $_SERVER;
		$this->saved_cookie = $GLOBALS['wp_rest_auth_cookie'] ?? null;
		$this->editor_id    = (int) wp_insert_user(
			array(
				'user_login' => 'f44_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'f44_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		wp_set_current_user( $this->editor_id );
		foreach ( array( 'oura', 'polar', 'whoop' ) as $provider ) {
			Outpost_Credentials_Store::set( $provider, array( 'access_token' => 'synthetic-' . $provider . '-token' ), $this->editor_id );
		}

		Outpost_Fetch_Recent_Oura::set_http_resolver_for_tests(
			static function ( string $url ) {
				if ( false !== strpos( $url, '/usercollection/sleep' ) ) {
					return array( 'data' => array( self::OURA_SLEEP ) );
				}
				return array( 'data' => self::OURA_WORKOUTS );
			}
		);
		$editor = $this->editor_id;
		Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests(
			static function ( string $method, string $url ) use ( $editor ) {
				$base = 'https://www.polaraccesslink.com/v3/users/' . $editor . '/exercise-transactions';
				if ( 'POST' === $method && $url === $base ) {
					return array( 'transaction-id' => 'txn1' );
				}
				if ( 'GET' === $method && $url === $base . '/txn1' ) {
					return array( 'exercises' => array( $base . '/txn1/exercises/f44ex' ) );
				}
				if ( 'GET' === $method && $url === $base . '/txn1/exercises/f44ex' ) {
					return self::POLAR_RUN;
				}
				return null;
			}
		);
		Outpost_Fetch_Recent_Whoop::set_http_resolver_for_tests(
			static function ( string $url ) {
				if ( false !== strpos( $url, '/cycle' ) ) {
					return array( 'records' => array( self::WHOOP_CYCLE ) );
				}
				return array( 'records' => array( self::WHOOP_RECOVERY ) );
			}
		);
	}

	protected function tearDown(): void {
		Outpost_Fetch_Recent_Oura::set_http_resolver_for_tests( null );
		Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests( null );
		Outpost_Fetch_Recent_Whoop::set_http_resolver_for_tests( null );
		foreach ( array( self::EXERCISE_CARD, self::SLEEP_CARD ) as $block ) {
			if ( \WP_Block_Type_Registry::get_instance()->is_registered( $block ) ) {
				unregister_block_type( $block );
			}
		}
		if ( taxonomy_exists( 'kind' ) ) {
			foreach ( array( 'exercise', 'sleep' ) as $slug ) {
				$term = get_term_by( 'slug', $slug, 'kind' );
				if ( $term instanceof \WP_Term ) {
					wp_delete_term( $term->term_id, 'kind' );
				}
			}
			unregister_taxonomy( 'kind' );
		}
		$_SERVER                        = $this->saved_server;
		$GLOBALS['wp_rest_auth_cookie'] = $this->saved_cookie;
		wp_set_current_user( 0 );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->editor_id );
		parent::tearDown();
	}

	private function register_post_kinds(): void {
		register_block_type(
			self::EXERCISE_CARD,
			array(
				'attributes' => array(
					'activity'            => array( 'type' => 'string' ),
					'distance'            => array( 'type' => 'number' ),
					'distanceUnit'        => array( 'type' => 'string' ),
					'duration'            => array( 'type' => 'string' ),
					'showDetailsPublicly' => array( 'type' => 'boolean' ),
				),
			)
		);
		register_block_type(
			self::SLEEP_CARD,
			array(
				'attributes' => array(
					'durationMinutes'   => array( 'type' => 'integer' ),
					'bedtime'           => array( 'type' => 'string' ),
					'wakeTime'          => array( 'type' => 'string' ),
					'showTimesPublicly' => array( 'type' => 'boolean' ),
				),
			)
		);
		register_taxonomy(
			'kind',
			'post',
			array(
				'show_in_rest' => true,
				'rest_base'    => 'kind',
			)
		);
		wp_insert_term( 'Exercise', 'kind', array( 'slug' => 'exercise' ) );
		wp_insert_term( 'Sleep', 'kind', array( 'slug' => 'sleep' ) );
	}

	/**
	 * GET the route as the sidebar does and return the response as JSON
	 * text plus the decoded items.
	 *
	 * @return array{json:string,items:list<array<string,mixed>>}
	 */
	private function rest_get( string $provider ): array {
		$GLOBALS['wp_rest_auth_cookie'] = true;
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
		$response                   = rest_do_request( new WP_REST_Request( 'GET', '/outpost/v1/fetch-recent/' . $provider ) );
		$data                       = rest_get_server()->response_to_data( $response, false );
		$this->assertSame( 200, $response->get_status(), $provider );
		return array(
			'json'  => (string) wp_json_encode( $data ) . "\n" . (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'items' => $data['items'],
		);
	}

	/**
	 * Every clock form of a timestamp. A value without an offset is read
	 * as UTC, the site timezone in the test install.
	 *
	 * @return list<string>
	 */
	private static function clock_forms( string $iso ): array {
		$at    = new \DateTimeImmutable( $iso, new \DateTimeZone( 'UTC' ) );
		$utc   = $at->setTimezone( new \DateTimeZone( 'UTC' ) );
		$forms = array( $iso, substr( $iso, 0, 19 ), (string) $utc->getTimestamp(), (string) ( $utc->getTimestamp() * 1000 ) );
		foreach ( array( $at, $utc ) as $t ) {
			$forms[] = $t->format( 'H:i:s' );
			$forms[] = $t->format( 'H:i' );
			$forms[] = $t->format( 'H' ) . '%3A' . $t->format( 'i' );
			$forms[] = $t->format( 'g:i A' );
			$forms[] = $t->format( 'g:iA' );
			$forms[] = $t->format( 'g:i a' );
			$forms[] = $t->format( 'g:ia' );
		}
		return array_values( array_unique( $forms ) );
	}

	/**
	 * Clock forms of the given sentinels found in a haystack.
	 *
	 * @param list<string> $sentinels ISO times.
	 * @return list<string>
	 */
	private static function hits( array $sentinels, string $haystack ): array {
		$hits = array();
		foreach ( $sentinels as $sentinel ) {
			foreach ( self::clock_forms( $sentinel ) as $form ) {
				if ( false !== strpos( $haystack, $form ) ) {
					$hits[] = "{$form} (from {$sentinel})";
				}
			}
		}
		return $hits;
	}

	/**
	 * @return array<string,array{0:bool}>
	 */
	public function card_states(): array {
		return array(
			'cards registered'  => array( true ),
			'cards unavailable' => array( false ),
		);
	}

	/**
	 * @return array<string,array{0:string,1:list<string>,2:array<string,string>,3:list<string>}>
	 */
	public function providers(): array {
		return array(
			'oura'  => array(
				'oura',
				array(
					self::OURA_SLEEP['bedtime_start'],
					self::OURA_SLEEP['bedtime_end'],
					self::OURA_WORKOUTS[0]['start_datetime'],
					self::OURA_WORKOUTS[0]['end_datetime'],
					self::OURA_WORKOUTS[1]['start_datetime'],
					self::OURA_WORKOUTS[1]['end_datetime'],
				),
				array(
					'oura-sleep-f44-sleep'     => '2026-11-11',
					'oura-workout-f44-wk-late' => '2026-11-09',
					'oura-workout-f44-wk-early' => '2026-11-09',
				),
				array( '_outpost_oura_start_at' ),
			),
			'polar' => array(
				'polar',
				array( self::POLAR_RUN['start-time'] ),
				array( 'polar-exercise-f44ex' => '2026-11-08' ),
				array( '_outpost_polar_start_time' ),
			),
			'whoop' => array(
				'whoop',
				array(
					self::WHOOP_CYCLE['start'],
					self::WHOOP_CYCLE['end'],
					self::WHOOP_RECOVERY['created_at'],
					self::WHOOP_RECOVERY['updated_at'],
				),
				array(
					'whoop-cycle-9441'    => '2026-11-07',
					'whoop-recovery-9440' => '2026-11-06',
				),
				array( '_outpost_whoop_start_at', '_outpost_whoop_created_at' ),
			),
		);
	}

	/**
	 * @return array<string,array{0:string,1:list<string>,2:array<string,string>,3:list<string>,4:bool}>
	 */
	public function matrix(): array {
		$out = array();
		foreach ( $this->providers() as $name => $provider ) {
			foreach ( $this->card_states() as $state => $args ) {
				$out[ "{$name}, {$state}" ] = array_merge( $provider, $args );
			}
		}
		return $out;
	}

	/**
	 * @test
	 */
	public function the_search_finds_every_sentinel_in_the_provider_records(): void {
		$records = (string) wp_json_encode(
			array( self::OURA_SLEEP, self::OURA_WORKOUTS, self::POLAR_RUN, self::WHOOP_CYCLE, self::WHOOP_RECOVERY ),
			JSON_UNESCAPED_SLASHES
		);
		foreach ( $this->providers() as $name => $provider ) {
			foreach ( $provider[1] as $sentinel ) {
				$this->assertNotSame( array(), self::hits( array( $sentinel ), $records ), "Positive control, {$name}: {$sentinel}" );
			}
		}
	}

	/**
	 * @test
	 * @dataProvider matrix
	 *
	 * @param list<string>          $sentinels  Event times the provider received.
	 * @param array<string,string>  $dates      Expected fetched_at date per item id.
	 * @param list<string>          $time_keys  Meta keys that used to carry a time.
	 */
	public function no_clock_form_reaches_the_response( string $provider, array $sentinels, array $dates, array $time_keys, bool $with_cards ): void {
		if ( $with_cards ) {
			$this->register_post_kinds();
		}
		$response = $this->rest_get( $provider );

		$this->assertSame( array(), self::hits( $sentinels, $response['json'] ), 'No clock form of any sentinel in the response.' );

		$this->assertSame( array_keys( $dates ), array_column( $response['items'], 'id' ), 'Items arrive, newest first.' );
		foreach ( $response['items'] as $item ) {
			$this->assertSame( $dates[ $item['id'] ], $item['fetched_at'], "Positive control: {$item['id']} keeps its date." );
			foreach ( $time_keys as $key ) {
				$this->assertArrayNotHasKey( $key, $item['post_payload']['post_meta'], "{$key} stays on the server." );
			}
		}
	}

	/**
	 * @test
	 */
	public function the_route_keeps_only_the_date_from_any_provider(): void {
		$filter = static function ( $providers ) {
			$providers['f44probe'] = array(
				'label'    => 'F-44 probe',
				'callback' => static function (): array {
					return array(
						array(
							'id'         => 'with-time',
							'title'      => 'With time',
							'fetched_at' => '2026-11-05T21:08:33-06:00',
						),
						array(
							'id'         => 'garbage',
							'title'      => 'Garbage',
							'fetched_at' => 'at 21:08:33',
						),
						array(
							'id'    => 'missing',
							'title' => 'Missing',
						),
					);
				},
			);
			return $providers;
		};
		add_filter( 'outpost_fetch_recent_providers', $filter );
		try {
			$response = $this->rest_get( 'f44probe' );
		} finally {
			remove_filter( 'outpost_fetch_recent_providers', $filter );
		}

		$this->assertSame( array(), self::hits( array( '2026-11-05T21:08:33-06:00' ), $response['json'] ) );
		$this->assertStringNotContainsString( '21:08', $response['json'] );
		$this->assertSame( '2026-11-05', $response['items'][0]['fetched_at'], 'Positive control: the date stays.' );
		$this->assertSame( gmdate( 'Y-m-d' ), $response['items'][1]['fetched_at'], 'An unreadable value becomes today.' );
		$this->assertSame( gmdate( 'Y-m-d' ), $response['items'][2]['fetched_at'], 'A missing value becomes today.' );
	}
}
