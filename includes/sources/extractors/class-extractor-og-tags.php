<?php
/**
 * Outpost_Source_Extractor_Og_Tags — STUB.
 *
 * Owns the parser body: F16 Source_Unknown end-to-end + Source_Snipd
 * (OG-tag-only sites). compute_fetch_url() returns the source URL
 * verbatim today; parse() throws Outpost_Source_Extractor_Not_Implemented_Exception.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Extractor_Og_Tags extends Outpost_Source_Extractor_Base {

	public function id(): string {
		return 'og_tags';
	}

	/**
	 * @return string[]
	 */
	public function expected_content_types(): array {
		return array( 'text/html', 'application/xhtml+xml' );
	}

	/**
	 * Parser stub. Owned by F16.
	 *
	 * @param string             $body   Response body (unused; throws before reading).
	 * @param array<string,mixed> $recipe Recipe (unused; throws before reading).
	 * @return array<string,mixed>
	 *
	 * @throws Outpost_Source_Extractor_Not_Implemented_Exception Always.
	 */
	public function parse( string $body, array $recipe ): array {
		throw new Outpost_Source_Extractor_Not_Implemented_Exception( esc_html( $this->id() ) );
	}
}
