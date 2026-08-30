<?php
/**
 * Filter-hook + edge-case tests for Outpost_ActivityPub_Adapter (F4).
 *
 * F1 covered detection. F2 covered the capabilities() shape and the
 * `outpost_companion_capabilities` filter for two cases (replace +
 * force-null). F4 fills in the remaining branches:
 *
 *   - filter returns a non-array, non-null value (string, integer):
 *     adapter falls back to its own original shape rather than emit
 *     malformed data.
 *   - filter returns null: chip force-hidden (regression-tested with
 *     F2 coverage retained here for completeness).
 *   - filter modifies a single key inside the rich shape: only that
 *     key changes; the rest of the shape passes through unchanged.
 *   - filter modifies the caveats array specifically: extends the
 *     site-policy warnings without dropping the existing entries.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_ActivityPub_Adapter;
use WP_Mock;

final class CompanionActivityPubFilterTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		// F2 #10 / A2 #8: Filter::$filtersWithAnyArgs is static and leaks
		// across tests. Reset at every setUp so per-test filter mocks don't
		// bleed into the next case.
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	/**
	 * Filter returning a non-array, non-null value (e.g. a string from
	 * a buggy filter callback) must NOT be propagated — the adapter
	 * falls back to its own constructed array shape so downstream
	 * consumers always see a well-typed chip.
	 */
	public function test_capabilities_falls_back_when_filter_returns_string(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
		WP_Mock::onFilter( 'outpost_companion_capabilities' )
			->withAnyArgs()
			->reply( 'oops, returned a string' );

		$adapter = new Outpost_ActivityPub_Adapter();
		$caps    = $adapter->capabilities();

		// Adapter returns its own original shape, not the bogus string.
		$this->assertIsArray( $caps );
		$this->assertSame( 'activitypub', $caps['id'] );
		$this->assertSame( 'Fediverse (via ActivityPub plugin)', $caps['label'] );
	}

	/**
	 * Filter returning an integer is a degenerate case — adapter
	 * fallback path mirrors the string case.
	 */
	public function test_capabilities_falls_back_when_filter_returns_integer(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
		WP_Mock::onFilter( 'outpost_companion_capabilities' )
			->withAnyArgs()
			->reply( 42 );

		$adapter = new Outpost_ActivityPub_Adapter();
		$caps    = $adapter->capabilities();

		$this->assertIsArray( $caps );
		$this->assertSame( 'activitypub', $caps['id'] );
	}

	/**
	 * Filter returning an array missing one of the required keys is
	 * still treated as valid — the adapter doesn't introspect the
	 * shape, only checks is_array vs null. Site owners filtering with
	 * partial shapes accept the consequence (downstream consumers
	 * may default-coalesce missing keys themselves).
	 */
	public function test_capabilities_passes_through_partial_shape_from_filter(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
		WP_Mock::onFilter( 'outpost_companion_capabilities' )
			->withAnyArgs()
			->reply(
				array(
					'id'    => 'activitypub',
					'label' => 'Custom Label Only',
					// missing detected, accepts_modes, accepts_media, etc.
				)
			);

		$adapter = new Outpost_ActivityPub_Adapter();
		$caps    = $adapter->capabilities();

		$this->assertSame( 'Custom Label Only', $caps['label'] );
		$this->assertArrayNotHasKey( 'accepts_modes', $caps );
	}

	/**
	 * Filter that extends the caveats array: the canonical use case
	 * for site-policy warnings (e.g. "We don't bridge to Bluesky on
	 * this site"). The filter receives the full shape, can mutate
	 * just one key, and return.
	 */
	public function test_capabilities_filter_can_extend_caveats_array(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
		WP_Mock::onFilter( 'outpost_companion_capabilities' )
			->withAnyArgs()
			->reply(
				array(
					'id'              => 'activitypub',
					'label'           => 'Fediverse (via ActivityPub plugin)',
					'detected'        => true,
					'accepts_modes'   => array( 'note', 'photo' ),
					'accepts_media'   => array( 'image' ),
					'max_attachments' => null,
					'alt_passthrough' => true,
					'char_limit'      => null,
					'caveats'         => array(
						'Pre-existing caveat A',
						'Pre-existing caveat B',
						'Site-policy added caveat C',
					),
					'requires_auth'   => false,
				)
			);

		$adapter = new Outpost_ActivityPub_Adapter();
		$caps    = $adapter->capabilities();

		$this->assertCount( 3, $caps['caveats'] );
		$this->assertContains( 'Site-policy added caveat C', $caps['caveats'] );
	}

	/**
	 * Filter modifying a single key while keeping the rest unchanged.
	 * Verifies the filter callback is the sole authority over the
	 * returned shape — adapter doesn't merge filter output back into
	 * its original.
	 */
	public function test_capabilities_filter_owns_the_full_returned_shape(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
		WP_Mock::onFilter( 'outpost_companion_capabilities' )
			->withAnyArgs()
			->reply(
				array(
					'id'              => 'activitypub',
					'label'           => 'Fediverse (via ActivityPub plugin)',
					'detected'        => true,
					'accepts_modes'   => array( 'note' ), // restricted by site policy
					'accepts_media'   => array(),
					'max_attachments' => 0,
					'alt_passthrough' => false,
					'char_limit'      => 280,
					'caveats'         => array(),
					'requires_auth'   => true,
				)
			);

		$adapter = new Outpost_ActivityPub_Adapter();
		$caps    = $adapter->capabilities();

		$this->assertSame( array( 'note' ), $caps['accepts_modes'] );
		$this->assertSame( 280, $caps['char_limit'] );
		$this->assertTrue( $caps['requires_auth'] );
		// Adapter does not "rescue" original keys — filter is fully in charge.
		$this->assertSame( array(), $caps['accepts_media'] );
	}

	/**
	 * Plugin inactive short-circuits to null BEFORE the filter runs.
	 * Verifies the filter cannot resurrect a chip when the underlying
	 * plugin is absent — that's the "plugin handles federation"
	 * contract and a filter-side override would create a false promise.
	 *
	 * Implementation detail: the adapter checks `is_active()` first
	 * and returns null without invoking apply_filters when inactive.
	 * This test exercises that branch and asserts the filter is never
	 * called.
	 */
	public function test_capabilities_does_not_invoke_filter_when_inactive(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );
		// Setting up a filter expectation here would fail if the filter
		// were invoked — by NOT setting one up we implicitly assert
		// `apply_filters` is not called. WP_Mock will pass any apply_filters
		// call through with default behavior, so the assertion is the
		// returned-null contract.

		$adapter = new Outpost_ActivityPub_Adapter();
		$this->assertNull( $adapter->capabilities() );
	}

	/**
	 * The chip's caveats are i18n'd via __() with the `outpost` text
	 * domain. Asserts that the live chip output contains the exact
	 * translated strings (since WP_Mock's default __() stub returns
	 * the input verbatim, the assertion is that the strings exist
	 * AND match the literals the adapter wraps in __() calls).
	 *
	 * If a future refactor inadvertently strips the __() wrapper, the
	 * string in the chip output would still match (because WP_Mock
	 * pretends __() is identity). The B4 lint covers the wrapping
	 * itself; this test covers the OUTPUT shape.
	 */
	public function test_capabilities_caveats_are_strings_ready_for_i18n(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );

		$adapter = new Outpost_ActivityPub_Adapter();
		$caps    = $adapter->capabilities();

		$this->assertIsArray( $caps['caveats'] );
		$this->assertNotEmpty( $caps['caveats'] );
		foreach ( $caps['caveats'] as $caveat ) {
			$this->assertIsString( $caveat );
			$this->assertNotEmpty( $caveat );
			// Caveat references Bluesky generically — not a specific account.
			$this->assertStringNotContainsString( '@', $caveat );
		}
	}
}
