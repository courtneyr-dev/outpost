<?php
/**
 * Outpost_Article_Extractor
 *
 * JSON-LD `Article` and `NewsArticle` schema handler. Returns headline,
 * author(s), publish/modified timestamps, publisher, section, image,
 * keywords, and word count when present in the JSON-LD block.
 *
 * Schema reference: https://schema.org/Article
 *
 * @package Outpost
 * @since   0.1.70
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-schema-helpers.php';

final class Outpost_Article_Extractor implements Outpost_Schema_Extractor {

	use Outpost_Schema_Helpers;

	public function supported_types(): array {
		return array( 'Article', 'NewsArticle', 'BlogPosting' );
	}

	public function priority(): int {
		return 10;
	}

	/**
	 * @param array<string,mixed> $jsonld_block Decoded JSON-LD object.
	 * @param string              $url          Source URL.
	 * @return array<string,mixed>
	 */
	public function extract( array $jsonld_block, string $url ): array {
		unset( $url ); // Reserved for future relative-URL resolution.
		return array(
			'type'            => isset( $jsonld_block['@type'] ) ? (string) $jsonld_block['@type'] : 'Article',
			'headline'        => self::as_string( $jsonld_block['headline'] ?? $jsonld_block['name'] ?? '' ),
			'description'     => self::as_string( $jsonld_block['description'] ?? '' ),
			'author'          => self::as_name_list( $jsonld_block['author'] ?? null ),
			'publisher'       => self::as_string( $jsonld_block['publisher'] ?? '' ),
			'date_published'  => self::as_iso_date( $jsonld_block['datePublished'] ?? '' ),
			'date_modified'   => self::as_iso_date( $jsonld_block['dateModified'] ?? '' ),
			'image'           => self::as_image_url( $jsonld_block['image'] ?? '' ),
			'article_section' => self::as_string( $jsonld_block['articleSection'] ?? '' ),
			'keywords'        => self::keywords_to_list( $jsonld_block['keywords'] ?? null ),
			'word_count'      => isset( $jsonld_block['wordCount'] ) && is_numeric( $jsonld_block['wordCount'] )
				? (int) $jsonld_block['wordCount']
				: null,
		);
	}

	/**
	 * Normalise a `keywords` field to a flat string array. Schema.org
	 * accepts comma-separated strings, arrays of strings, or arrays of
	 * DefinedTerm objects.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private static function keywords_to_list( $value ): array {
		if ( is_string( $value ) ) {
			$parts = explode( ',', $value );
			return array_values(
				array_filter(
					array_map( 'trim', $parts ),
					static function ( $s ) {
						return '' !== $s; }
				)
			);
		}
		if ( is_array( $value ) ) {
			return self::as_name_list( $value );
		}
		return array();
	}
}
