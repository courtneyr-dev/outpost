<?php
/**
 * Outpost_Source_Extractor_Api_Xml — STUB.
 *
 * Generic XML-API extractor with declared field mapping. Owned by
 * a later Phase F session (BoardGameGeek, anything that still
 * returns XML in 2026). parse() throws
 * Outpost_Source_Extractor_Not_Implemented_Exception until then.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Extractor_Api_Xml extends Outpost_Source_Extractor_Base {

	public function id(): string {
		return 'api_xml';
	}

	/**
	 * @return string[]
	 */
	public function expected_content_types(): array {
		return array( 'application/xml', 'text/xml' );
	}

	/**
	 * @param string             $body   Unused.
	 * @param array<string,mixed> $recipe Unused.
	 * @return array<string,mixed>
	 *
	 * @throws Outpost_Source_Extractor_Not_Implemented_Exception Always.
	 */
	public function parse( string $body, array $recipe ): array {
		throw new Outpost_Source_Extractor_Not_Implemented_Exception( esc_html( $this->id() ) );
	}
}
