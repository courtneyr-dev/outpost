<?php
/**
 * Outpost_Source_Extractor_Oembed
 *
 * The only concrete extractor F5 implements. F7's Source_Spotify
 * needs it; F15's Source_YouTube reuses it; later oEmbed-friendly
 * sources (Vimeo, Soundcloud, Flickr, Reddit, Twitch, etc.) reuse
 * it without parser changes.
 *
 * oEmbed contract (https://oembed.com):
 *
 *   - Endpoint URL is built by substituting the source URL into the
 *     `endpoint` recipe template's `{url}` placeholder. The source
 *     URL gets URL-encoded.
 *   - Response is JSON. The `type` field declares the embed shape
 *     (link / photo / video / rich); F5 returns whatever fields are
 *     present and lets the source's `mapping` pick what to keep.
 *   - Common fields: `title`, `author_name`, `author_url`,
 *     `provider_name`, `provider_url`, `thumbnail_url`,
 *     `thumbnail_width`, `thumbnail_height`, `html`, `width`,
 *     `height`, `version`.
 *
 * Defenses:
 *
 *   - `compute_fetch_url` validates that the recipe contains a
 *     scheme://host endpoint pattern. A recipe without `http(s)://`
 *     produces a clearly invalid fetch URL that wp_safe_remote_get
 *     rejects, so we don't need to re-implement scheme checking
 *     here — but we DO refuse to template a URL that has no
 *     `{url}` placeholder, since that would silently fetch the
 *     same endpoint regardless of source URL (likely a recipe bug).
 *   - `parse` rejects non-JSON bodies, bodies > 1 MB (already capped
 *     by the endpoint at 5 MB; this is a per-extractor sanity cap),
 *     and bodies that decode to anything other than an associative
 *     array.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Extractor_Oembed extends Outpost_Source_Extractor_Base {

	/** Per-extractor body size cap, defense-in-depth on top of the endpoint's. */
	private const MAX_BODY_BYTES = 1024 * 1024;

	public function id(): string {
		return 'oembed';
	}

	/**
	 * @return string[]
	 */
	public function expected_content_types(): array {
		return array( 'application/json' );
	}

	/**
	 * Substitute the source URL into the recipe's endpoint template
	 * and URL-encode it. Refuses to operate on a recipe without a
	 * `{url}` placeholder so silent endpoint reuse (likely a recipe
	 * bug) becomes a visible InvalidArgumentException instead.
	 *
	 * @param string             $source_url Source URL the user shared.
	 * @param array<string,mixed> $recipe     Source recipe; must contain `endpoint` template.
	 * @return string Fully-formed oEmbed endpoint URL ready for wp_safe_remote_get.
	 *
	 * @throws \InvalidArgumentException When the recipe is malformed.
	 */
	public function compute_fetch_url( string $source_url, array $recipe ): string {
		$endpoint = isset( $recipe['endpoint'] ) && is_string( $recipe['endpoint'] )
			? $recipe['endpoint']
			: '';
		if ( '' === $endpoint ) {
			throw new \InvalidArgumentException(
				esc_html( 'Outpost_Source_Extractor_Oembed: recipe missing required "endpoint" template.' )
			);
		}
		if ( false === strpos( $endpoint, '{url}' ) ) {
			throw new \InvalidArgumentException(
				esc_html( 'Outpost_Source_Extractor_Oembed: recipe endpoint template missing required {url} placeholder.' )
			);
		}
		return str_replace( '{url}', rawurlencode( $source_url ), $endpoint );
	}

	/**
	 * Parse oEmbed JSON response body to a flat associative array.
	 *
	 * @param string             $body   Response body.
	 * @param array<string,mixed> $recipe Recipe (unused for oembed JSON parsing; kept for contract symmetry).
	 * @return array<string,mixed> Decoded oEmbed object.
	 *
	 * @throws \RuntimeException When body is too large, not JSON, or decodes to a non-object shape.
	 */
	public function parse( string $body, array $recipe ): array {
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			throw new \RuntimeException(
				esc_html( 'Outpost_Source_Extractor_Oembed: response body exceeds 1 MB cap.' )
			);
		}
		$decoded = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new \RuntimeException(
				esc_html( 'Outpost_Source_Extractor_Oembed: response is not valid JSON: ' . json_last_error_msg() )
			);
		}
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException(
				esc_html( 'Outpost_Source_Extractor_Oembed: response decoded to a non-array; oEmbed responses must be JSON objects.' )
			);
		}
		// oEmbed responses are JSON objects, never lists. A numeric-keyed
		// array is technically an array but not a valid oEmbed response.
		if ( array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) && count( $decoded ) > 0 ) {
			throw new \RuntimeException(
				esc_html( 'Outpost_Source_Extractor_Oembed: response is a JSON list; oEmbed expects an object.' )
			);
		}
		return $decoded;
	}
}
