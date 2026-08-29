<?php
/**
 * Unit tests for Outpost_Fetch_Recent_Oura (G11a-consumer).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Fetch_Recent_Oura;
use ReflectionClass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class FetchRecentOuraTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		$ref  = new ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
		Outpost_Fetch_Recent_Oura::set_http_resolver_for_tests( null );
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing( static function ( $s ) { return (string) $s; } );
	}

	public function tearDown(): void {
		Outpost_Fetch_Recent_Oura::set_http_resolver_for_tests( null );
		WP_Mock::tearDown();
	}

	// ---------- Registration ----------

	public function test_provider_appends_to_registry(): void {
		$result = Outpost_Fetch_Recent_Oura::add_to_registry( array() );

		$this->assertArrayHasKey( 'oura', $result );
		$this->assertSame( 'publish_posts', $result['oura']['capability'] );
		$this->assertSame( 'oura', $result['oura']['oauth_provider'] );
		$this->assertTrue( is_callable( $result['oura']['callback'] ) );
	}

	public function test_provider_preserves_existing_entries(): void {
		$existing = array(
			'test' => array(
				'label'    => 'Test',
				'callback' => '__return_null',
			),
		);
		$result   = Outpost_Fetch_Recent_Oura::add_to_registry( $existing );

		$this->assertArrayHasKey( 'test', $result );
		$this->assertArrayHasKey( 'oura', $result );
	}

	public function test_provider_handles_non_array_input(): void {
		$result = Outpost_Fetch_Recent_Oura::add_to_registry( 'not-an-array' );

		$this->assertArrayHasKey( 'oura', $result );
	}

	// ---------- Item shape mapping ----------

	public function test_map_workout_item_uses_distance_in_title_when_present(): void {
		$workout = array(
			'id'             => 'wid-1',
			'activity'       => 'running',
			'distance'       => 5200.0,
			'calories'       => 478.0,
			'start_datetime' => '2026-05-04T07:30:00+00:00',
			'end_datetime'   => '2026-05-04T08:02:00+00:00',
		);

		$item = Outpost_Fetch_Recent_Oura::map_workout_item( $workout );

		$this->assertSame( 'oura-workout-wid-1', $item['id'] );
		$this->assertStringContainsString( 'Running', $item['title'] );
		$this->assertStringContainsString( '5.2 km', $item['title'] );
		$this->assertSame( 'workout', $item['post_kind'] );
		$this->assertStringContainsString( '32 min', $item['subtitle'] );
		$this->assertStringContainsString( '478 kcal', $item['subtitle'] );
		$this->assertSame( '2026-05-04T07:30:00+00:00', $item['fetched_at'] );
	}

	public function test_map_workout_item_omits_distance_when_zero(): void {
		$workout = array(
			'id'             => 'wid-2',
			'activity'       => 'strength_training',
			'distance'       => 0.0,
			'calories'       => 200.0,
			'start_datetime' => '2026-05-04T18:00:00+00:00',
			'end_datetime'   => '2026-05-04T18:45:00+00:00',
		);

		$item = Outpost_Fetch_Recent_Oura::map_workout_item( $workout );

		$this->assertStringContainsString( 'Strength training', $item['title'] );
		$this->assertStringNotContainsString( 'km', $item['title'] );
		$this->assertSame( 'oura-workout-wid-2', $item['id'] );
	}

	public function test_map_sleep_item_includes_hours_and_score(): void {
		$sleep = array(
			'id'                   => 'sid-1',
			'day'                  => '2026-05-04',
			'total_sleep_duration' => 26040, // 7h14m
			'bedtime_start'        => '2026-05-03T22:45:00+00:00',
			'readiness'            => array( 'score' => 82 ),
		);

		$item = Outpost_Fetch_Recent_Oura::map_sleep_item( $sleep );

		$this->assertSame( 'oura-sleep-sid-1', $item['id'] );
		$this->assertStringContainsString( '2026-05-04', $item['title'] );
		$this->assertStringContainsString( '7.2 hours', $item['subtitle'] );
		$this->assertStringContainsString( 'score: 82', $item['subtitle'] );
		$this->assertSame( 'note', $item['post_kind'] );
	}

	// ---------- fetch_items combines + sorts ----------

	public function test_fetch_items_combines_and_sorts_descending(): void {
		$this->stub_credentials_for_user( 7, 'fixture-oura-token' );
		Outpost_Fetch_Recent_Oura::set_http_resolver_for_tests(
			static function ( $url ) {
				if ( str_contains( $url, '/usercollection/workout' ) ) {
					return array(
						'data' => array(
							array(
								'id'             => 'old-w',
								'activity'       => 'walking',
								'distance'       => 1500.0,
								'calories'       => 80.0,
								'start_datetime' => '2026-05-01T10:00:00+00:00',
								'end_datetime'   => '2026-05-01T10:20:00+00:00',
							),
						),
					);
				}
				return array(
					'data' => array(
						array(
							'id'                   => 'new-s',
							'day'                  => '2026-05-05',
							'total_sleep_duration' => 28800,
							'bedtime_start'        => '2026-05-05T22:00:00+00:00',
						),
					),
				);
			}
		);
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

		$items = Outpost_Fetch_Recent_Oura::fetch_items( 10 );

		$this->assertCount( 2, $items );
		$this->assertStringContainsString( 'Sleep', $items[0]['title'] ); // newer
		$this->assertStringContainsString( 'Walking', $items[1]['title'] );
	}

	public function test_fetch_items_caps_to_count(): void {
		$this->stub_credentials_for_user( 7, 'fixture-oura-token' );
		Outpost_Fetch_Recent_Oura::set_http_resolver_for_tests(
			static function ( $url ) {
				if ( str_contains( $url, '/usercollection/workout' ) ) {
					return array(
						'data' => array(
							array( 'id' => 'w1', 'activity' => 'running', 'distance' => 5000.0, 'calories' => 300.0, 'start_datetime' => '2026-05-04T08:00:00+00:00', 'end_datetime' => '2026-05-04T08:30:00+00:00' ),
							array( 'id' => 'w2', 'activity' => 'cycling', 'distance' => 20000.0, 'calories' => 600.0, 'start_datetime' => '2026-05-03T08:00:00+00:00', 'end_datetime' => '2026-05-03T09:00:00+00:00' ),
							array( 'id' => 'w3', 'activity' => 'walking', 'distance' => 1500.0, 'calories' => 80.0, 'start_datetime' => '2026-05-02T10:00:00+00:00', 'end_datetime' => '2026-05-02T10:20:00+00:00' ),
						),
					);
				}
				return array( 'data' => array() );
			}
		);
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

		$items = Outpost_Fetch_Recent_Oura::fetch_items( 2 );

		$this->assertCount( 2, $items );
	}

	public function test_fetch_items_returns_empty_when_not_connected(): void {
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		WP_Mock::userFunction( 'get_user_meta' )->andReturn( '' );

		$items = Outpost_Fetch_Recent_Oura::fetch_items( 10 );

		$this->assertSame( array(), $items );
	}

	public function test_fetch_items_returns_empty_when_no_user(): void {
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );

		$items = Outpost_Fetch_Recent_Oura::fetch_items( 10 );

		$this->assertSame( array(), $items );
	}

	public function test_fetch_items_handles_membership_lapsed_gracefully(): void {
		$this->stub_credentials_for_user( 7, 'fixture-oura-token' );
		Outpost_Fetch_Recent_Oura::set_http_resolver_for_tests(
			static function () {
				// Simulating 403 → api_get returns null → fetch_workouts/sleep return [].
				return null;
			}
		);
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

		$items = Outpost_Fetch_Recent_Oura::fetch_items( 10 );

		$this->assertSame( array(), $items );
	}

	private function stub_credentials_for_user( int $user_id, string $access_token ): void {
		$creds = array( 'access_token' => $access_token );
		\Outpost_Encryption_Key_Resolver::reset_for_tests();
		WP_Mock::userFunction( 'get_option' )->andReturn( base64_encode( random_bytes( 32 ) ) );
		$envelope = \Outpost_Encryption::encrypt( wp_json_encode( $creds ) );
		WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			static function ( $uid, $key, $single ) use ( $user_id, $envelope ) {
				if ( $uid === $user_id && 'outpost_creds_oura' === $key ) {
					return $envelope;
				}
				return '';
			}
		);
	}
}
