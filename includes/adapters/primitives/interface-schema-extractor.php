<?php
/**
 * Outpost_Schema_Extractor — interface (G4).
 *
 * Contract for category-specific JSON-LD extractors that Og_Inbound
 * dispatches to. Implementations declare which Schema.org @type values
 * they handle and return a flat array of extracted fields when fed a
 * matching JSON-LD block.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Outpost_Schema_Extractor {

	/**
	 * Schema.org @type values this extractor claims.
	 *
	 * @return string[]
	 */
	public function supported_types(): array;

	/**
	 * Priority — higher wins on conflict. Default 10.
	 *
	 * @return int
	 */
	public function priority(): int;

	/**
	 * Extract category-specific fields from a JSON-LD block.
	 *
	 * @param array<string,mixed> $jsonld_block Decoded JSON-LD object.
	 * @param string              $url          Source URL.
	 * @return array<string,mixed>
	 */
	public function extract( array $jsonld_block, string $url ): array;
}
