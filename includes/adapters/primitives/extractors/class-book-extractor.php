<?php
/**
 * Outpost_Book_Extractor
 *
 * JSON-LD `Book` schema handler. Returns author(s), ISBN, format
 * (Hardcover / Paperback / EBook / AudiobookFormat), page count,
 * publisher, publish date, language, image.
 *
 * Schema reference: https://schema.org/Book
 *
 * @package Outpost
 * @since   0.1.70
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-schema-helpers.php';

final class Outpost_Book_Extractor implements Outpost_Schema_Extractor {

	use Outpost_Schema_Helpers;

	public function supported_types(): array {
		return array( 'Book', 'Audiobook' );
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
		unset( $url );
		return array(
			'type'             => isset( $jsonld_block['@type'] ) ? (string) $jsonld_block['@type'] : 'Book',
			'name'             => self::as_string( $jsonld_block['name'] ?? '' ),
			'description'      => self::as_string( $jsonld_block['description'] ?? '' ),
			'author'           => self::as_name_list( $jsonld_block['author'] ?? null ),
			'isbn'             => self::isbn( $jsonld_block['isbn'] ?? $jsonld_block['gtin13'] ?? '' ),
			'book_format'      => self::strip_schema_prefix( $jsonld_block['bookFormat'] ?? '' ),
			'number_of_pages'  => isset( $jsonld_block['numberOfPages'] ) && is_numeric( $jsonld_block['numberOfPages'] )
				? (int) $jsonld_block['numberOfPages']
				: null,
			'publisher'        => self::as_string( $jsonld_block['publisher'] ?? '' ),
			'date_published'   => self::as_iso_date( $jsonld_block['datePublished'] ?? '' ),
			'in_language'      => self::language( $jsonld_block['inLanguage'] ?? '' ),
			'image'            => self::as_image_url( $jsonld_block['image'] ?? '' ),
			'aggregate_rating' => self::aggregate_rating( $jsonld_block['aggregateRating'] ?? null ),
		);
	}

	/**
	 * Normalise an ISBN — strip hyphens, uppercase, validate length.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function isbn( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$cleaned = strtoupper( preg_replace( '/[^0-9X]/i', '', $value ) ?? '' );
		if ( ! in_array( strlen( $cleaned ), array( 10, 13 ), true ) ) {
			return '';
		}
		return $cleaned;
	}

	/**
	 * Normalise a Schema.org Language object or BCP-47 string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function language( $value ): string {
		if ( is_string( $value ) ) {
			return trim( $value );
		}
		if ( is_array( $value ) ) {
			if ( isset( $value['alternateName'] ) && is_string( $value['alternateName'] ) ) {
				return trim( $value['alternateName'] );
			}
			if ( isset( $value['name'] ) && is_string( $value['name'] ) ) {
				return trim( $value['name'] );
			}
		}
		return '';
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private static function strip_schema_prefix( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$str = trim( $value );
		$str = preg_replace( '~^https?://schema\.org/~', '', $str ) ?? $str;
		return $str;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return array<string,mixed>|null
	 */
	private static function aggregate_rating( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$rating = $value['ratingValue'] ?? null;
		$count  = $value['ratingCount'] ?? $value['reviewCount'] ?? null;
		if ( null === $rating && null === $count ) {
			return null;
		}
		return array(
			'rating' => is_numeric( $rating ) ? (float) $rating : null,
			'count'  => is_numeric( $count ) ? (int) $count : null,
		);
	}
}
