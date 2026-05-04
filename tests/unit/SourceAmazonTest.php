<?php
/**
 * Unit tests for Outpost_Source_Amazon (F16).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Amazon;
use Outpost_Source_Registry;
use Outpost_Source_Extractor_Og_Tags;
use Outpost\Tests\Helpers\SourceFixtureLoader;
use WP_Mock;

final class SourceAmazonTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Source_Registry::reset_for_tests();
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Source_Registry::reset_for_tests();
	}

	public function test_capabilities_shape(): void {
		$source = new Outpost_Source_Amazon();
		$caps   = $source->capabilities();

		$this->assertSame( 'amazon', $caps['id'] );
		$this->assertSame( 'bookmark', $caps['mode'] );
		$this->assertSame( 'u-bookmark-of', $caps['h_entry_property'] );
	}

	public function test_caveat_documents_affiliate_stripping(): void {
		$source = new Outpost_Source_Amazon();
		$caveats = $source->capabilities()['caveats'];

		$this->assertGreaterThanOrEqual( 1, count( $caveats ) );
		$has_aff_caveat = false;
		foreach ( $caveats as $c ) {
			if ( false !== stripos( $c, 'affiliate' ) ) {
				$has_aff_caveat = true;
				break;
			}
		}
		$this->assertTrue( $has_aff_caveat, 'A caveat must mention affiliate stripping.' );
	}

	public function test_dp_url_matches_us(): void {
		$source = new Outpost_Source_Amazon();
		$this->assertTrue( $source->matches_url( 'https://www.amazon.com/dp/B00EXAMPLE' ) );
	}

	public function test_dp_url_matches_uk(): void {
		$source = new Outpost_Source_Amazon();
		$this->assertTrue( $source->matches_url( 'https://www.amazon.co.uk/dp/B00EXAMPLE' ) );
	}

	public function test_dp_url_matches_germany(): void {
		$source = new Outpost_Source_Amazon();
		$this->assertTrue( $source->matches_url( 'https://www.amazon.de/dp/B00EXAMPLE' ) );
	}

	public function test_apex_amazon_dp_url_matches(): void {
		$source = new Outpost_Source_Amazon();
		$this->assertTrue( $source->matches_url( 'https://amazon.com/dp/B00EXAMPLE' ) );
	}

	public function test_smile_amazon_dp_url_matches(): void {
		$source = new Outpost_Source_Amazon();
		$this->assertTrue( $source->matches_url( 'https://smile.amazon.com/dp/B00EXAMPLE' ) );
	}

	public function test_gp_product_url_matches(): void {
		$source = new Outpost_Source_Amazon();
		$this->assertTrue( $source->matches_url( 'https://www.amazon.com/gp/product/B00EXAMPLE' ) );
	}

	public function test_wishlist_url_does_not_match(): void {
		$source = new Outpost_Source_Amazon();
		$this->assertFalse( $source->matches_url( 'https://www.amazon.com/hz/wishlist/ls/EXAMPLE' ) );
	}

	public function test_search_url_does_not_match(): void {
		$source = new Outpost_Source_Amazon();
		$this->assertFalse( $source->matches_url( 'https://www.amazon.com/s?k=test' ) );
	}

	public function test_strip_affiliate_params_removes_tag(): void {
		$dirty = 'https://www.amazon.com/dp/B00EXAMPLE?tag=affiliate-20&keywords=foo';
		$clean = Outpost_Source_Amazon::strip_affiliate_params( $dirty );

		$this->assertStringNotContainsString( 'tag=', $clean );
		$this->assertStringContainsString( 'keywords=foo', $clean );
	}

	public function test_strip_affiliate_params_removes_link_codes(): void {
		$dirty = 'https://www.amazon.com/dp/B00EXAMPLE?linkCode=ll1&linkId=abc&ascsubtag=xyz';
		$clean = Outpost_Source_Amazon::strip_affiliate_params( $dirty );

		$this->assertStringNotContainsString( 'linkCode', $clean );
		$this->assertStringNotContainsString( 'linkId', $clean );
		$this->assertStringNotContainsString( 'ascsubtag', $clean );
	}

	public function test_strip_affiliate_params_removes_pd_rd_tracking(): void {
		$dirty = 'https://www.amazon.com/dp/B00EXAMPLE?pd_rd_w=abc&pd_rd_r=def&pd_rd_wg=ghi&pf_rd_p=jkl&pf_rd_r=mno';
		$clean = Outpost_Source_Amazon::strip_affiliate_params( $dirty );

		$this->assertStringNotContainsString( 'pd_rd', $clean );
		$this->assertStringNotContainsString( 'pf_rd', $clean );
	}

	public function test_strip_affiliate_params_preserves_url_without_query(): void {
		$dirty = 'https://www.amazon.com/dp/B00EXAMPLE';
		$clean = Outpost_Source_Amazon::strip_affiliate_params( $dirty );

		$this->assertSame( $dirty, $clean );
	}

	public function test_strip_affiliate_params_strips_all_known_returns_no_query(): void {
		$dirty = 'https://www.amazon.com/dp/B00EXAMPLE?tag=affiliate-20&linkCode=ll1';
		$clean = Outpost_Source_Amazon::strip_affiliate_params( $dirty );

		$this->assertSame( 'https://www.amazon.com/dp/B00EXAMPLE', $clean );
	}

	public function test_recipe_for_url_includes_canonical_url(): void {
		$source = new Outpost_Source_Amazon();
		$dirty  = 'https://www.amazon.com/dp/B00EXAMPLE?tag=affiliate-20';
		$recipe = $source->recipe_for_url( $dirty );

		$this->assertSame( 'https://www.amazon.com/dp/B00EXAMPLE', $recipe['canonical_url'] );
	}

	public function test_mode_is_bookmark(): void {
		$source = new Outpost_Source_Amazon();
		$this->assertSame( 'bookmark', $source->mode_for_url( 'https://www.amazon.com/dp/B00EXAMPLE' ) );
	}

	public function test_mapping_for_product_fixture(): void {
		$source     = new Outpost_Source_Amazon();
		$body       = SourceFixtureLoader::load_raw_fixture( 'amazon', 'og-product-success', 'html' );
		$ext        = new Outpost_Source_Extractor_Og_Tags();
		$decoded    = $ext->parse( $body, array() );
		$source_url = 'https://www.amazon.com/dp/B00EXAMPLE';

		$mapped = $source->map_extracted( $decoded, $source_url );

		$this->assertSame( 'Sample Product Title with Variant Specifics', $mapped['p-name'] );
		$this->assertSame( 'https://m.media-amazon.com/images/example-product-image.jpg', $mapped['u-photo'] );
		$this->assertSame( $source_url, $mapped['u-bookmark-of'] );
	}

	public function test_registry_finds_amazon_for_product_url(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_Amazon() );
		$found = Outpost_Source_Registry::find_for_url( 'https://www.amazon.com/dp/B00EXAMPLE' );

		$this->assertNotNull( $found );
		$this->assertSame( 'amazon', $found->capabilities()['id'] );
	}

	public function test_registry_falls_back_to_unknown_for_wishlist(): void {
		WP_Mock::userFunction( 'is_plugin_active' )->andReturn( false );
		WP_Mock::userFunction( 'get_plugins' )->andReturn( array() );

		Outpost_Source_Registry::register( new Outpost_Source_Amazon() );
		$found = Outpost_Source_Registry::find_for_url( 'https://www.amazon.com/hz/wishlist/ls/EXAMPLE' );

		$this->assertNotNull( $found );
		$this->assertSame( 'unknown', $found->capabilities()['id'] );
	}
}
