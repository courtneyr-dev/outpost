<?php
/**
 * Outpost_Source_Extractor_Rss — STUB.
 *
 * RSS / Atom feed parser. Owned by a later Phase F session for
 * podcast / blog feed extraction. parse() throws
 * Outpost_Source_Extractor_Not_Implemented_Exception until then.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Extractor_Rss extends Outpost_Source_Extractor_Base {

	public function id(): string {
		return 'rss';
	}

	/**
	 * @return string[]
	 */
	public function expected_content_types(): array {
		return array( 'application/rss+xml', 'application/atom+xml', 'text/xml', 'application/xml' );
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
