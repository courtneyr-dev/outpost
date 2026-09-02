<?php
/**
 * Outpost_POSSE_Registry unit tests (G3.5b).
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_POSSE_Destination_Base;
use Outpost_POSSE_Registry;
use ReflectionClass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Test-only destination factory.
 */
final class G35bRegistryFakeDestination extends Outpost_POSSE_Destination_Base {

	private string $id;

	public function __construct( string $id ) {
		$this->id = $id;
	}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return ucfirst( $this->id );
	}

	public function provider_id(): string {
		return '';
	}

	public function dispatch( int $post_id ): array {
		return self::success_result( 'https://example.com/' . $post_id );
	}
}

final class PosseRegistryTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		$ref  = new ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
		Outpost_POSSE_Registry::reset_for_tests();
	}

	public function tearDown(): void {
		Outpost_POSSE_Registry::reset_for_tests();
		WP_Mock::tearDown();
	}

	public function test_register_and_get_returns_destination(): void {
		$dest = new G35bRegistryFakeDestination( 'beehiiv' );
		Outpost_POSSE_Registry::register( $dest );

		$this->assertSame( $dest, Outpost_POSSE_Registry::get( 'beehiiv' ) );
	}

	public function test_get_returns_null_for_unknown_id(): void {
		$this->assertNull( Outpost_POSSE_Registry::get( 'nonexistent' ) );
	}

	public function test_register_is_idempotent_overwrites(): void {
		$first  = new G35bRegistryFakeDestination( 'mastodon' );
		$second = new G35bRegistryFakeDestination( 'mastodon' );

		Outpost_POSSE_Registry::register( $first );
		Outpost_POSSE_Registry::register( $second );

		$this->assertSame( $second, Outpost_POSSE_Registry::get( 'mastodon' ) );
	}

	public function test_all_returns_every_registered_destination(): void {
		Outpost_POSSE_Registry::register( new G35bRegistryFakeDestination( 'alpha' ) );
		Outpost_POSSE_Registry::register( new G35bRegistryFakeDestination( 'beta' ) );

		$all = Outpost_POSSE_Registry::all();

		$this->assertCount( 2, $all );
		$this->assertArrayHasKey( 'alpha', $all );
		$this->assertArrayHasKey( 'beta', $all );
	}

	public function test_all_applies_outpost_posse_destinations_filter(): void {
		Outpost_POSSE_Registry::register( new G35bRegistryFakeDestination( 'real' ) );

		$injected = new G35bRegistryFakeDestination( 'filter-injected' );
		WP_Mock::onFilter( 'outpost_posse_destinations' )
			->withAnyArgs()
			->reply(
				array(
					'real'            => new G35bRegistryFakeDestination( 'real' ),
					'filter-injected' => $injected,
				)
			);

		$all = Outpost_POSSE_Registry::all();

		$this->assertArrayHasKey( 'filter-injected', $all );
		$this->assertSame( $injected, $all['filter-injected'] );
	}

	public function test_all_drops_non_destination_filter_entries(): void {
		Outpost_POSSE_Registry::register( new G35bRegistryFakeDestination( 'keep' ) );

		WP_Mock::onFilter( 'outpost_posse_destinations' )
			->withAnyArgs()
			->reply(
				array(
					'keep'  => new G35bRegistryFakeDestination( 'keep' ),
					'junk'  => 'not-an-instance',
					42      => new G35bRegistryFakeDestination( 'numeric-key' ),
				)
			);

		$all = Outpost_POSSE_Registry::all();

		$this->assertArrayHasKey( 'keep', $all );
		$this->assertArrayNotHasKey( 'junk', $all );
		$this->assertArrayNotHasKey( 42, $all );
	}

	public function test_all_falls_back_when_filter_returns_non_array(): void {
		Outpost_POSSE_Registry::register( new G35bRegistryFakeDestination( 'fallback' ) );

		WP_Mock::onFilter( 'outpost_posse_destinations' )
			->withAnyArgs()
			->reply( 'not-an-array' );

		$all = Outpost_POSSE_Registry::all();

		$this->assertArrayHasKey( 'fallback', $all );
	}

	public function test_reset_for_tests_clears_registry(): void {
		Outpost_POSSE_Registry::register( new G35bRegistryFakeDestination( 'transient' ) );
		Outpost_POSSE_Registry::reset_for_tests();

		$this->assertNull( Outpost_POSSE_Registry::get( 'transient' ) );
	}
}
