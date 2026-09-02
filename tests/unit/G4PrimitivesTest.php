<?php
/**
 * Unit tests for the G4a adapter primitives:
 * Outpost_Og_Inbound + Outpost_Composite_Inbound + Outpost_Schema_Extractor
 * interface contract.
 *
 * Concrete schema extractors (Recipe/Event/Article) ship in G4b; this
 * test file uses an inline stub extractor to verify the dispatch path
 * works end-to-end without depending on the concrete extractors.
 *
 * Og_Inbound HTTP fetching is exercised via parse_html_to_response()
 * which takes a body directly — bypasses wp_remote_get and avoids
 * needing fake HTTP infrastructure for unit-level tests.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Og_Inbound;
use Outpost_Composite_Inbound;
use Outpost_Schema_Extractor;
use WP_Error;
use WP_Mock;

/**
 * Inline stub extractor used to verify dispatch + interface contract
 * without a concrete extractor dependency.
 */
final class G4StubSchemaExtractor implements Outpost_Schema_Extractor {

	public function supported_types(): array {
		return array( 'TestType' );
	}

	public function priority(): int {
		return 10;
	}

	/**
	 * @param array<string,mixed> $jsonld_block JSON-LD block.
	 * @param string              $url          Source URL.
	 * @return array<string,mixed>
	 */
	public function extract( array $jsonld_block, string $url ): array {
		return array(
			'category'   => 'test',
			'name'       => (string) ( $jsonld_block['name'] ?? '' ),
			'source_url' => $url,
		);
	}
}

final class G4PrimitivesTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Og_Inbound::reset_extractors_for_tests();
		Outpost_Composite_Inbound::reset_strategies_for_tests();
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Og_Inbound::reset_extractors_for_tests();
		Outpost_Composite_Inbound::reset_strategies_for_tests();
	}

	// --- Og_Inbound parse_html_to_response ------------------------------

	public function test_og_response_shape_with_rich_meta(): void {
		$html = '<html><head>'
			. '<meta property="og:title" content="Example Title">'
			. '<meta property="og:description" content="Example desc">'
			. '<meta property="og:image" content="https://example.com/img.jpg">'
			. '<meta property="og:site_name" content="Example Site">'
			. '<meta property="og:type" content="article">'
			. '</head></html>';

		$out = Outpost_Og_Inbound::parse_html_to_response( $html, 'https://example.com/page', 'https://example.com/page' );

		$this->assertSame( 'Example Title', $out['title'] );
		$this->assertSame( 'Example desc', $out['description'] );
		$this->assertSame( 'https://example.com/img.jpg', $out['image'] );
		$this->assertSame( 'Example Site', $out['site_name'] );
		$this->assertSame( 'article', $out['type'] );
		$this->assertSame( 'https://example.com/page', $out['source_url'] );
		$this->assertArrayHasKey( 'fetched_at', $out );
		$this->assertArrayHasKey( 'raw_meta', $out );
		$this->assertSame( 'https://example.com/page', $out['raw_meta']['_original_url'] );
	}

	public function test_og_response_handles_minimal_meta(): void {
		$html = '<html><head><meta property="og:title" content="Just a title"></head></html>';
		$out  = Outpost_Og_Inbound::parse_html_to_response( $html, 'https://example.com/x', 'https://example.com/x' );

		$this->assertSame( 'Just a title', $out['title'] );
		$this->assertSame( '', $out['description'] );
		$this->assertNull( $out['image'] );
		$this->assertNull( $out['site_name'] );
	}

	public function test_og_response_falls_back_to_twitter_meta(): void {
		$html = '<html><head>'
			. '<meta name="twitter:title" content="Twitter Title">'
			. '<meta name="twitter:image" content="https://example.com/twit.png">'
			. '</head></html>';
		$out = Outpost_Og_Inbound::parse_html_to_response( $html, 'https://example.com/y', 'https://example.com/y' );

		$this->assertSame( 'Twitter Title', $out['title'] );
		$this->assertSame( 'https://example.com/twit.png', $out['image'] );
	}

	public function test_og_image_resolves_relative_url(): void {
		$html = '<html><head><meta property="og:image" content="/relative.png"></head></html>';
		$out  = Outpost_Og_Inbound::parse_html_to_response( $html, 'https://example.com/page', 'https://example.com/page' );

		$this->assertSame( 'https://example.com/relative.png', $out['image'] );
	}

	public function test_og_image_resolves_protocol_relative_url(): void {
		$html = '<html><head><meta property="og:image" content="//cdn.example.com/img.png"></head></html>';
		$out  = Outpost_Og_Inbound::parse_html_to_response( $html, 'https://example.com/page', 'https://example.com/page' );

		$this->assertSame( 'https://cdn.example.com/img.png', $out['image'] );
	}

	public function test_og_response_dispatches_to_registered_extractor(): void {
		Outpost_Og_Inbound::register_extractor( new G4StubSchemaExtractor() );

		$jsonld = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@type'    => 'TestType',
				'name'     => 'Inside the JSON-LD',
			)
		);
		$html = '<html><head>'
			. '<meta property="og:title" content="Page">'
			. '<script type="application/ld+json">' . $jsonld . '</script>'
			. '</head></html>';

		$out = Outpost_Og_Inbound::parse_html_to_response( $html, 'https://example.com/t', 'https://example.com/t' );

		$this->assertSame( 'test', $out['schema_org_data']['category'] );
		$this->assertSame( 'Inside the JSON-LD', $out['schema_org_data']['name'] );
		$this->assertSame( 'https://example.com/t', $out['schema_org_data']['source_url'] );
	}

	public function test_og_response_handles_jsonld_graph_wrap(): void {
		Outpost_Og_Inbound::register_extractor( new G4StubSchemaExtractor() );

		$jsonld = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => array(
					array(
						'@type' => 'WebPage',
						'name'  => 'Web page wrapper',
					),
					array(
						'@type' => 'TestType',
						'name'  => 'Inside the graph',
					),
				),
			)
		);
		$html = '<html><head><script type="application/ld+json">' . $jsonld . '</script></head></html>';

		$out = Outpost_Og_Inbound::parse_html_to_response( $html, 'https://example.com/r', 'https://example.com/r' );

		$this->assertSame( 'test', $out['schema_org_data']['category'] );
		$this->assertSame( 'Inside the graph', $out['schema_org_data']['name'] );
	}

	public function test_og_response_returns_empty_schema_when_no_extractor_match(): void {
		$jsonld = wp_json_encode( array( '@type' => 'NoSuchType', 'name' => 'whatever' ) );
		$html   = '<html><head><script type="application/ld+json">' . $jsonld . '</script></head></html>';

		$out = Outpost_Og_Inbound::parse_html_to_response( $html, 'https://example.com/q', 'https://example.com/q' );

		$this->assertSame( array(), $out['schema_org_data'] );
	}

	public function test_og_response_handles_malformed_html(): void {
		$html = '<not-html garbage <<< no closing tags';
		$out  = Outpost_Og_Inbound::parse_html_to_response( $html, 'https://example.com/m', 'https://example.com/m' );

		$this->assertSame( '', $out['title'] );
		$this->assertSame( '', $out['description'] );
		$this->assertNull( $out['image'] );
	}

	// --- Composite_Inbound ----------------------------------------------

	public function test_composite_returns_error_on_empty_source_list(): void {
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		$out = Outpost_Composite_Inbound::fetch( 'https://example.com/x', array() );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'outpost_composite_empty_sources', $out->get_error_code() );
	}

	public function test_composite_validates_role_string(): void {
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		$out = Outpost_Composite_Inbound::fetch(
			'https://example.com/x',
			array(
				array(
					'id'       => 'a',
					'role'     => 'NOTAROLE',
					'callback' => static function () {
						return array();
					},
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'outpost_composite_invalid_role', $out->get_error_code() );
	}

	public function test_composite_primary_success_returns_primary(): void {
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$out = Outpost_Composite_Inbound::fetch(
			'https://example.com/p',
			array(
				array(
					'id'       => 'primary_a',
					'role'     => 'primary',
					'callback' => static function (): array {
						return array(
							'name'  => 'From Primary',
							'image' => 'p.jpg',
						);
					},
				),
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'From Primary', $out['name'] );
		$this->assertSame( 'primary_a', $out['_composite_meta']['primary'] );
	}

	public function test_composite_falls_back_when_primary_fails(): void {
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$out = Outpost_Composite_Inbound::fetch(
			'https://example.com/f',
			array(
				array(
					'id'       => 'primary_x',
					'role'     => 'primary',
					'callback' => static function (): WP_Error {
						return new WP_Error( 'whatever', 'primary down' );
					},
				),
				array(
					'id'       => 'fallback_y',
					'role'     => 'fallback',
					'callback' => static function (): array {
						return array( 'name' => 'From Fallback' );
					},
				),
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'From Fallback', $out['name'] );
		$this->assertSame( 'fallback_y', $out['_composite_meta']['primary'] );
	}

	public function test_composite_returns_error_when_all_fail(): void {
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );

		$out = Outpost_Composite_Inbound::fetch(
			'https://example.com/all',
			array(
				array(
					'id'       => 'p1',
					'role'     => 'primary',
					'callback' => static function (): WP_Error {
						return new WP_Error( 'x', 'p1 down' );
					},
				),
				array(
					'id'       => 'p2',
					'role'     => 'fallback',
					'callback' => static function (): WP_Error {
						return new WP_Error( 'x', 'p2 down' );
					},
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'outpost_composite_all_failed', $out->get_error_code() );
	}

	public function test_composite_enrichers_merge_with_deep_merge_default(): void {
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$out = Outpost_Composite_Inbound::fetch(
			'https://example.com/merge',
			array(
				array(
					'id'       => 'p',
					'role'     => 'primary',
					'callback' => static function (): array {
						return array(
							'name'  => 'P name',
							'image' => 'p.jpg',
						);
					},
				),
				array(
					'id'       => 'enrich_one',
					'role'     => 'enrich',
					'callback' => static function (): array {
						return array(
							'image_high_res' => 'enrich.jpg',
							'extra_field'    => 42,
						);
					},
				),
			)
		);

		$this->assertSame( 'P name', $out['name'] );
		$this->assertSame( 'p.jpg', $out['image'] );
		$this->assertSame( 'enrich.jpg', $out['image_high_res'] );
		$this->assertSame( 42, $out['extra_field'] );
	}

	public function test_composite_enricher_failure_does_not_block_primary(): void {
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$out = Outpost_Composite_Inbound::fetch(
			'https://example.com/enrich-fail',
			array(
				array(
					'id'       => 'p',
					'role'     => 'primary',
					'callback' => static function (): array {
						return array( 'name' => 'P' );
					},
				),
				array(
					'id'       => 'broken_enricher',
					'role'     => 'enrich',
					'callback' => static function (): WP_Error {
						return new WP_Error( 'enrich_fail', 'broken' );
					},
				),
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'P', $out['name'] );
		$this->assertSame( 'p', $out['_composite_meta']['primary'] );
	}

	public function test_composite_uses_user_callback_strategy(): void {
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$out = Outpost_Composite_Inbound::fetch(
			'https://example.com/u',
			array(
				array(
					'id'       => 'p',
					'role'     => 'primary',
					'callback' => static function (): array {
						return array( 'a' => 1 );
					},
				),
				array(
					'id'       => 'e',
					'role'     => 'enrich',
					'callback' => static function (): array {
						return array( 'b' => 2 );
					},
				),
			),
			array(
				'merge_strategy' => 'user_callback',
				'merger'         => static function ( array $results ): array {
					return array( 'merged_count' => count( $results ) );
				},
			)
		);

		$this->assertSame( 2, $out['merged_count'] );
	}

	public function test_composite_returns_error_when_callback_not_callable(): void {
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );

		$out = Outpost_Composite_Inbound::fetch(
			'https://example.com/x',
			array(
				array(
					'id'       => 'broken',
					'role'     => 'primary',
					'callback' => 'not_a_real_function_q9q9q9',
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'outpost_composite_invalid_callback', $out->get_error_code() );
	}

	// --- Schema_Extractor interface contract ----------------------------

	public function test_stub_extractor_implements_interface(): void {
		$ex = new G4StubSchemaExtractor();
		$this->assertInstanceOf( Outpost_Schema_Extractor::class, $ex );
		$this->assertSame( array( 'TestType' ), $ex->supported_types() );
		$this->assertSame( 10, $ex->priority() );
		$out = $ex->extract( array( 'name' => 'X' ), 'https://example.com/' );
		$this->assertSame( 'test', $out['category'] );
	}
}
