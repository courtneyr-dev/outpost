<?php
/**
 * Outpost_Source_Extractor_Composite — STUB.
 *
 * Tries multiple extractors in priority order, returns the first
 * one that succeeds. Owned by a later Phase F session. parse()
 * throws Outpost_Source_Extractor_Not_Implemented_Exception until
 * then.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Extractor_Composite extends Outpost_Source_Extractor_Base {

	public function id(): string {
		return 'composite';
	}

	/**
	 * Composite accepts whatever its constituent extractors accept.
	 * The endpoint can't pre-validate Content-Type because it
	 * depends on which inner extractor wins — so the composite
	 * stub returns the union of common shapes for the validator.
	 *
	 * @return string[]
	 */
	public function expected_content_types(): array {
		return array(
			'text/html',
			'application/xhtml+xml',
			'application/json',
			'application/xml',
			'text/xml',
			'application/rss+xml',
			'application/atom+xml',
		);
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
