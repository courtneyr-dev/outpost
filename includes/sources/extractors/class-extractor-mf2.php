<?php
/**
 * Outpost_Source_Extractor_Mf2 — STUB.
 *
 * Microformats2 parser. Owned by a later Phase F session for h-card /
 * h-cite extraction off rich IndieWeb pages. parse() throws
 * Outpost_Source_Extractor_Not_Implemented_Exception until then.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Extractor_Mf2 extends Outpost_Source_Extractor_Base {

	public function id(): string {
		return 'mf2';
	}

	/**
	 * @return string[]
	 */
	public function expected_content_types(): array {
		return array( 'text/html', 'application/xhtml+xml' );
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
