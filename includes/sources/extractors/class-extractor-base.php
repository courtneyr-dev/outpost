<?php
/**
 * Outpost_Source_Extractor_Base
 *
 * Abstract base every extractor type extends. Extractors are
 * dispatch types, NOT fetchers — they declare:
 *
 *   - which `id()` matches the extractor reference in a
 *     Source_Base capabilities()['extractor'] field
 *   - which content types the response is allowed to be
 *   - how to compute the URL B2 should fetch from a source URL +
 *     the source adapter's recipe
 *   - how to parse the response body once B2 has fetched it
 *
 * Extractors do NOT call `wp_remote_get`, do NOT bypass
 * Outpost_Preview_Endpoint's SSRF defenses, do NOT cache. The
 * preview endpoint owns fetching; the extractor only declares the
 * shape of the dispatch.
 *
 * F5 ships only Extractor_Oembed concrete. Other extractor types
 * (og_tags, mf2, rss, api_json, api_xml, composite) are stubs whose
 * `parse()` throws Outpost_Source_Extractor_Not_Implemented_Exception
 * until the session that owns them lands.
 *
 * Symmetry note: this is the inbound counterpart to outbound
 * companion adapters. Where Companion_Base declares "I publish to
 * silo X with these limits", Extractor_Base declares "I parse
 * response shape Y from URL Z." See concepts/capture-inbound-may-2026.md
 * §1 + §10 for the bidirectional architecture.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Outpost_Source_Extractor_Base {

	/**
	 * Stable extractor type ID that matches the value sources put in
	 * their capabilities()['extractor'] field. One of:
	 *
	 *   'oembed' | 'og_tags' | 'mf2' | 'rss' | 'api_json' | 'api_xml' | 'composite'
	 *
	 * @return string
	 */
	abstract public function id(): string;

	/**
	 * Content-Type prefixes the extractor accepts on responses.
	 * The preview endpoint validates the response Content-Type
	 * against this list before calling parse(); responses with a
	 * non-matching content type cause a 415 error.
	 *
	 * Values are lower-cased prefixes — `text/html` matches
	 * `text/html; charset=utf-8`.
	 *
	 * @return string[]
	 */
	abstract public function expected_content_types(): array;

	/**
	 * Compute the URL the preview endpoint should fetch given the
	 * source URL the user shared and the source adapter's recipe.
	 *
	 * For oEmbed extractors, this substitutes the source URL into
	 * the recipe's endpoint template. For og_tags / mf2 / rss
	 * extractors, this is typically the source URL verbatim. For
	 * api_json / api_xml extractors, this is a recipe-driven
	 * transformation.
	 *
	 * The returned URL is what gets passed to wp_safe_remote_get.
	 * SSRF defenses (loopback, private network, scheme allowlist)
	 * apply to the returned URL, not to the source URL — so an
	 * extractor that returns a localhost URL is properly blocked
	 * by the existing wp_safe_remote_get filter chain.
	 *
	 * Default implementation returns the source URL verbatim, which
	 * is the right behavior for og_tags / mf2 / rss / composite.
	 * Concrete implementations override for oembed / api_*.
	 *
	 * @param string             $source_url The URL the user shared.
	 * @param array<string,mixed> $recipe     Recipe from source's capabilities().
	 * @return string The URL B2 should fetch.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Template method; concrete extractors consume $recipe.
	public function compute_fetch_url( string $source_url, array $recipe ): string {
		return $source_url;
	}

	/**
	 * Parse a response body into a flat array of extracted fields.
	 * The response body has already been validated for content-type
	 * and capped for size by the preview endpoint.
	 *
	 * Field names returned here are the keys a Source_Base mapping
	 * targets — the source's `mapping` key transforms these
	 * extractor-native names into h-entry properties.
	 *
	 * Stub extractors throw Outpost_Source_Extractor_Not_Implemented_Exception.
	 * Concrete extractors (Extractor_Oembed in F5; later sessions for the
	 * other types) may throw \RuntimeException on parse-failure modes
	 * (oversized body, malformed JSON / XML / HTML, unexpected shape).
	 *
	 * @param string             $body   Response body (size-capped).
	 * @param array<string,mixed> $recipe Recipe from source's capabilities().
	 * @return array<string,mixed> Extracted-field map.
	 *
	 * @throws Outpost_Source_Extractor_Not_Implemented_Exception When stubbed.
	 * @throws \RuntimeException When a concrete extractor fails to parse.
	 */
	abstract public function parse( string $body, array $recipe ): array;
}
