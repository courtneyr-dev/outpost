<?php
/**
 * Unit tests for Outpost_Fetch_Recent_Whoop (G11b-consumer).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Fetch_Recent_Whoop;
use ReflectionClass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class FetchRecentWhoopTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		$ref  = new ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
		Outpost_Fetch_Recent_Whoop::set_http_resolver_for_tests( null );
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing( static function ( $s ) { return (string) $s; } );
	}

	public function tearDown(): void {
		Outpost_Fetch_Recent_Whoop::set_http_resolver_for_tests( null );
		WP_Mock::tearDown();
	}

	public function test_provider_appends_to_registry(): void {
		$result = Outpost_Fetch_Recent_Whoop::add_to_registry( array() );

		$this->assertArrayHasKey( 'whoop', $result );
		$this->assertSame( 'publish_posts', $result['whoop']['capability'] );
		$this->assertSame( 'whoop', $result['whoop']['oauth_provider'] );
		$this->assertTrue( is_callable( $result['whoop']['callback'] ) );
	}

	public function test_provider_handles_non_array_input(): void {
		$result = Outpost_Fetch_Recent_Whoop::add_to_registry( 'not-an-array' );

		$this->assertArrayHasKey( 'whoop', $result );
	}

	public function test_map_cycle_item_includes_strain_in_title(): void {
		$cycle = array(
			'id'    => 'cyc-1',
			'start' => '2026-05-04T00:00:00+00:00',
			'score' => array( 'strain' => 14.7 ),
		);

		$item = Outpost_Fetch_Recent_Whoop::map_cycle_item( $cycle );

		$this->assertSame( 'whoop-cycle-cyc-1', $item['id'] );
		$this->assertStringContainsString( 'Strain 14.7/21', $item['title'] );
		$this->assertSame( 'note', $item['post_kind'] );
		$this->assertSame( '2026-05-04T00:00:00+00:00', $item['fetched_at'] );
		$this->assertSame( '14.7', $item['post_payload']['post_meta']['_outpost_whoop_strain'] );
	}

	public function test_map_cycle_item_handles_top_level_strain_field(): void {
		$cycle = array(
			'id'     => 'cyc-2',
			'start'  => '2026-05-03T00:00:00+00:00',
			'strain' => 18.2,
		);

		$item = Outpost_Fetch_Recent_Whoop::map_cycle_item( $cycle );

		$this->assertStringContainsString( '18.2/21', $item['title'] );
	}

	public function test_map_recovery_item_includes_date_and_percent(): void {
		$recovery = array(
			'cycle_id'   => 'cyc-99',
			'created_at' => '2026-05-04T07:30:00+00:00',
			'score'      => array( 'recovery_score' => 78.0 ),
		);

		$item = Outpost_Fetch_Recent_Whoop::map_recovery_item( $recovery );

		$this->assertSame( 'whoop-recovery-cyc-99', $item['id'] );
		$this->assertStringContainsString( '2026-05-04', $item['title'] );
		$this->assertStringContainsString( '78%', $item['title'] );
		$this->assertSame( 'note', $item['post_kind'] );
	}

	public function test_fetch_items_combines_and_sorts_descending(): void {
		$this->stub_credentials_for_user( 7, 'fixture-whoop-token' );
		Outpost_Fetch_Recent_Whoop::set_http_resolver_for_tests(
			static function ( $url ) {
				if ( str_contains( $url, '/cycle' ) ) {
					return array(
						'records' => array(
							array(
								'id'    => 'old-c',
								'start' => '2026-05-01T00:00:00+00:00',
								'score' => array( 'strain' => 10.5 ),
							),
						),
					);
				}
				return array(
					'records' => array(
						array(
							'cycle_id'   => 'rec-new',
							'created_at' => '2026-05-05T08:00:00+00:00',
							'score'      => array( 'recovery_score' => 88.0 ),
						),
					),
				);
			}
		);
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

		$items = Outpost_Fetch_Recent_Whoop::fetch_items( 10 );

		$this->assertCount( 2, $items );
		$this->assertStringContainsString( 'Recovery', $items[0]['title'] );
		$this->assertStringContainsString( 'Cycle', $items[1]['title'] );
	}

	public function test_fetch_items_caps_to_count(): void {
		$this->stub_credentials_for_user( 7, 'fixture-whoop-token' );
		Outpost_Fetch_Recent_Whoop::set_http_resolver_for_tests(
			static function ( $url ) {
				if ( str_contains( $url, '/cycle' ) ) {
					return array(
						'records' => array(
							array( 'id' => 'c1', 'start' => '2026-05-04T00:00:00+00:00', 'score' => array( 'strain' => 10.0 ) ),
							array( 'id' => 'c2', 'start' => '2026-05-03T00:00:00+00:00', 'score' => array( 'strain' => 12.0 ) ),
							array( 'id' => 'c3', 'start' => '2026-05-02T00:00:00+00:00', 'score' => array( 'strain' => 14.0 ) ),
						),
					);
				}
				return array( 'records' => array() );
			}
		);
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

		$items = Outpost_Fetch_Recent_Whoop::fetch_items( 2 );

		$this->assertCount( 2, $items );
	}

	public function test_fetch_items_returns_empty_when_not_connected(): void {
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		WP_Mock::userFunction( 'get_user_meta' )->andReturn( '' );

		$items = Outpost_Fetch_Recent_Whoop::fetch_items( 10 );

		$this->assertSame( array(), $items );
	}

	public function test_fetch_items_handles_failed_api_gracefully(): void {
		$this->stub_credentials_for_user( 7, 'fixture-whoop-token' );
		Outpost_Fetch_Recent_Whoop::set_http_resolver_for_tests(
			static function () {
				return null; // Simulates non-2xx → api_get returns null.
			}
		);
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );

		$items = Outpost_Fetch_Recent_Whoop::fetch_items( 10 );

		$this->assertSame( array(), $items );
	}

	private function stub_credentials_for_user( int $user_id, string $access_token ): void {
		$creds = array( 'access_token' => $access_token );
		\Outpost_Encryption_Key_Resolver::reset_for_tests();
		WP_Mock::userFunction( 'get_option' )->andReturn( base64_encode( random_bytes( 32 ) ) );
		$envelope = \Outpost_Encryption::encrypt( wp_json_encode( $creds ) );
		WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			static function ( $uid, $key, $single ) use ( $user_id, $envelope ) {
				if ( $uid === $user_id && 'outpost_creds_whoop' === $key ) {
					return $envelope;
				}
				return '';
			}
		);
	}
}
