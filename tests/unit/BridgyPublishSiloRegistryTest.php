<?php
/**
 * Unit tests for Outpost_Bridgy_Publish_Silo_Registry (F14).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Bridgy_Publish_Silo_Registry;
use Outpost_Bridgy_Publish_Silo_Config;
use Outpost_Bridgy_Publish_Invalid_Config_Exception;
use WP_Mock;

final class BridgyPublishSiloRegistryTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Bridgy_Publish_Silo_Registry::reset_for_tests();
		// F2 #10 / A2 #8 static-state reset.
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Bridgy_Publish_Silo_Registry::reset_for_tests();
	}

	public function test_default_configs_returns_five_silos(): void {
		$configs = Outpost_Bridgy_Publish_Silo_Registry::default_configs();
		$this->assertCount( 5, $configs );
	}

	public function test_default_silos_have_expected_ids(): void {
		$ids = array_map(
			static fn ( array $c ): string => (string) ( $c['id'] ?? '' ),
			Outpost_Bridgy_Publish_Silo_Registry::default_configs()
		);
		sort( $ids );
		$this->assertSame(
			array(
				'bridgy-bluesky',
				'bridgy-flickr',
				'bridgy-github',
				'bridgy-mastodon',
				'bridgy-reddit',
			),
			$ids
		);
	}

	public function test_all_silos_validates_each_default(): void {
		WP_Mock::onFilter( 'outpost_bridgy_publish_silos' )
			->withAnyArgs()
			->reply( Outpost_Bridgy_Publish_Silo_Registry::default_configs() );

		$resolved = Outpost_Bridgy_Publish_Silo_Registry::all_silos();

		$this->assertCount( 5, $resolved );
		foreach ( $resolved as $silo ) {
			$this->assertInstanceOf( Outpost_Bridgy_Publish_Silo_Config::class, $silo );
		}
	}

	public function test_filter_can_append_a_custom_silo(): void {
		$defaults = Outpost_Bridgy_Publish_Silo_Registry::default_configs();
		$custom   = array(
			'id'            => 'bridgy-fake-future-silo',
			'label'         => 'Future Silo',
			'icon'          => 'future',
			'silo_id'       => 'future',
			'bridgy_url'    => 'https://brid.gy/publish/future',
			'accepts_modes' => array( 'note' ),
		);
		WP_Mock::onFilter( 'outpost_bridgy_publish_silos' )
			->withAnyArgs()
			->reply( array_merge( $defaults, array( $custom ) ) );

		$resolved = Outpost_Bridgy_Publish_Silo_Registry::all_silos();
		$ids      = array_map(
			static fn ( Outpost_Bridgy_Publish_Silo_Config $s ): string => $s->id(),
			$resolved
		);
		$this->assertContains( 'bridgy-fake-future-silo', $ids );
		$this->assertCount( 6, $resolved );
	}

	public function test_filter_can_remove_a_default_silo(): void {
		$without_github = array_values(
			array_filter(
				Outpost_Bridgy_Publish_Silo_Registry::default_configs(),
				static fn ( array $c ): bool => 'bridgy-github' !== $c['id']
			)
		);
		WP_Mock::onFilter( 'outpost_bridgy_publish_silos' )
			->withAnyArgs()
			->reply( $without_github );

		$resolved = Outpost_Bridgy_Publish_Silo_Registry::all_silos();
		$ids      = array_map(
			static fn ( Outpost_Bridgy_Publish_Silo_Config $s ): string => $s->id(),
			$resolved
		);
		$this->assertNotContains( 'bridgy-github', $ids );
		$this->assertCount( 4, $resolved );
	}

	public function test_malformed_filter_config_throws(): void {
		WP_Mock::onFilter( 'outpost_bridgy_publish_silos' )
			->withAnyArgs()
			->reply( array( array( 'id' => 'broken' ) ) );

		$this->expectException( Outpost_Bridgy_Publish_Invalid_Config_Exception::class );
		Outpost_Bridgy_Publish_Silo_Registry::all_silos();
	}

	public function test_filter_returning_non_array_falls_back_to_defaults(): void {
		WP_Mock::onFilter( 'outpost_bridgy_publish_silos' )
			->withAnyArgs()
			->reply( null );

		$resolved = Outpost_Bridgy_Publish_Silo_Registry::all_silos();
		$this->assertCount( 5, $resolved );
	}

	public function test_find_by_id_returns_config_when_present(): void {
		WP_Mock::onFilter( 'outpost_bridgy_publish_silos' )
			->withAnyArgs()
			->reply( Outpost_Bridgy_Publish_Silo_Registry::default_configs() );

		$silo = Outpost_Bridgy_Publish_Silo_Registry::find_by_id( 'bridgy-mastodon' );
		$this->assertNotNull( $silo );
		$this->assertSame( 'bridgy-mastodon', $silo->id() );
		$this->assertSame( 'mastodon', $silo->silo_id() );
	}

	public function test_find_by_id_returns_null_when_unknown(): void {
		$this->assertNull( Outpost_Bridgy_Publish_Silo_Registry::find_by_id( 'totally-fake' ) );
	}

	public function test_silo_config_to_chip_has_bridgy_publish_extension_key(): void {
		WP_Mock::onFilter( 'outpost_bridgy_publish_silos' )
			->withAnyArgs()
			->reply( Outpost_Bridgy_Publish_Silo_Registry::default_configs() );

		$silo = Outpost_Bridgy_Publish_Silo_Registry::find_by_id( 'bridgy-mastodon' );
		$chip = $silo->to_chip();
		$this->assertArrayHasKey( 'bridgy_publish', $chip );
		$this->assertSame( 'mastodon', $chip['bridgy_publish']['silo_id'] );
		$this->assertSame( 'https://brid.gy/publish/mastodon', $chip['bridgy_publish']['bridgy_url'] );
	}

	public function test_invalid_bridgy_url_rejected(): void {
		WP_Mock::onFilter( 'outpost_bridgy_publish_silos' )
			->withAnyArgs()
			->reply( array(
				array(
					'id'            => 'bridgy-bad',
					'label'         => 'Bad',
					'icon'          => 'bad',
					'silo_id'       => 'bad',
					// Not a brid.gy URL — must be rejected.
					'bridgy_url'    => 'https://evil.example/publish/bad',
					'accepts_modes' => array( 'note' ),
				),
			) );

		$this->expectException( Outpost_Bridgy_Publish_Invalid_Config_Exception::class );
		$this->expectExceptionMessage( 'brid.gy' );
		Outpost_Bridgy_Publish_Silo_Registry::all_silos();
	}
}
