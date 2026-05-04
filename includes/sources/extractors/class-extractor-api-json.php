<?php
/**
 * Outpost_Source_Extractor_Api_Json — STUB.
 *
 * Generic JSON-API extractor with declared field mapping. Owned by
 * a later Phase F session for sources whose public API returns JSON
 * but isn't oEmbed-shaped (e.g. Snipd's REST API, Last.fm). parse()
 * throws Outpost_Source_Extractor_Not_Implemented_Exception until then.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Extractor_Api_Json extends Outpost_Source_Extractor_Base {

	public function id(): string {
		return 'api_json';
	}

	/**
	 * @return string[]
	 */
	public function expected_content_types(): array {
		return array( 'application/json' );
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
