<?php
/**
 * Unit tests for Outpost_Source_Ravelry (G14b-source).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Ravelry;
use ReflectionClass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class SourceRavelryTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		$ref  = new ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( static function ( $url ) { return parse_url( $url ); } );
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing( static function ( $s ) { return (string) $s; } );
		WP_Mock::userFunction( 'esc_html__' )->andReturnUsing( static function ( $s ) { return (string) $s; } );
		WP_Mock::userFunction( 'esc_url' )->andReturnUsing( static function ( $s ) { return (string) $s; } );
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->andReturn( true );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_detects_pattern_url(): void {
		$source = new Outpost_Source_Ravelry();

		$this->assertTrue( $source->matches_url( 'https://www.ravelry.com/patterns/library/foo-bar' ) );
	}

	public function test_detects_project_url(): void {
		$source = new Outpost_Source_Ravelry();

		$this->assertTrue( $source->matches_url( 'https://www.ravelry.com/projects/jane/foo-bar' ) );
	}

	public function test_detects_apex_host(): void {
		$source = new Outpost_Source_Ravelry();

		$this->assertTrue( $source->matches_url( 'https://ravelry.com/patterns/library/foo' ) );
	}

	public function test_does_not_match_other_paths(): void {
		$source = new Outpost_Source_Ravelry();

		$this->assertFalse( $source->matches_url( 'https://www.ravelry.com/people/jane' ) );
		$this->assertFalse( $source->matches_url( 'https://www.ravelry.com/' ) );
		$this->assertFalse( $source->matches_url( 'https://example.com/patterns/library/foo' ) );
	}

	public function test_parse_url_target_for_pattern(): void {
		$target = Outpost_Source_Ravelry::parse_url_target( 'https://www.ravelry.com/patterns/library/sample-shawl' );

		$this->assertSame( 'pattern', $target['kind'] );
		$this->assertSame( 'sample-shawl', $target['slug'] );
	}

	public function test_parse_url_target_for_project(): void {
		$target = Outpost_Source_Ravelry::parse_url_target( 'https://www.ravelry.com/projects/jane/sample-project' );

		$this->assertSame( 'project', $target['kind'] );
		$this->assertSame( 'jane', $target['username'] );
		$this->assertSame( 'sample-project', $target['slug'] );
	}

	public function test_capabilities_shape(): void {
		$source = new Outpost_Source_Ravelry();
		$caps   = $source->capabilities();

		$this->assertSame( 'ravelry', $caps['id'] );
		$this->assertSame( 'unambiguous', $caps['ambiguity'] );
		$this->assertSame( 'note', $caps['mode'] );
		$this->assertSame( 'api_json', $caps['extractor'] );
		$this->assertTrue( $caps['auth_required'] );
	}

	// ---------- Fetch flow ----------

	public function test_fetches_pattern_with_oauth_creds(): void {
		$this->stub_credentials_for_user( 7, 'fixture-ravelry-token' );
		$search   = array(
			'patterns' => array(
				array( 'id' => 12345 ),
			),
		);
		$pattern = array(
			'name'                 => 'Sample Shawl',
			'designer'             => array( 'name' => 'Jane Designer' ),
			'gauge'                => 18.0,
			'gauge_divisor'        => 24.0,
			'row_gauge'            => 24.0,
			'gauge_pattern'        => 'stockinette',
			'yardage'              => 800,
			'yardage_max'          => 1000,
			'pattern_needle_sizes' => array(
				array( 'name' => 'US 6 - 4.0 mm' ),
			),
			'packs'                => array(
				array( 'yarn_name' => 'Cascade 220' ),
			),
			'photos'               => array(
				array(
					'sort_order'  => 1,
					'medium2_url' => 'https://images.ravelrycache.com/sample-shawl.jpg',
				),
			),
		);
		$this->queue_remote_get_responses(
			array(
				array( 200, $search ),
				array( 200, array( 'pattern' => $pattern ) ),
			)
		);

		$result = Outpost_Source_Ravelry::fetch( 'https://www.ravelry.com/patterns/library/sample-shawl', 7 );

		$this->assertTrue( $result['extracted'] );
		$this->assertSame( 'pattern', $result['kind'] );
		$this->assertSame( 'note', $result['post_kind'] );
		$this->assertSame( 'Sample Shawl', $result['title'] );
		$this->assertSame( 'Jane Designer', $result['designer'] );
		$this->assertSame( 'https://images.ravelrycache.com/sample-shawl.jpg', $result['photo_url'] );
		$this->assertSame( 'https://images.ravelrycache.com/sample-shawl.jpg', $result['post_payload']['featured_image_url'] );
		$this->assertStringContainsString( 'Gauge', $result['content'] );
		$this->assertStringContainsString( 'Yardage', $result['content'] );
	}

	public function test_fetches_project_with_oauth_creds(): void {
		$this->stub_credentials_for_user( 7, 'fixture-ravelry-token' );
		$project = array(
			'name'        => 'My Sample Project',
			'status_name' => 'finished',
			'started'     => '2026-04-01',
			'completed'   => '2026-05-01',
			'photos'      => array(
				array(
					'marked_as_primary' => true,
					'medium2_url'       => 'https://images.ravelrycache.com/sample-project.jpg',
				),
			),
		);
		$this->queue_remote_get_responses(
			array(
				array( 200, array( 'project' => $project ) ),
			)
		);

		$result = Outpost_Source_Ravelry::fetch( 'https://www.ravelry.com/projects/jane/my-sample-project', 7 );

		$this->assertTrue( $result['extracted'] );
		$this->assertSame( 'project', $result['kind'] );
		$this->assertSame( 'note', $result['post_kind'] );
		$this->assertSame( 'My Sample Project', $result['title'] );
		$this->assertSame( 'finished', $result['status'] );
		$this->assertSame( 'https://images.ravelrycache.com/sample-project.jpg', $result['photo_url'] );
		$this->assertStringContainsString( 'Status', $result['content'] );
		$this->assertStringContainsString( 'Completed', $result['content'] );
	}

	public function test_private_project_returns_extracted_false(): void {
		$this->stub_credentials_for_user( 7, 'fixture-ravelry-token' );
		$project = array(
			'name'    => 'Secret Project',
			'private' => true,
		);
		$this->queue_remote_get_responses(
			array(
				array( 200, array( 'project' => $project ) ),
			)
		);

		$result = Outpost_Source_Ravelry::fetch( 'https://www.ravelry.com/projects/jane/secret-project', 7 );

		$this->assertFalse( $result['extracted'] );
		$this->assertSame( 'private', $result['reason'] );
	}

	public function test_pattern_without_photo_omits_featured_image(): void {
		$this->stub_credentials_for_user( 7, 'fixture-ravelry-token' );
		$search  = array( 'patterns' => array( array( 'id' => 99 ) ) );
		$pattern = array(
			'name' => 'No Photo Pattern',
			// No photos array.
		);
		$this->queue_remote_get_responses(
			array(
				array( 200, $search ),
				array( 200, array( 'pattern' => $pattern ) ),
			)
		);

		$result = Outpost_Source_Ravelry::fetch( 'https://www.ravelry.com/patterns/library/no-photo-pattern', 7 );

		$this->assertTrue( $result['extracted'] );
		$this->assertNull( $result['photo_url'] );
		$this->assertArrayNotHasKey( 'featured_image_url', $result['post_payload'] );
	}

	public function test_auth_failed_returns_reason(): void {
		$this->stub_credentials_for_user( 7, 'fixture-ravelry-token' );
		$this->queue_remote_get_responses(
			array( array( 401, array() ) )
		);

		$result = Outpost_Source_Ravelry::fetch( 'https://www.ravelry.com/projects/jane/anything', 7 );

		$this->assertFalse( $result['extracted'] );
		$this->assertSame( 'auth_failed', $result['reason'] );
	}

	public function test_transport_failed_returns_reason(): void {
		$this->stub_credentials_for_user( 7, 'fixture-ravelry-token' );
		$this->queue_remote_get_responses(
			array( array( 503, array() ) )
		);

		$result = Outpost_Source_Ravelry::fetch( 'https://www.ravelry.com/projects/jane/anything', 7 );

		$this->assertFalse( $result['extracted'] );
		$this->assertSame( 'transport_failed', $result['reason'] );
	}

	public function test_not_connected_when_credentials_missing(): void {
		WP_Mock::userFunction( 'get_user_meta' )->andReturn( '' );

		$result = Outpost_Source_Ravelry::fetch( 'https://www.ravelry.com/patterns/library/foo', 7 );

		$this->assertFalse( $result['extracted'] );
		$this->assertSame( 'not_connected', $result['reason'] );
	}

	public function test_invalid_url_returns_reason(): void {
		$result = Outpost_Source_Ravelry::fetch( 'https://example.com/foo', 7 );

		$this->assertFalse( $result['extracted'] );
		$this->assertSame( 'invalid_url', $result['reason'] );
	}

	// ---------- Test seams ----------

	private function stub_credentials_for_user( int $user_id, string $access_token ): void {
		$creds = array( 'access_token' => $access_token );
		\Outpost_Encryption_Key_Resolver::reset_for_tests();
		WP_Mock::userFunction( 'get_option' )->andReturn( base64_encode( random_bytes( 32 ) ) );
		$envelope = \Outpost_Encryption::encrypt( wp_json_encode( $creds ) );
		WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			static function ( $uid, $key, $single ) use ( $user_id, $envelope ) {
				if ( $uid === $user_id && 'outpost_creds_ravelry' === $key ) {
					return $envelope;
				}
				return '';
			}
		);
	}

	/**
	 * Queue a sequence of (status, body) wp_remote_get responses.
	 *
	 * @param array<int,array{0:int,1:array<string,mixed>}> $responses
	 */
	private function queue_remote_get_responses( array $responses ): void {
		$state = (object) array( 'i' => 0, 'queue' => $responses );
		WP_Mock::userFunction( 'wp_remote_get' )->andReturnUsing(
			static function () use ( $state ) {
				$entry = $state->queue[ $state->i ] ?? $state->queue[ count( $state->queue ) - 1 ];
				++$state->i;
				return array(
					'response' => array( 'code' => $entry[0], 'message' => '' ),
					'body'     => wp_json_encode( $entry[1] ),
				);
			}
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing(
			static function ( $response ) {
				return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
			}
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing(
			static function ( $response ) {
				return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
			}
		);
		WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
	}
}
