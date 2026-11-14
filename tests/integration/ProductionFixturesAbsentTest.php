<?php
/**
 * The plugin as loaded exposes no test fixture (T10-S3-09, F-10).
 *
 * The Test fetch-recent provider is gone from the shipped code, so a site
 * lists only real providers. Fetch-recent's permitted and denied behavior is
 * still covered through test-owned fixtures (ConnectedAccountReadsTest).
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ProductionFixturesAbsentTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Fetch_Recent_REST' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap.' );
		}
	}

	public function test_no_test_provider_class_is_loaded(): void {
		$this->assertFalse( class_exists( 'Outpost_Fetch_Recent_Test_Provider', false ) );
	}

	public function test_the_provider_registry_has_no_test_provider(): void {
		$providers = \Outpost_Fetch_Recent_REST::get_providers();
		$this->assertArrayNotHasKey( 'test', $providers );
		foreach ( $providers as $config ) {
			$this->assertStringNotContainsString( 'sample data', (string) ( $config['label'] ?? '' ) );
		}
	}
}
