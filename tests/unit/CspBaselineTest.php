<?php
/**
 * The composer shell's CSP baseline holds against `outpost_csp` filters (F-58).
 *
 * A filter may add directives and origins, but it can't loosen the four
 * required directives or `script-src`, whatever case or packing it uses.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Mock;
use Outpost_PWA_Shell;

/**
 * @covers \Outpost_PWA_Shell
 */
final class CspBaselineTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		// WP_Mock 1.x leaks Filter::$filtersWithAnyArgs across tests.
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * Runs the private CSP builder with the filter returning $filtered.
	 *
	 * @param mixed $filtered What the `outpost_csp` filter returns.
	 * @return array<string, list<list<string>>> Directive name => each occurrence's tokens.
	 */
	private function csp_with_filter( $filtered ): array {
		WP_Mock::onFilter( 'outpost_csp' )->withAnyArgs()->reply( $filtered );
		$method = new \ReflectionMethod( Outpost_PWA_Shell::class, 'content_security_policy' );
		$header = (string) $method->invoke( null );

		$this->assertDoesNotMatchRegularExpression( '/[\r\n]/', $header, 'The header must be one line.' );

		$parsed = array();
		foreach ( explode( ';', $header ) as $part ) {
			$tokens = preg_split( '/\s+/', trim( $part ) );
			if ( ! is_array( $tokens ) || '' === $tokens[0] ) {
				continue;
			}
			$name              = strtolower( (string) array_shift( $tokens ) );
			$parsed[ $name ][] = $tokens;
		}
		return $parsed;
	}

	/**
	 * Asserts a directive appears once with exactly these tokens.
	 *
	 * @param array<string, list<list<string>>> $csp    Parsed policy.
	 * @param string                            $name   Directive name.
	 * @param list<string>                      $tokens Expected tokens.
	 */
	private function assertDirective( array $csp, string $name, array $tokens ): void {
		$this->assertArrayHasKey( $name, $csp, "$name must be present." );
		$this->assertCount( 1, $csp[ $name ], "$name must appear once; a browser obeys the first copy." );
		$this->assertSame( $tokens, $csp[ $name ][0], "$name must be exactly: " . implode( ' ', $tokens ) );
	}

	public function test_unfiltered_policy_keeps_every_baseline_directive(): void {
		$csp = $this->csp_with_filter(
			array( "default-src 'self'", "script-src 'self'", "frame-ancestors 'none'", "object-src 'none'", "base-uri 'self'", 'upgrade-insecure-requests' )
		);

		$this->assertDirective( $csp, 'default-src', array( "'self'" ) );
		$this->assertDirective( $csp, 'script-src', array( "'self'" ) );
		$this->assertDirective( $csp, 'frame-ancestors', array( "'none'" ) );
		$this->assertDirective( $csp, 'object-src', array( "'none'" ) );
		$this->assertDirective( $csp, 'base-uri', array( "'self'" ) );
		$this->assertDirective( $csp, 'upgrade-insecure-requests', array() );
	}

	public function test_uppercase_directive_name_cannot_loosen_default_src(): void {
		$csp = $this->csp_with_filter( array( 'Default-Src *', "script-src 'self'" ) );

		$this->assertDirective( $csp, 'default-src', array( "'self'" ) );
	}

	public function test_directive_packed_into_another_entry_cannot_loosen_baseline(): void {
		$csp = $this->csp_with_filter(
			array( "img-src 'self' https:; default-src *; frame-ancestors *", "script-src 'self'" )
		);

		$this->assertDirective( $csp, 'img-src', array( "'self'", 'https:' ) );
		$this->assertDirective( $csp, 'default-src', array( "'self'" ) );
		$this->assertDirective( $csp, 'frame-ancestors', array( "'none'" ) );
	}

	public function test_script_src_keeps_added_origins_and_drops_loosening_tokens(): void {
		$csp = $this->csp_with_filter(
			array( "script-src 'self' 'unsafe-inline' 'UNSAFE-EVAL' https://cdn.example.com * data: https:" )
		);

		$this->assertDirective( $csp, 'script-src', array( "'self'", 'https://cdn.example.com' ) );
	}

	public function test_script_src_is_restored_when_a_filter_drops_it(): void {
		$csp = $this->csp_with_filter( array( "img-src 'self'" ) );

		$this->assertDirective( $csp, 'script-src', array( "'self'" ) );
	}

	public function test_script_src_none_cannot_disable_the_bundle(): void {
		$csp = $this->csp_with_filter( array( "script-src 'none'" ) );

		$this->assertDirective( $csp, 'script-src', array( "'self'" ) );
	}

	public function test_element_and_attribute_script_overrides_are_removed(): void {
		$csp = $this->csp_with_filter(
			array( "script-src 'self'", "script-src-elem 'unsafe-inline'", "Script-Src-Attr 'unsafe-inline'" )
		);

		$this->assertArrayNotHasKey( 'script-src-elem', $csp, 'script-src-elem overrides script-src for <script> tags.' );
		$this->assertArrayNotHasKey( 'script-src-attr', $csp, 'script-src-attr overrides script-src for inline handlers.' );
	}

	public function test_later_duplicate_cannot_override_an_earlier_directive(): void {
		$csp = $this->csp_with_filter( array( "img-src 'self'", 'img-src *', "script-src 'self'" ) );

		$this->assertDirective( $csp, 'img-src', array( "'self'" ) );
	}

	public function test_non_array_filter_result_falls_back_to_the_baseline(): void {
		$csp = $this->csp_with_filter( 'default-src *' );

		$this->assertDirective( $csp, 'default-src', array( "'self'" ) );
		$this->assertDirective( $csp, 'script-src', array( "'self'" ) );
		$this->assertDirective( $csp, 'frame-ancestors', array( "'none'" ) );
	}

	public function test_control_characters_cannot_split_the_header(): void {
		$csp = $this->csp_with_filter( array( "img-src 'self'\r\nX-Injected: 1", "script-src 'self'" ) );

		$this->assertArrayNotHasKey( 'x-injected:', $csp );
		$this->assertDirective( $csp, 'default-src', array( "'self'" ) );
	}
}
