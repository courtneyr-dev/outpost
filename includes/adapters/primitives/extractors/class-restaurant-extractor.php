<?php
/**
 * Outpost_Restaurant_Extractor
 *
 * JSON-LD `Restaurant` and `FoodEstablishment` schema handler. Returns
 * formatted address, telephone, served cuisine(s), price range,
 * geo coordinates, opening hours, aggregate rating.
 *
 * Schema reference: https://schema.org/Restaurant
 *
 * @package Outpost
 * @since   0.1.70
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-schema-helpers.php';

final class Outpost_Restaurant_Extractor implements Outpost_Schema_Extractor {

	use Outpost_Schema_Helpers;

	public function supported_types(): array {
		return array( 'Restaurant', 'FoodEstablishment', 'CafeOrCoffeeShop', 'BarOrPub', 'Bakery', 'FastFoodRestaurant' );
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
			'type'             => isset( $jsonld_block['@type'] ) ? (string) $jsonld_block['@type'] : 'Restaurant',
			'name'             => self::as_string( $jsonld_block['name'] ?? '' ),
			'description'      => self::as_string( $jsonld_block['description'] ?? '' ),
			'address'          => self::as_postal_address_string( $jsonld_block['address'] ?? null ),
			'telephone'        => self::as_string( $jsonld_block['telephone'] ?? '' ),
			'serves_cuisine'   => self::cuisine( $jsonld_block['servesCuisine'] ?? null ),
			'price_range'      => self::as_string( $jsonld_block['priceRange'] ?? '' ),
			'geo'              => self::geo( $jsonld_block['geo'] ?? null ),
			'opening_hours'    => self::opening_hours( $jsonld_block['openingHoursSpecification'] ?? $jsonld_block['openingHours'] ?? null ),
			'aggregate_rating' => self::aggregate_rating( $jsonld_block['aggregateRating'] ?? null ),
			'image'            => self::as_image_url( $jsonld_block['image'] ?? '' ),
		);
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private static function cuisine( $value ): array {
		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			return '' === $trimmed ? array() : array( $trimmed );
		}
		if ( is_array( $value ) ) {
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
		return array();
	}

	/**
	 * @param mixed $value Raw value.
	 * @return array{lat: float, lng: float}|null
	 */
	private static function geo( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$lat = $value['latitude'] ?? null;
		$lng = $value['longitude'] ?? null;
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return null;
		}
		return array(
			'lat' => (float) $lat,
			'lng' => (float) $lng,
		);
	}

	/**
	 * Accepts either a string array of plain hours strings or a list of
	 * OpeningHoursSpecification objects.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int,string>
	 */
	private static function opening_hours( $value ): array {
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
				continue;
			}
			if ( is_array( $entry ) ) {
				$days     = $entry['dayOfWeek'] ?? '';
				$opens    = $entry['opens'] ?? '';
				$closes   = $entry['closes'] ?? '';
				$days_str = '';
				if ( is_array( $days ) ) {
					$days_str = implode(
						', ',
						array_map(
							static function ( $d ) {
								if ( is_string( $d ) ) {
									return preg_replace( '~^https?://schema\.org/~', '', trim( $d ) );
								}
								return '';
							},
							$days
						)
					);
				} elseif ( is_string( $days ) ) {
					$days_str = preg_replace( '~^https?://schema\.org/~', '', trim( $days ) ) ?? '';
				}
				if ( '' !== $days_str || '' !== $opens || '' !== $closes ) {
					$out[] = trim( sprintf( '%s %s-%s', $days_str, $opens, $closes ) );
				}
			}
		}
		return $out;
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
