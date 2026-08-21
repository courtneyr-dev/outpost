<?php
/**
 * Unit tests for Outpost_Companion_Registry + the concrete adapters.
 *
 * Exercises:
 *   - all() returns one instance per default adapter class
 *   - get() caches per class — same class returns same instance
 *   - Adapter file() / label() / feature_slugs() shapes
 *   - all_active_feature_slugs() de-duplicates and sorts
 *   - The outpost_companion_adapters filter prunes unknown classes
 *   - F1: ActivityPub adapter capability-shape (chip)
 *   - F2: per-mode chip filtering, fail-open behavior,
 *     `outpost_companion_capabilities` filter hook
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Companion_Registry;
use Outpost_Companion_Base;
use Outpost_Post_Kinds_Adapter;
use Outpost_XFN_Adapter;
use Outpost_Yoast_Adapter;
use Outpost_ActivityPub_Adapter;
use Outpost_F2TestRestricted_Adapter;
use WP_Mock;

require_once dirname( __DIR__ ) . '/fixtures/companion-restricted-modes.php';

final class CompanionRegistryTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Companion_Registry::reset_for_tests();
		// Workaround for WP_Mock 1.x: Filter::$filtersWithAnyArgs is static
		// and leaks across tests (see CLAUDE.md A2 #8). Without the reset
		// every withAnyArgs() filter set up in a prior test still applies
		// to subsequent tests' apply_filters calls.
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
		// F14: chips_for_mode now iterates Bridgy_Publish_Adapter which
		// reads Bridgy settings via get_option. Default to "no Bridgy
		// configured" so these tests see only the F1+F2 chip surfaces.
		WP_Mock::userFunction( 'get_option' )->andReturn( array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Companion_Registry::reset_for_tests();
	}

	public function test_all_returns_nine_default_adapters(): void {
		// F9 added the manual-share umbrella → 8.
		// F14 added the bridgy-publish umbrella → 9.
		$adapters = Outpost_Companion_Registry::all();
		$this->assertCount( 9, $adapters );
		foreach ( $adapters as $adapter ) {
			$this->assertInstanceOf( Outpost_Companion_Base::class, $adapter );
		}
	}

	public function test_get_caches_by_class(): void {
		$first  = Outpost_Companion_Registry::get( Outpost_XFN_Adapter::class );
		$second = Outpost_Companion_Registry::get( Outpost_XFN_Adapter::class );
		$this->assertSame( $first, $second );
	}

	public function test_post_kinds_adapter_shape(): void {
		$adapter = new Outpost_Post_Kinds_Adapter();
		$this->assertSame( OUTPOST_POST_KINDS_PLUGIN_FILE, $adapter->file() );
		$this->assertSame( 'Post Kinds for IndieWeb', $adapter->label() );
		$slugs = $adapter->feature_slugs();
		// One slug per kind in Post Kinds' default taxonomy registry.
		$this->assertCount( 36, $slugs );
		$this->assertCount( 36, array_unique( $slugs ), 'Feature slugs must not repeat.' );
		foreach ( $slugs as $slug ) {
			$this->assertStringStartsWith( 'post-kinds.', $slug );
		}
		$this->assertContains( 'post-kinds.listen', $slugs );
		$this->assertContains( 'post-kinds.like', $slugs );
		// `quote` matches Post Kinds' actual taxonomy slug — the earlier
		// `post-kinds.quotation` spelling matched nothing real.
		$this->assertContains( 'post-kinds.quote', $slugs );
		$this->assertNotContains( 'post-kinds.quotation', $slugs );
		$this->assertContains( 'post-kinds.event', $slugs );
		$this->assertContains( 'post-kinds.craft', $slugs );
	}

	public function test_xfn_adapter_shape(): void {
		$adapter = new Outpost_XFN_Adapter();
		$this->assertSame( OUTPOST_LINK_EXTENSION_XFN_PLUGIN_FILE, $adapter->file() );
		$this->assertSame( 'Link Extension for XFN', $adapter->label() );
		$this->assertSame( array( 'xfn.relationships' ), $adapter->feature_slugs() );
	}

	public function test_yoast_adapter_shape(): void {
		$adapter = new Outpost_Yoast_Adapter();
		$this->assertSame( OUTPOST_YOAST_PLUGIN_FILE, $adapter->file() );
		$this->assertSame( 'Yoast SEO', $adapter->label() );
		$this->assertSame( array( 'yoast.focus-keyphrase' ), $adapter->feature_slugs() );
	}

	public function test_all_active_feature_slugs_dedupes_and_sorts(): void {
		// Stub is_plugin_active so every adapter reports active.
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
		$slugs = Outpost_Companion_Registry::all_active_feature_slugs();
		// Result is sorted alphabetically and each slug appears exactly once.
		$this->assertSame( $slugs, array_values( array_unique( $slugs ) ) );
		$sorted = $slugs;
		sort( $sorted );
		$this->assertSame( $sorted, $slugs );
		// Spot-check known slugs are present.
		$this->assertContains( 'xfn.relationships', $slugs );
		$this->assertContains( 'yoast.focus-keyphrase', $slugs );
		$this->assertContains( 'post-kinds.listen', $slugs );
	}

	// --- F1/F2: ActivityPub adapter shape ---------------------------------

	public function test_activitypub_adapter_shape(): void {
		$adapter = new Outpost_ActivityPub_Adapter();
		$this->assertSame( OUTPOST_ACTIVITYPUB_PLUGIN_FILE, $adapter->file() );
		$this->assertSame( 'ActivityPub', $adapter->label() );
		$this->assertSame( array( 'activitypub.federate' ), $adapter->feature_slugs() );
	}

	public function test_activitypub_capabilities_returns_full_shape_when_active(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
		// No filter mock — WP_Mock defaults apply_filters to passthrough,
		// so the adapter's array returns unchanged.

		$adapter = new Outpost_ActivityPub_Adapter();
		$caps    = $adapter->capabilities();

		// All required keys present and typed.
		$this->assertIsArray( $caps );
		$this->assertSame( 'activitypub', $caps['id'] );
		$this->assertSame( 'Fediverse (via ActivityPub plugin)', $caps['label'] );
		$this->assertTrue( $caps['detected'] );
		$this->assertIsArray( $caps['accepts_modes'] );
		$this->assertIsArray( $caps['accepts_media'] );
		$this->assertNull( $caps['max_attachments'] );
		$this->assertTrue( $caps['alt_passthrough'] );
		$this->assertNull( $caps['char_limit'] );
		$this->assertIsArray( $caps['caveats'] );
		$this->assertFalse( $caps['requires_auth'] );

		// All 13 composer modes Outpost ships are accepted.
		foreach ( array(
			'note',
			'photo',
			'gallery',
			'article',
			'listen',
			'watch',
			'read',
			'play',
			'checkin',
			'reply',
			'like',
			'repost',
			'bookmark',
		) as $mode ) {
			$this->assertContains( $mode, $caps['accepts_modes'], "Mode '{$mode}' should be accepted." );
		}

		// Caveats are non-empty strings, ready for translation. The
		// caveat references Bluesky generically; no specific account
		// or instance handle.
		$this->assertNotEmpty( $caps['caveats'] );
		foreach ( $caps['caveats'] as $caveat ) {
			$this->assertIsString( $caveat );
			$this->assertNotEmpty( $caveat );
		}
	}

	public function test_activitypub_capabilities_returns_null_when_plugin_inactive(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );
		$adapter = new Outpost_ActivityPub_Adapter();
		$this->assertNull( $adapter->capabilities() );
	}

	public function test_companion_base_default_capabilities_is_null(): void {
		// Adapters that don't override capabilities() must return null,
		// not an empty array — distinct nullity matters for the
		// chips_for_mode() short-circuit and the merger's redundancy
		// check.
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
		$xfn = new Outpost_XFN_Adapter();
		$this->assertNull( $xfn->capabilities() );
	}

	public function test_outpost_companion_capabilities_filter_can_replace_shape(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
		// Filter replaces the chip with a stripped-down version (e.g.
		// site policy says "no Bluesky reach", so caveats removed).
		WP_Mock::onFilter( 'outpost_companion_capabilities' )
			->withAnyArgs()
			->reply(
				array(
					'id'              => 'activitypub',
					'label'           => 'Custom Override',
					'detected'        => true,
					'accepts_modes'   => array( 'note' ),
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

		$this->assertSame( 'Custom Override', $caps['label'] );
		$this->assertSame( array( 'note' ), $caps['accepts_modes'] );
		$this->assertSame( 280, $caps['char_limit'] );
		$this->assertTrue( $caps['requires_auth'] );
	}

	public function test_outpost_companion_capabilities_filter_can_force_null(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
		// Filter returns null = chip is force-hidden even though plugin
		// is active.
		WP_Mock::onFilter( 'outpost_companion_capabilities' )
			->withAnyArgs()
			->reply( null );

		$adapter = new Outpost_ActivityPub_Adapter();
		$this->assertNull( $adapter->capabilities() );
	}

	// --- F2: per-mode chip filtering --------------------------------------

	public function test_chips_for_mode_photo_includes_activitypub_when_detected(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );

		$chips = Outpost_Companion_Registry::chips_for_mode( 'photo' );
		$ids   = array_column( $chips, 'id' );
		$this->assertContains( 'activitypub', $ids );
	}

	public function test_chips_for_mode_note_includes_activitypub_because_ap_accepts_all(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );

		$chips = Outpost_Companion_Registry::chips_for_mode( 'note' );
		$ids   = array_column( $chips, 'id' );
		$this->assertContains( 'activitypub', $ids );
	}

	public function test_chips_for_mode_excludes_restricted_companion_on_unaccepted_mode(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );

		// Inject the photo-only test fixture via the adapters filter.
		WP_Mock::onFilter( 'outpost_companion_adapters' )->withAnyArgs()->reply(
			array(
				Outpost_ActivityPub_Adapter::class,
				Outpost_F2TestRestricted_Adapter::class,
			)
		);

		// Note mode — fixture's accepts_modes is [ 'photo' ] only, so it
		// must be excluded. ActivityPub's accepts_modes includes note.
		$chips = Outpost_Companion_Registry::chips_for_mode( 'note' );
		$ids   = array_column( $chips, 'id' );

		$this->assertNotContains( 'f2-test-restricted', $ids );
		$this->assertContains( 'activitypub', $ids );
	}

	public function test_chips_for_mode_includes_restricted_companion_on_accepted_mode(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
		WP_Mock::onFilter( 'outpost_companion_adapters' )->withAnyArgs()->reply(
			array(
				Outpost_ActivityPub_Adapter::class,
				Outpost_F2TestRestricted_Adapter::class,
			)
		);

		// Photo mode — both companions accept photo.
		$chips = Outpost_Companion_Registry::chips_for_mode( 'photo' );
		$ids   = array_column( $chips, 'id' );

		$this->assertContains( 'f2-test-restricted', $ids );
		$this->assertContains( 'activitypub', $ids );
	}

	public function test_chips_for_mode_excludes_undetected_companions(): void {
		// Plugins absent — no chips, regardless of mode.
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		$chips = Outpost_Companion_Registry::chips_for_mode( 'photo' );
		$this->assertSame( array(), $chips );
	}

	public function test_chips_for_mode_unknown_mode_fails_open(): void {
		// Unknown mode -> registry returns every detected chip without
		// filtering. The composer always sends a known mode; this is a
		// defensive fallback so a typo in a third-party caller doesn't
		// silently hide every destination.
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );
		WP_Mock::onFilter( 'outpost_companion_adapters' )->withAnyArgs()->reply(
			array(
				Outpost_ActivityPub_Adapter::class,
				Outpost_F2TestRestricted_Adapter::class,
			)
		);

		// Mode 'invalid_mode' is not in known_modes(); fail-open returns
		// both detected chips even though the F2 fixture's accepts_modes
		// would have filtered it out under strict-match.
		$chips = Outpost_Companion_Registry::chips_for_mode( 'invalid_mode' );
		$ids   = array_column( $chips, 'id' );

		$this->assertContains( 'activitypub', $ids );
		$this->assertContains( 'f2-test-restricted', $ids );
	}

	public function test_chips_for_mode_null_returns_all_detected_chips(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( true );

		$chips = Outpost_Companion_Registry::chips_for_mode( null );
		$ids   = array_column( $chips, 'id' );

		// ActivityPub contributes one capabilities() chip; F9 manual-
		// share umbrella contributes 10 platform_chips() entries — total 11.
		$this->assertContains( 'activitypub', $ids );
		$this->assertContains( 'instagram-feed', $ids );
		$this->assertContains( 'flickr-manual', $ids );
		$this->assertCount( 11, $chips );
	}

	public function test_known_modes_returns_thirteen_default_modes(): void {
		$modes = Outpost_Companion_Registry::known_modes();
		$this->assertContains( 'note', $modes );
		$this->assertContains( 'bookmark', $modes );
		$this->assertCount( 13, $modes );
	}
}
