<?php
/**
 * Unit tests for Outpost_Source_Rwg (G12a-source).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Rwg;
use ReflectionClass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class SourceRwgTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		$ref  = new ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( static function ( $url ) { return parse_url( $url ); } );
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing( static function ( $s ) { return (string) $s; } );
		WP_Mock::userFunction( 'esc_url' )->andReturnUsing( static function ( $s ) { return (string) $s; } );
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->andReturn( true );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	// ---------- URL detection ----------

	public function test_detects_trip_url(): void {
		$source = new Outpost_Source_Rwg();

		$this->assertTrue( $source->matches_url( 'https://ridewithgps.com/trips/12345' ) );
	}

	public function test_detects_trip_url_with_www(): void {
		$source = new Outpost_Source_Rwg();

		$this->assertTrue( $source->matches_url( 'https://www.ridewithgps.com/trips/12345' ) );
	}

	public function test_detects_route_url(): void {
		$source = new Outpost_Source_Rwg();

		$this->assertTrue( $source->matches_url( 'https://ridewithgps.com/routes/67890' ) );
	}

	public function test_detects_trip_url_with_json_suffix(): void {
		$source = new Outpost_Source_Rwg();

		$this->assertTrue( $source->matches_url( 'https://ridewithgps.com/trips/12345.json' ) );
	}

	public function test_does_not_match_other_paths(): void {
		$source = new Outpost_Source_Rwg();

		$this->assertFalse( $source->matches_url( 'https://ridewithgps.com/users/foo' ) );
		$this->assertFalse( $source->matches_url( 'https://ridewithgps.com/' ) );
		$this->assertFalse( $source->matches_url( 'https://example.com/trips/123' ) );
	}

	public function test_parse_url_target_returns_trip_id(): void {
		$target = Outpost_Source_Rwg::parse_url_target( 'https://ridewithgps.com/trips/12345' );

		$this->assertSame( 'trip', $target['kind'] );
		$this->assertSame( 12345, $target['id'] );
	}

	public function test_parse_url_target_returns_route_id(): void {
		$target = Outpost_Source_Rwg::parse_url_target( 'https://www.ridewithgps.com/routes/67890' );

		$this->assertSame( 'route', $target['kind'] );
		$this->assertSame( 67890, $target['id'] );
	}

	public function test_capabilities_shape(): void {
		$source = new Outpost_Source_Rwg();
		$caps   = $source->capabilities();

		$this->assertSame( 'ridewithgps', $caps['id'] );
		$this->assertSame( 'unambiguous', $caps['ambiguity'] );
		$this->assertSame( 'workout', $caps['mode'] );
		$this->assertSame( 'api_json', $caps['extractor'] );
		$this->assertTrue( $caps['auth_required'] );
		$this->assertSame( array( 'ridewithgps.com', 'www.ridewithgps.com' ), $caps['host_patterns'] );
	}

	// ---------- Fetch flow ----------

	public function test_fetch_returns_not_connected_when_no_credentials(): void {
		WP_Mock::userFunction( 'get_user_meta' )->andReturn( '' );

		$result = Outpost_Source_Rwg::fetch( 'https://ridewithgps.com/trips/12345', 7 );

		$this->assertFalse( $result['extracted'] );
		$this->assertSame( 'not_connected', $result['reason'] );
	}

	public function test_fetch_returns_invalid_url_for_non_match(): void {
		$result = Outpost_Source_Rwg::fetch( 'https://example.com/foo', 7 );

		$this->assertFalse( $result['extracted'] );
		$this->assertSame( 'invalid_url', $result['reason'] );
	}

	public function test_fetch_trip_with_oauth_creds_returns_canonical_workout_payload(): void {
		$this->stub_credentials_for_user( 7, 'fixture-rwg-token' );
		$trip = array(
			'name'           => 'Morning loop',
			'distance'       => 45200.0,
			'elevation_gain' => 580.0,
			'description'    => 'Commute via the river path.',
			'departed_at'    => '2026-05-04T13:00:00Z',
			'visibility'     => 'everyone',
		);
		$this->stub_remote_get_response( 200, array( 'trip' => $trip ) );

		$result = Outpost_Source_Rwg::fetch( 'https://ridewithgps.com/trips/12345', 7 );

		$this->assertTrue( $result['extracted'] );
		$this->assertSame( 'trip', $result['kind'] );
		$this->assertSame( 'workout', $result['post_kind'] );
		$this->assertSame( 'Morning loop', $result['title'] );
		$this->assertSame( 45200.0, $result['distance_meters'] );
		$this->assertSame( 45.2, $result['distance_km'] );
		$this->assertSame( 28.09, $result['distance_miles'] );
		$this->assertStringContainsString( 'Morning loop', $result['post_payload']['title'] );
		$this->assertSame( 'https://ridewithgps.com/trips/12345', $result['post_payload']['syndication_source_url'] );
	}

	public function test_fetch_route_with_oauth_creds_returns_canonical_note_payload(): void {
		$this->stub_credentials_for_user( 7, 'fixture-rwg-token' );
		$route = array(
			'name'           => 'Big climb practice',
			'distance'       => 30000.0,
			'elevation_gain' => 1200.0,
			'description'    => 'Repeat hill intervals.',
			'visibility'     => 'public_search',
		);
		$this->stub_remote_get_response( 200, array( 'route' => $route ) );

		$result = Outpost_Source_Rwg::fetch( 'https://ridewithgps.com/routes/67890', 7 );

		$this->assertTrue( $result['extracted'] );
		$this->assertSame( 'route', $result['kind'] );
		$this->assertSame( 'note', $result['post_kind'] );
		$this->assertSame( 'Big climb practice', $result['title'] );
		$this->assertSame( 30000.0, $result['distance_meters'] );
		$this->assertSame( 1200.0, $result['elevation_meters'] );
	}

	public function test_private_trip_returns_extracted_false_with_reason(): void {
		$this->stub_credentials_for_user( 7, 'fixture-rwg-token' );
		$trip = array(
			'name'       => 'Secret training',
			'distance'   => 10000.0,
			'visibility' => 'private',
		);
		$this->stub_remote_get_response( 200, array( 'trip' => $trip ) );

		$result = Outpost_Source_Rwg::fetch( 'https://ridewithgps.com/trips/12345', 7 );

		$this->assertFalse( $result['extracted'] );
		$this->assertSame( 'private', $result['reason'] );
	}

	public function test_friends_visibility_treated_as_private(): void {
		$this->stub_credentials_for_user( 7, 'fixture-rwg-token' );
		$trip = array(
			'name'       => 'Friends-only ride',
			'distance'   => 10000.0,
			'visibility' => 'friends',
		);
		$this->stub_remote_get_response( 200, array( 'trip' => $trip ) );

		$result = Outpost_Source_Rwg::fetch( 'https://ridewithgps.com/trips/12345', 7 );

		$this->assertFalse( $result['extracted'] );
		$this->assertSame( 'private', $result['reason'] );
	}

	public function test_auth_failed_returns_extracted_false_with_reason(): void {
		$this->stub_credentials_for_user( 7, 'fixture-rwg-token' );
		$this->stub_remote_get_response( 401, array() );

		$result = Outpost_Source_Rwg::fetch( 'https://ridewithgps.com/trips/12345', 7 );

		$this->assertFalse( $result['extracted'] );
		$this->assertSame( 'auth_failed', $result['reason'] );
	}

	public function test_transport_failed_returns_extracted_false_with_reason(): void {
		$this->stub_credentials_for_user( 7, 'fixture-rwg-token' );
		$this->stub_remote_get_response( 503, array() );

		$result = Outpost_Source_Rwg::fetch( 'https://ridewithgps.com/trips/12345', 7 );

		$this->assertFalse( $result['extracted'] );
		$this->assertSame( 'transport_failed', $result['reason'] );
	}

	public function test_not_found_returns_extracted_false_with_reason(): void {
		$this->stub_credentials_for_user( 7, 'fixture-rwg-token' );
		$this->stub_remote_get_response( 404, array() );

		$result = Outpost_Source_Rwg::fetch( 'https://ridewithgps.com/trips/99999', 7 );

		$this->assertFalse( $result['extracted'] );
		$this->assertSame( 'not_found', $result['reason'] );
	}

	// ---------- Test seams ----------

	private function stub_credentials_for_user( int $user_id, string $access_token ): void {
		// Outpost_Credentials_Store::get reads via get_user_meta + decrypts.
		// For unit tests, simplest to override via the encryption-aware path.
		// We patch get_user_meta to return the encrypted credentials envelope.
		// Use a simple in-memory encryption stub instead — the credentials
		// store has a `set_resolver_for_tests` style but we just stub at
		// the user-meta layer.
		$meta_value = $this->encrypt_for_test( $access_token );
		WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			static function ( $uid, $key, $single ) use ( $user_id, $meta_value ) {
				if ( $uid === $user_id && 'outpost_creds_ridewithgps' === $key ) {
					return $meta_value;
				}
				return '';
			}
		);
	}

	private function encrypt_for_test( string $access_token ): string {
		// Outpost_Credentials_Store reads + decrypts. Build a value that
		// round-trips through the real encryption helper.
		// Simpler: override Outpost_Credentials_Store's static for tests.
		$creds = array(
			'access_token' => $access_token,
		);
		\Outpost_Encryption_Key_Resolver::reset_for_tests();
		WP_Mock::userFunction( 'get_option' )->andReturn( base64_encode( random_bytes( 32 ) ) );
		return \Outpost_Encryption::encrypt( wp_json_encode( $creds ) );
	}

	private function stub_remote_get_response( int $status, array $body ): void {
		$json = wp_json_encode( $body );
		WP_Mock::userFunction( 'wp_remote_get' )->andReturn(
			array(
				'response' => array( 'code' => $status, 'message' => '' ),
				'body'     => $json,
			)
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( $status );
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $json );
		WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
	}
}
