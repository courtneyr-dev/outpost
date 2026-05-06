<?php
/**
 * Outpost_Event_Extractor
 *
 * JSON-LD `Event` schema handler. Returns start/end timestamps,
 * location, organizer, performer(s), event status (cancelled /
 * postponed / scheduled), attendance mode (offline/online/mixed),
 * and offers (price + currency + URL).
 *
 * Schema reference: https://schema.org/Event
 *
 * @package Outpost
 * @since   0.1.70
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-schema-helpers.php';

final class Outpost_Event_Extractor implements Outpost_Schema_Extractor {

	use Outpost_Schema_Helpers;

	public function supported_types(): array {
		return array(
			'Event',
			'BusinessEvent',
			'EducationEvent',
			'ExhibitionEvent',
			'Festival',
			'FoodEvent',
			'LiteraryEvent',
			'MusicEvent',
			'PublicationEvent',
			'SaleEvent',
			'ScreeningEvent',
			'SocialEvent',
			'SportsEvent',
			'TheaterEvent',
			'VisualArtsEvent',
		);
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
			'type'                  => isset( $jsonld_block['@type'] ) ? (string) $jsonld_block['@type'] : 'Event',
			'name'                  => self::as_string( $jsonld_block['name'] ?? '' ),
			'description'           => self::as_string( $jsonld_block['description'] ?? '' ),
			'start_date'            => self::as_iso_date( $jsonld_block['startDate'] ?? '' ),
			'end_date'              => self::as_iso_date( $jsonld_block['endDate'] ?? '' ),
			'location_name'         => self::location_name( $jsonld_block['location'] ?? null ),
			'location_address'      => self::location_address( $jsonld_block['location'] ?? null ),
			'organizer'             => self::as_name_list( $jsonld_block['organizer'] ?? null ),
			'performer'             => self::as_name_list( $jsonld_block['performer'] ?? null ),
			'event_status'          => self::strip_schema_prefix( $jsonld_block['eventStatus'] ?? '' ),
			'event_attendance_mode' => self::strip_schema_prefix( $jsonld_block['eventAttendanceMode'] ?? '' ),
			'offers'                => self::offers( $jsonld_block['offers'] ?? null ),
			'image'                 => self::as_image_url( $jsonld_block['image'] ?? '' ),
		);
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function location_name( $value ): string {
		if ( is_string( $value ) ) {
			return trim( $value );
		}
		if ( is_array( $value ) ) {
			if ( isset( $value['name'] ) && is_string( $value['name'] ) ) {
				return trim( $value['name'] );
			}
			if ( isset( $value[0] ) ) {
				return self::location_name( $value[0] );
			}
		}
		return '';
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function location_address( $value ): string {
		if ( ! is_array( $value ) ) {
			return '';
		}
		if ( isset( $value['address'] ) ) {
			return self::as_postal_address_string( $value['address'] );
		}
		if ( isset( $value[0]['address'] ) ) {
			return self::as_postal_address_string( $value[0]['address'] );
		}
		// VirtualLocation has a `url` instead of `address`.
		if ( isset( $value['url'] ) && is_string( $value['url'] ) ) {
			return trim( $value['url'] );
		}
		return '';
	}

	/**
	 * Strip the `https://schema.org/` prefix common in eventStatus and
	 * eventAttendanceMode field values.
	 *
	 * @param mixed $value Raw value.
	 * @return string
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
	 * @param mixed $value Raw offers value.
	 * @return array<int,array<string,mixed>>
	 */
	private static function offers( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		// Single Offer object → wrap in array.
		if ( isset( $value['@type'] ) || isset( $value['price'] ) ) {
			$value = array( $value );
		}
		$out = array();
		foreach ( $value as $offer ) {
			if ( ! is_array( $offer ) ) {
				continue;
			}
			$row   = array(
				'price'        => isset( $offer['price'] ) && is_numeric( $offer['price'] ) ? (float) $offer['price'] : null,
				'price_string' => isset( $offer['price'] ) ? (string) $offer['price'] : '',
				'currency'     => isset( $offer['priceCurrency'] ) ? (string) $offer['priceCurrency'] : '',
				'url'          => isset( $offer['url'] ) ? (string) $offer['url'] : '',
				'availability' => self::strip_schema_prefix( $offer['availability'] ?? '' ),
			);
			$out[] = $row;
		}
		return $out;
	}
}
