<?php
/**
 * Outpost_Recipe_Extractor
 *
 * JSON-LD `Recipe` schema handler. Returns prep / cook / total time in
 * minutes, recipe-yield, category, cuisine, ingredients, instructions,
 * nutrition, keywords, author, image.
 *
 * Schema reference: https://schema.org/Recipe
 *
 * @package Outpost
 * @since   0.1.70
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-schema-helpers.php';

final class Outpost_Recipe_Extractor implements Outpost_Schema_Extractor {

	use Outpost_Schema_Helpers;

	public function supported_types(): array {
		return array( 'Recipe' );
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
			'type'             => 'Recipe',
			'name'             => self::as_string( $jsonld_block['name'] ?? '' ),
			'description'      => self::as_string( $jsonld_block['description'] ?? '' ),
			'author'           => self::as_name_list( $jsonld_block['author'] ?? null ),
			'date_published'   => self::as_iso_date( $jsonld_block['datePublished'] ?? '' ),
			'image'            => self::as_image_url( $jsonld_block['image'] ?? '' ),
			'prep_time'        => self::as_iso_duration_minutes( $jsonld_block['prepTime'] ?? null ),
			'cook_time'        => self::as_iso_duration_minutes( $jsonld_block['cookTime'] ?? null ),
			'total_time'       => self::as_iso_duration_minutes( $jsonld_block['totalTime'] ?? null ),
			'recipe_yield'     => self::yield_to_string( $jsonld_block['recipeYield'] ?? null ),
			'recipe_category'  => self::as_string( $jsonld_block['recipeCategory'] ?? '' ),
			'recipe_cuisine'   => self::as_string( $jsonld_block['recipeCuisine'] ?? '' ),
			'ingredients'      => self::ingredients( $jsonld_block['recipeIngredient'] ?? null ),
			'instructions'     => self::as_instruction_list( $jsonld_block['recipeInstructions'] ?? null ),
			'nutrition'        => self::nutrition( $jsonld_block['nutrition'] ?? null ),
			'keywords'         => self::keywords_to_list( $jsonld_block['keywords'] ?? null ),
			'aggregate_rating' => self::aggregate_rating( $jsonld_block['aggregateRating'] ?? null ),
		);
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function yield_to_string( $value ): string {
		if ( is_numeric( $value ) ) {
			return (string) $value;
		}
		if ( is_string( $value ) ) {
			return trim( $value );
		}
		if ( is_array( $value ) && isset( $value[0] ) ) {
			return self::yield_to_string( $value[0] );
		}
		return '';
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private static function ingredients( $value ): array {
		if ( is_string( $value ) ) {
			return array( trim( $value ) );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $entry ) {
			if ( is_string( $entry ) ) {
				$trimmed = trim( $entry );
				if ( '' !== $trimmed ) {
					$out[] = $trimmed;
				}
			}
		}
		return $out;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return array<string,mixed>|null
	 */
	private static function nutrition( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$keys = array(
			'calories',
			'fatContent',
			'saturatedFatContent',
			'carbohydrateContent',
			'sugarContent',
			'proteinContent',
			'fiberContent',
			'sodiumContent',
			'cholesterolContent',
			'servingSize',
		);
		$out  = array();
		foreach ( $keys as $k ) {
			if ( isset( $value[ $k ] ) && is_string( $value[ $k ] ) ) {
				$out[ self::camel_to_snake( $k ) ] = trim( $value[ $k ] );
			}
		}
		return empty( $out ) ? null : $out;
	}

	/**
	 * @param mixed $value Raw keywords value.
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

	private static function camel_to_snake( string $camel ): string {
		$snake = preg_replace( '/([a-z])([A-Z])/', '$1_$2', $camel );
		return strtolower( $snake ?? $camel );
	}
}
