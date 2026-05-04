<?php
/**
 * Unit tests for Outpost_Source_Registry — register, all,
 * find_for_url, get_by_id, get_extractor.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Registry;
use Outpost_Source_Unknown;
use WP_Mock;

require_once dirname( __DIR__ ) . '/fixtures/source-test-fakes.php';

final class SourceRegistryTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Source_Registry::reset_for_tests();
		// F2 #10 / A2 #8 static-state reset.
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Source_Registry::reset_for_tests();
	}

	public function test_all_returns_unknown_after_bootstrap(): void {
		$sources = Outpost_Source_Registry::all();
		$this->assertCount( 1, $sources );
		$this->assertSame( Outpost_Source_Unknown::ID, $sources[0]->capabilities()['id'] );
	}

	public function test_find_for_url_falls_back_to_unknown_for_arbitrary_host(): void {
		$source = Outpost_Source_Registry::find_for_url( 'https://example.com/page' );
		$this->assertNotNull( $source );
		$this->assertSame( Outpost_Source_Unknown::ID, $source->capabilities()['id'] );
	}

	public function test_concrete_registration_takes_precedence_over_fallback(): void {
		// Direct-registration variant of the action-based init flow.
		// Production calls `do_action('outpost_sources_init')` from inside
		// `ensure_bootstrapped()`, but WP_Mock doesn't dispatch listeners
		// for arbitrary actions in unit tests. The registry's invariant
		// that early registrations win is what matters; the action is
		// just the wiring. Production behavior is exercised in the
		// integration test stubs in `tests/integration/`.
		Outpost_Source_Registry::register(
			new \Outpost_F5TestSource_Stub(
				array(
					'id'            => 'concrete-test',
					'host_patterns' => array( 'example.com' ),
				)
			)
		);

		$source = Outpost_Source_Registry::find_for_url( 'https://example.com/' );
		$this->assertNotNull( $source );
		$this->assertSame( 'concrete-test', $source->capabilities()['id'] );

		// Other URLs still fall back to Unknown (which gets registered
		// during ensure_bootstrapped on first lookup).
		$other = Outpost_Source_Registry::find_for_url( 'https://other.test/' );
		$this->assertSame( Outpost_Source_Unknown::ID, $other->capabilities()['id'] );
	}

	public function test_get_by_id_returns_matching_source(): void {
		$found = Outpost_Source_Registry::get_by_id( Outpost_Source_Unknown::ID );
		$this->assertNotNull( $found );
		$this->assertSame( Outpost_Source_Unknown::ID, $found->capabilities()['id'] );
	}

	public function test_get_by_id_returns_null_for_unknown_id(): void {
		$this->assertNull( Outpost_Source_Registry::get_by_id( 'no-such-source' ) );
	}

	public function test_register_rejects_duplicate_ids(): void {
		Outpost_Source_Registry::register(
			new \Outpost_F5TestSource_Stub( array( 'id' => 'duplicate' ) )
		);
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate' );
		Outpost_Source_Registry::register(
			new \Outpost_F5TestSource_Stub( array( 'id' => 'duplicate' ) )
		);
	}

	public function test_register_rejects_malformed_host_pattern_at_register_time(): void {
		$this->expectException( \InvalidArgumentException::class );
		Outpost_Source_Registry::register(
			new \Outpost_F5TestSource_Stub(
				array(
					'id'            => 'bad-pattern',
					'host_patterns' => array( 'https://example.com' ),
				)
			)
		);
	}

	public function test_register_rejects_empty_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		Outpost_Source_Registry::register(
			new \Outpost_F5TestSource_Stub( array( 'id' => '' ) )
		);
	}

	public function test_get_extractor_returns_oembed_instance(): void {
		$ext = Outpost_Source_Registry::get_extractor( 'oembed' );
		$this->assertInstanceOf( \Outpost_Source_Extractor_Oembed::class, $ext );
	}

	public function test_get_extractor_returns_stub_instances_for_other_types(): void {
		foreach ( array( 'og_tags', 'mf2', 'rss', 'api_json', 'api_xml', 'composite' ) as $type ) {
			$ext = Outpost_Source_Registry::get_extractor( $type );
			$this->assertInstanceOf( \Outpost_Source_Extractor_Base::class, $ext );
			$this->assertSame( $type, $ext->id() );
		}
	}

	public function test_get_extractor_returns_null_for_unknown_type(): void {
		$this->assertNull( Outpost_Source_Registry::get_extractor( 'no-such-extractor' ) );
	}
}
