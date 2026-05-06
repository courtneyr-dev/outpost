<?php
/**
 * Unit tests for the G10a scripture og_tags batch:
 * Sefaria + SuttaCentral.
 *
 * Per-adapter coverage focuses on capabilities() shape + URL pattern
 * matching (positive + negative). Detailed mapping tests are covered
 * by the og_tags extractor's own test suite; this batch verifies
 * adapter SHAPE only, matching the F17 batch convention.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Sefaria;
use Outpost_Source_SuttaCentral;
use Outpost_Source_Registry;
use WP_Mock;

final class G10aScriptureBatchTest extends \WP_Mock\Tools\TestCase {

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

	// --- Sefaria --------------------------------------------------------

	public function test_sefaria_capabilities(): void {
		$caps = ( new Outpost_Source_Sefaria() )->capabilities();
		$this->assertSame( 'sefaria', $caps['id'] );
		$this->assertSame( 'quote', $caps['mode'] );
		$this->assertSame( 'og_tags', $caps['extractor'] );
		$this->assertSame( 'u-quotation-of', $caps['h_entry_property'] );
		$this->assertNotEmpty( $caps['caveats'] );
	}

	public function test_sefaria_apex_and_www_match(): void {
		$source = new Outpost_Source_Sefaria();
		$this->assertTrue( $source->matches_url( 'https://www.sefaria.org/Genesis.1.1' ) );
		$this->assertTrue( $source->matches_url( 'https://sefaria.org/Bereishit_Rabbah.1.1' ) );
	}

	public function test_sefaria_other_hosts_do_not_match(): void {
		$source = new Outpost_Source_Sefaria();
		$this->assertFalse( $source->matches_url( 'https://example.org/Genesis.1.1' ) );
	}

	public function test_sefaria_mapping_routes_to_quotation_of(): void {
		$caps = ( new Outpost_Source_Sefaria() )->capabilities();
		$this->assertSame( 'p-name', $caps['mapping']['og:title'] );
		$this->assertSame( 'p-summary', $caps['mapping']['og:description'] );
		$this->assertSame( 'u-quotation-of', $caps['mapping']['@source_url'] );
	}

	// --- SuttaCentral ---------------------------------------------------

	public function test_suttacentral_capabilities(): void {
		$caps = ( new Outpost_Source_SuttaCentral() )->capabilities();
		$this->assertSame( 'suttacentral', $caps['id'] );
		$this->assertSame( 'quote', $caps['mode'] );
		$this->assertSame( 'og_tags', $caps['extractor'] );
		$this->assertSame( 'u-quotation-of', $caps['h_entry_property'] );
	}

	public function test_suttacentral_apex_matches(): void {
		$source = new Outpost_Source_SuttaCentral();
		$this->assertTrue( $source->matches_url( 'https://suttacentral.net/dn1/en/sujato' ) );
		$this->assertTrue( $source->matches_url( 'https://suttacentral.net/dhp1-20' ) );
	}

	public function test_suttacentral_other_hosts_do_not_match(): void {
		$source = new Outpost_Source_SuttaCentral();
		$this->assertFalse( $source->matches_url( 'https://www.suttacentral.net/dn1' ) );
		$this->assertFalse( $source->matches_url( 'https://example.com/dn1' ) );
	}

	public function test_suttacentral_caveats_mention_g10b(): void {
		$caps = ( new Outpost_Source_SuttaCentral() )->capabilities();
		$found = false;
		foreach ( (array) $caps['caveats'] as $caveat ) {
			if ( false !== stripos( (string) $caveat, 'G10b' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'SuttaCentral caveats should mention deferred G10b path.' );
	}
}
