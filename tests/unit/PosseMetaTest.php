<?php
/**
 * Outpost_POSSE_Meta unit tests (G3.5b).
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_POSSE_Meta;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class PosseMetaTest extends TestCase {

	/** @var array<string,mixed> */
	private array $store = array();

	public function setUp(): void {
		WP_Mock::setUp();
		$this->store = array();

		$store_ref = &$this->store;
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			static function ( $post_id, $key, $single ) use ( &$store_ref ) {
				return $store_ref[ $post_id . '|' . $key ] ?? '';
			}
		);
		WP_Mock::userFunction( 'update_post_meta' )->andReturnUsing(
			static function ( $post_id, $key, $value ) use ( &$store_ref ) {
				$store_ref[ $post_id . '|' . $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	// ---------- Constants & registration ----------

	public function test_meta_key_constants_match_locked_names(): void {
		$this->assertSame( '_outpost_posse_targets', Outpost_POSSE_Meta::TARGETS );
		$this->assertSame( '_outpost_syndication_urls', Outpost_POSSE_Meta::SYNDICATION_URLS );
		$this->assertSame( '_outpost_posse_failures', Outpost_POSSE_Meta::FAILURES );
		$this->assertSame( '_outpost_posse_in_flight', Outpost_POSSE_Meta::IN_FLIGHT );
	}

	public function test_register_post_meta_keys_method_is_callable(): void {
		// Sanity: register_post_meta_keys exists as a public static method
		// the init action callback resolves to.
		$this->assertTrue(
			is_callable( array( Outpost_POSSE_Meta::class, 'register_post_meta_keys' ) )
		);
	}

	// ---------- Targets ----------

	public function test_get_targets_returns_empty_array_when_unset(): void {
		$this->assertSame( array(), Outpost_POSSE_Meta::get_targets( 1 ) );
	}

	public function test_set_and_get_targets_round_trip(): void {
		Outpost_POSSE_Meta::set_targets( 1, array( 'beehiiv', 'mastodon' ) );

		$this->assertSame( array( 'beehiiv', 'mastodon' ), Outpost_POSSE_Meta::get_targets( 1 ) );
	}

	public function test_set_targets_sanitizes_and_dedupes(): void {
		Outpost_POSSE_Meta::set_targets( 1, array( 'beehiiv', '', 'beehiiv', 'mastodon' ) );

		$this->assertSame( array( 'beehiiv', 'mastodon' ), Outpost_POSSE_Meta::get_targets( 1 ) );
	}

	// ---------- Syndication URLs ----------

	public function test_add_syndication_url_appends_entry(): void {
Outpost_POSSE_Meta::add_syndication_url( 1, 'beehiiv', 'https://beehiiv.example/post/1' );

		$urls = Outpost_POSSE_Meta::get_syndication_urls( 1 );
		$this->assertCount( 1, $urls );
		$this->assertSame( 'beehiiv', $urls[0]['destination_id'] );
		$this->assertSame( 'https://beehiiv.example/post/1', $urls[0]['url'] );
	}

	public function test_add_syndication_url_replaces_per_destination(): void {
Outpost_POSSE_Meta::add_syndication_url( 1, 'beehiiv', 'https://beehiiv.example/post/1-old' );
		Outpost_POSSE_Meta::add_syndication_url( 1, 'beehiiv', 'https://beehiiv.example/post/1-new' );

		$urls = Outpost_POSSE_Meta::get_syndication_urls( 1 );
		$this->assertCount( 1, $urls );
		$this->assertSame( 'https://beehiiv.example/post/1-new', $urls[0]['url'] );
	}

	public function test_add_syndication_url_keeps_other_destinations(): void {
Outpost_POSSE_Meta::add_syndication_url( 1, 'beehiiv', 'https://beehiiv.example/post/1' );
		Outpost_POSSE_Meta::add_syndication_url( 1, 'mastodon', 'https://mastodon.example/@user/123' );

		$urls = Outpost_POSSE_Meta::get_syndication_urls( 1 );
		$this->assertCount( 2, $urls );
	}

	// ---------- Failures ----------

	public function test_add_failure_records_entry_with_attempt_count(): void {
Outpost_POSSE_Meta::add_failure( 1, 'beehiiv', 'auth expired', 3 );

		$failures = Outpost_POSSE_Meta::get_failures( 1 );
		$this->assertCount( 1, $failures );
		$this->assertSame( 'beehiiv', $failures[0]['destination_id'] );
		$this->assertSame( 'auth expired', $failures[0]['error'] );
		$this->assertSame( 3, $failures[0]['attempt_count'] );
	}

	public function test_add_failure_replaces_per_destination(): void {
Outpost_POSSE_Meta::add_failure( 1, 'beehiiv', 'first', 1 );
		Outpost_POSSE_Meta::add_failure( 1, 'beehiiv', 'second', 2 );

		$failures = Outpost_POSSE_Meta::get_failures( 1 );
		$this->assertCount( 1, $failures );
		$this->assertSame( 'second', $failures[0]['error'] );
	}

	// ---------- In-flight ----------

	public function test_add_in_flight_is_idempotent(): void {
		Outpost_POSSE_Meta::add_in_flight( 1, 'beehiiv' );
		Outpost_POSSE_Meta::add_in_flight( 1, 'beehiiv' );

		$this->assertSame( array( 'beehiiv' ), Outpost_POSSE_Meta::get_in_flight( 1 ) );
	}

	public function test_remove_in_flight_drops_only_target_destination(): void {
		Outpost_POSSE_Meta::add_in_flight( 1, 'beehiiv' );
		Outpost_POSSE_Meta::add_in_flight( 1, 'mastodon' );
		Outpost_POSSE_Meta::remove_in_flight( 1, 'beehiiv' );

		$this->assertSame( array( 'mastodon' ), Outpost_POSSE_Meta::get_in_flight( 1 ) );
	}

	public function test_remove_in_flight_when_not_present_is_noop(): void {
		Outpost_POSSE_Meta::add_in_flight( 1, 'mastodon' );
		Outpost_POSSE_Meta::remove_in_flight( 1, 'beehiiv' );

		$this->assertSame( array( 'mastodon' ), Outpost_POSSE_Meta::get_in_flight( 1 ) );
	}

	// ---------- Sanitize helper ----------

	public function test_sanitize_string_array_filters_non_strings(): void {
		$result = Outpost_POSSE_Meta::sanitize_string_array(
			array( 'beehiiv', 42, '', null, true, 'mastodon', 'beehiiv' )
		);

		$this->assertSame( array( 'beehiiv', 'mastodon' ), $result );
	}

	public function test_sanitize_string_array_returns_empty_for_non_array(): void {
		$this->assertSame( array(), Outpost_POSSE_Meta::sanitize_string_array( 'not-an-array' ) );
	}
}
