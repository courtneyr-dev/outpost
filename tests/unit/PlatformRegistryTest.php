<?php
/**
 * Unit tests for Outpost_Manual_Share_Platform_Registry (F9).
 *
 * Verifies the 10 default platforms register cleanly, the
 * outpost_manual_share_platforms filter is the documented extension
 * point (append, remove, modify), and validation propagates from
 * Platform_Config through registry resolution.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Manual_Share_Platform_Registry;
use Outpost_Manual_Share_Platform_Config;
use Outpost_Manual_Share_Invalid_Config_Exception;
use WP_Mock;

final class PlatformRegistryTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Manual_Share_Platform_Registry::reset_for_tests();
		// F2 #10 / A2 #8 static-state reset.
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Manual_Share_Platform_Registry::reset_for_tests();
	}

	public function test_default_configs_returns_exactly_10_platforms(): void {
		$configs = Outpost_Manual_Share_Platform_Registry::default_configs();
		$this->assertCount( 10, $configs );
	}

	public function test_default_configs_have_expected_ids(): void {
		$ids = array_map(
			static fn( array $c ): string => (string) ( $c['id'] ?? '' ),
			Outpost_Manual_Share_Platform_Registry::default_configs()
		);
		sort( $ids );
		$this->assertSame(
			array(
				'facebook',
				'flickr-manual',
				'instagram-feed',
				'instagram-stories',
				'linkedin',
				'pinterest',
				'reddit-manual',
				'threads',
				'tiktok',
				'x-twitter',
			),
			$ids
		);
	}

	public function test_all_platforms_resolves_each_default_to_a_platform_config(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );

		$resolved = Outpost_Manual_Share_Platform_Registry::all_platforms();

		$this->assertCount( 10, $resolved );
		foreach ( $resolved as $platform ) {
			$this->assertInstanceOf( Outpost_Manual_Share_Platform_Config::class, $platform );
		}
	}

	public function test_filter_can_append_a_custom_platform(): void {
		$defaults = Outpost_Manual_Share_Platform_Registry::default_configs();
		$custom   = array(
			'id'            => 'custom-glass',
			'label'         => 'Glass',
			'icon'          => 'glass',
			'accepts_modes' => array( 'photo' ),
			'caption_via'   => 'clipboard',
			'after_share'   => 'mark_done',
		);
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( array_merge( $defaults, array( $custom ) ) );

		$resolved = Outpost_Manual_Share_Platform_Registry::all_platforms();

		$ids = array_map(
			static fn( Outpost_Manual_Share_Platform_Config $p ): string => $p->id(),
			$resolved
		);
		$this->assertContains( 'custom-glass', $ids );
		$this->assertCount( 11, $resolved );
	}

	public function test_filter_can_remove_a_default_platform(): void {
		$without_instagram = array_values(
			array_filter(
				Outpost_Manual_Share_Platform_Registry::default_configs(),
				static fn( array $c ): bool => 'instagram-feed' !== $c['id']
			)
		);
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( $without_instagram );

		$resolved = Outpost_Manual_Share_Platform_Registry::all_platforms();
		$ids      = array_map(
			static fn( Outpost_Manual_Share_Platform_Config $p ): string => $p->id(),
			$resolved
		);

		$this->assertNotContains( 'instagram-feed', $ids );
		$this->assertCount( 9, $resolved );
	}

	public function test_filter_can_modify_a_default_platform(): void {
		$modified = Outpost_Manual_Share_Platform_Registry::default_configs();
		foreach ( $modified as &$config ) {
			if ( 'pinterest' === $config['id'] ) {
				$config['caveats'] = array( 'Custom caveat for Pinterest' );
			}
		}
		unset( $config );

		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( $modified );

		$resolved  = Outpost_Manual_Share_Platform_Registry::all_platforms();
		$pinterest = null;
		foreach ( $resolved as $p ) {
			if ( 'pinterest' === $p->id() ) {
				$pinterest = $p;
			}
		}
		$this->assertNotNull( $pinterest );
		$this->assertSame(
			array( 'Custom caveat for Pinterest' ),
			$pinterest->to_array()['caveats']
		);
	}

	public function test_malformed_filter_config_throws_invalid_config_exception(): void {
		$malformed = array(
			array(
				'id'    => 'broken-platform',
				'label' => 'Broken',
				// missing required keys
			),
		);
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( $malformed );

		$this->expectException( Outpost_Manual_Share_Invalid_Config_Exception::class );
		Outpost_Manual_Share_Platform_Registry::all_platforms();
	}

	public function test_filter_returning_non_array_falls_back_to_defaults(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( null );

		$resolved = Outpost_Manual_Share_Platform_Registry::all_platforms();
		$this->assertCount( 10, $resolved );
	}

	public function test_resolved_platforms_are_cached_per_request(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );

		$first  = Outpost_Manual_Share_Platform_Registry::all_platforms();
		$second = Outpost_Manual_Share_Platform_Registry::all_platforms();

		// Identity check — same array of objects across calls.
		$this->assertSame( $first, $second );
	}

	public function test_reddit_and_flickr_default_to_prefers_bridgy_true(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );

		$resolved = Outpost_Manual_Share_Platform_Registry::all_platforms();
		$by_id    = array();
		foreach ( $resolved as $p ) {
			$by_id[ $p->id() ] = $p;
		}

		$this->assertTrue( $by_id['reddit-manual']->prefers_bridgy() );
		$this->assertTrue( $by_id['flickr-manual']->prefers_bridgy() );
		$this->assertFalse( $by_id['instagram-feed']->prefers_bridgy() );
		$this->assertFalse( $by_id['facebook']->prefers_bridgy() );
	}

	public function test_default_platforms_all_accept_photo_or_gallery_mode(): void {
		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );

		$resolved = Outpost_Manual_Share_Platform_Registry::all_platforms();
		foreach ( $resolved as $platform ) {
			$modes      = $platform->accepts_modes();
			$has_visual = in_array( 'photo', $modes, true ) || in_array( 'gallery', $modes, true );
			$this->assertTrue(
				$has_visual,
				sprintf( 'Platform %s should accept photo or gallery mode', $platform->id() )
			);
		}
	}
}
