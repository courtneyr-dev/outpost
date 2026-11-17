<?php
/**
 * Unit tests for Outpost_Fetch_Recent_Polar (G11c-consumer).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Fetch_Recent_Polar;
use ReflectionClass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class FetchRecentPolarTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		$ref  = new ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
		Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests( null );
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing( static function ( $s ) { return (string) $s; } );
		$this->site_settings = array();
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			fn ( $name, $fallback = false ) => 'outpost_settings' === $name
				? $this->site_settings
				: ( $this->other_option ?? $fallback )
		);
	}

	/** @var array<string,mixed> What get_option( 'outpost_settings' ) returns. */
	private array $site_settings = array();

	/** @var string|null What every other get_option() call returns (the encryption key). */
	private ?string $other_option = null;

	public function tearDown(): void {
		Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests( null );
		WP_Mock::tearDown();
	}

	public function test_provider_appends_to_registry(): void {
		$result = Outpost_Fetch_Recent_Polar::add_to_registry( array() );

		$this->assertArrayHasKey( 'polar', $result );
		$this->assertSame( 'publish_posts', $result['polar']['capability'] );
		$this->assertSame( 'polar', $result['polar']['oauth_provider'] );
		$this->assertTrue( is_callable( $result['polar']['callback'] ) );
	}

	// ---------- Item shape mapping ----------

	public function test_map_exercise_with_distance(): void {
		$exercise = array(
			'id'         => 'ex-1',
			'sport'      => 'RUNNING',
			'start-time' => '2026-05-04T07:30:00.000',
			'distance'   => 5200.0,
			'duration'   => 'PT32M0S',
		);

		$item = Outpost_Fetch_Recent_Polar::map_exercise_item( $exercise, 'https://www.polaraccesslink.com/v3/exercises/ex-1' );

		$this->assertSame( 'polar-exercise-ex-1', $item['id'] );
		$this->assertStringContainsString( 'Running', $item['title'] );
		$this->assertStringContainsString( '5.2 km', $item['title'] );
		$this->assertSame( 'workout', $item['post_kind'] );
		$this->assertSame( '2026-05-04', $item['subtitle'] );
		$this->assertSame( '5200', $item['post_payload']['post_meta']['_outpost_polar_distance_m'] );
		$this->assertSame( 'PT32M0S', $item['post_payload']['post_meta']['_outpost_polar_duration'] );
	}

	/**
	 * P4B-02: without Post Kinds' card, the title and paragraph go into the
	 * post. Distance and duration stay in private post meta unless the site
	 * turns on "Workout details in post text"; the picker list keeps them.
	 */
	public function test_post_text_leaves_out_distance_and_duration_by_default(): void {
		$distance = Outpost_Fetch_Recent_Polar::map_exercise_item(
			array( 'id' => 'ex-1', 'sport' => 'RUNNING', 'distance' => 5200.0, 'duration' => 'PT32M0S' ),
			'url'
		);
		$duration = Outpost_Fetch_Recent_Polar::map_exercise_item(
			array( 'id' => 'ex-2', 'sport' => 'STRENGTH_TRAINING', 'duration' => 'PT45M30S' ),
			'url'
		);

		$this->assertSame( 'Training — Running', $distance['post_payload']['title'] );
		$this->assertSame( '<p>Training — Running</p>', $distance['post_payload']['content'] );
		$this->assertSame( 'Training — Strength Training', $duration['post_payload']['title'] );
		$this->assertSame( '<p>Training — Strength Training</p>', $duration['post_payload']['content'] );
		$this->assertStringContainsString( '5.2 km', $distance['title'], 'The picker list still shows the distance.' );
		$this->assertSame( '5200', $distance['post_payload']['post_meta']['_outpost_polar_distance_m'] );
	}

	public function test_post_text_includes_details_when_the_site_turns_them_on(): void {
		$this->site_settings = array( 'workout_details_in_text' => true );

		$item = Outpost_Fetch_Recent_Polar::map_exercise_item(
			array( 'id' => 'ex-1', 'sport' => 'RUNNING', 'distance' => 5200.0, 'duration' => 'PT32M0S' ),
			'url'
		);

		$this->assertSame( 'Training — Running, 5.2 km', $item['post_payload']['title'] );
		$this->assertSame( '<p>Training — Running, 5.2 km</p>', $item['post_payload']['content'] );
	}

	public function test_map_exercise_with_only_duration_uses_minutes(): void {
		$exercise = array(
			'id'         => 'ex-2',
			'sport'      => 'STRENGTH_TRAINING',
			'start-time' => '2026-05-03T18:00:00.000',
			'duration'   => 'PT45M30S',
		);

		$item = Outpost_Fetch_Recent_Polar::map_exercise_item( $exercise, 'https://www.polaraccesslink.com/v3/exercises/ex-2' );

		$this->assertStringContainsString( 'Strength Training', $item['title'] );
		$this->assertStringContainsString( '46 min', $item['title'] );
	}

	public function test_map_exercise_without_distance_or_duration(): void {
		$exercise = array(
			'id'         => 'ex-3',
			'sport'      => 'YOGA',
			'start-time' => '2026-05-02T10:00:00.000',
		);

		$item = Outpost_Fetch_Recent_Polar::map_exercise_item( $exercise, 'url' );

		$this->assertStringContainsString( 'Yoga', $item['title'] );
		$this->assertStringNotContainsString( 'km', $item['title'] );
		$this->assertStringNotContainsString( 'min', $item['title'] );
	}

	// ---------- Transaction model ----------

	public function test_start_transaction_extracts_id_from_body(): void {
		Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests(
			static function ( $method, $url, $token, $body ) {
				if ( 'POST' === $method && str_contains( $url, '/exercise-transactions' ) ) {
					return array( 'transaction-id' => '12345' );
				}
				return null;
			}
		);

		$txn = Outpost_Fetch_Recent_Polar::start_transaction( 'fixture-token', 7 );

		$this->assertSame( '12345', $txn );
	}

	public function test_start_transaction_handles_nested_response(): void {
		Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests(
			static function () {
				return array( 'transaction' => array( 'id' => '67890' ) );
			}
		);

		$txn = Outpost_Fetch_Recent_Polar::start_transaction( 'fixture-token', 7 );

		$this->assertSame( '67890', $txn );
	}

	public function test_start_transaction_returns_null_on_failure(): void {
		Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests( static function () { return null; } );

		$txn = Outpost_Fetch_Recent_Polar::start_transaction( 'fixture-token', 7 );

		$this->assertNull( $txn );
	}

	public function test_list_transaction_exercises_handles_string_array(): void {
		Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests(
			static function () {
				return array(
					'exercises' => array(
						'https://www.polaraccesslink.com/v3/users/7/exercise-transactions/txn-1/exercises/ex-1',
						'https://www.polaraccesslink.com/v3/users/7/exercise-transactions/txn-1/exercises/ex-2',
					),
				);
			}
		);

		$urls = Outpost_Fetch_Recent_Polar::list_transaction_exercises( 'fixture-token', 7, 'txn-1' );

		$this->assertCount( 2, $urls );
		$this->assertStringContainsString( 'ex-1', $urls[0] );
	}

	public function test_list_transaction_exercises_handles_object_array(): void {
		Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests(
			static function () {
				return array(
					'exercises' => array(
						array( 'url' => 'https://www.polaraccesslink.com/v3/users/7/exercise-transactions/txn-1/exercises/ex-3' ),
					),
				);
			}
		);

		$urls = Outpost_Fetch_Recent_Polar::list_transaction_exercises( 'fixture-token', 7, 'txn-1' );

		$this->assertCount( 1, $urls );
		$this->assertStringContainsString( 'ex-3', $urls[0] );
	}

	public function test_list_transaction_exercises_drops_urls_outside_this_transaction(): void {
		Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests(
			static function () {
				return array(
					'exercises' => array(
						'https://attacker.example/v3/users/7/exercise-transactions/txn-1/exercises/ex-1',
						'https://www.polaraccesslink.com/v3/users/8/exercise-transactions/txn-1/exercises/ex-2',
						'https://www.polaraccesslink.com/v3/users/7/exercise-transactions/txn-1/exercises/ex-3',
					),
				);
			}
		);

		$urls = Outpost_Fetch_Recent_Polar::list_transaction_exercises( 'fixture-token', 7, 'txn-1' );

		$this->assertSame( array( 'https://www.polaraccesslink.com/v3/users/7/exercise-transactions/txn-1/exercises/ex-3' ), $urls );
	}

	public function test_fetch_items_does_not_commit_transaction(): void {
		// Track that no PUT request is issued during the list flow.
		$state    = (object) array( 'methods' => array() );
		$this->stub_credentials_for_user( 7, 'fixture-polar-token' );
		Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests(
			static function ( $method, $url, $token, $body ) use ( $state ) {
				$state->methods[] = $method;
				if ( 'POST' === $method && str_contains( $url, '/exercise-transactions' ) ) {
					return array( 'transaction-id' => 'txn-test' );
				}
				if ( 'GET' === $method && str_ends_with( $url, '/exercise-transactions/txn-test' ) ) {
					return array(
						'exercises' => array( 'https://www.polaraccesslink.com/v3/users/7/exercise-transactions/txn-test/exercises/ex-9' ),
					);
				}
				if ( 'GET' === $method && str_contains( $url, '/exercises/ex-9' ) ) {
					return array(
						'id'         => 'ex-9',
						'sport'      => 'CYCLING',
						'start-time' => '2026-05-04T08:00:00.000',
						'distance'   => 20000.0,
						'duration'   => 'PT60M0S',
					);
				}
				return null;
			}
		);
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

		$items = Outpost_Fetch_Recent_Polar::fetch_items( 10 );

		$this->assertCount( 1, $items );
		$this->assertStringContainsString( 'Cycling', $items[0]['title'] );
		$this->assertNotContains( 'PUT', $state->methods, 'Picker must NOT commit the transaction (no PUT).' );
	}

	public function test_fetch_items_starts_then_lists_then_fetches(): void {
		$this->stub_credentials_for_user( 7, 'fixture-polar-token' );
		Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests(
			static function ( $method, $url ) {
				if ( 'POST' === $method ) {
					return array( 'transaction-id' => 'txn-1' );
				}
				if ( str_ends_with( $url, '/exercise-transactions/txn-1' ) ) {
					return array(
						'exercises' => array(
							'https://www.polaraccesslink.com/v3/users/7/exercise-transactions/txn-1/exercises/a',
							'https://www.polaraccesslink.com/v3/users/7/exercise-transactions/txn-1/exercises/b',
						),
					);
				}
				if ( str_ends_with( $url, '/exercises/a' ) ) {
					return array(
						'id'         => 'a',
						'sport'      => 'RUNNING',
						'start-time' => '2026-05-04T07:00:00.000',
						'distance'   => 5000.0,
					);
				}
				if ( str_ends_with( $url, '/exercises/b' ) ) {
					return array(
						'id'         => 'b',
						'sport'      => 'RUNNING',
						'start-time' => '2026-05-05T07:00:00.000',
						'distance'   => 6000.0,
					);
				}
				return null;
			}
		);
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

		$items = Outpost_Fetch_Recent_Polar::fetch_items( 10 );

		$this->assertCount( 2, $items );
		// Sorted descending by start time.
		$this->assertSame( 'polar-exercise-b', $items[0]['id'] );
		$this->assertSame( 'polar-exercise-a', $items[1]['id'] );
	}

	public function test_fetch_items_returns_empty_when_transaction_fails(): void {
		$this->stub_credentials_for_user( 7, 'fixture-polar-token' );
		Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests(
			static function ( $method ) {
				if ( 'POST' === $method ) {
					return null; // 204 / failure
				}
				return null;
			}
		);
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

		$items = Outpost_Fetch_Recent_Polar::fetch_items( 10 );

		$this->assertSame( array(), $items );
	}

	public function test_fetch_items_returns_empty_when_not_connected(): void {
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		WP_Mock::userFunction( 'get_user_meta' )->andReturn( '' );

		$items = Outpost_Fetch_Recent_Polar::fetch_items( 10 );

		$this->assertSame( array(), $items );
	}

	private function stub_credentials_for_user( int $user_id, string $access_token ): void {
		$creds = array( 'access_token' => $access_token );
		\Outpost_Encryption_Key_Resolver::reset_for_tests();
		// One get_option() mock per test (WP_Mock gotcha); setUp() registers it.
		$this->other_option = base64_encode( random_bytes( 32 ) );
		$envelope = \Outpost_Encryption::encrypt( wp_json_encode( $creds ) );
		WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			static function ( $uid, $key, $single ) use ( $user_id, $envelope ) {
				if ( $uid === $user_id && 'outpost_creds_polar' === $key ) {
					return $envelope;
				}
				return '';
			}
		);
	}
}
