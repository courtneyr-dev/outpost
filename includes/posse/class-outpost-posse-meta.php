<?php
/**
 * Outpost_POSSE_Meta (G3.5b).
 *
 * Static accessor methods for the four post-meta keys that drive
 * POSSE-outbound state. The keys are intentionally stable (locked
 * names — see G3.5b prompt §5) so the IndieWeb-standard
 * `_outpost_syndication_urls` shape is portable.
 *
 * Meta keys:
 *
 *   _outpost_posse_targets    — string[] of destination ids the user
 *                               selected for this post (UI sets it; the
 *                               dispatcher reads it on publish)
 *   _outpost_syndication_urls — array of { destination_id, url, posted_at }
 *                               objects for successful syndications. The
 *                               IndieWeb canonical list — rendered as
 *                               `u-syndication` markup.
 *   _outpost_posse_failures   — array of { destination_id, error,
 *                               attempted_at, attempt_count } for
 *                               permanent failures (3 retries exhausted
 *                               OR non-retryable on first attempt)
 *   _outpost_posse_in_flight  — string[] of destination ids currently
 *                               scheduled or retrying. Idempotency seal:
 *                               re-publishing while in-flight is a no-op.
 *
 * `register()` calls register_post_meta() so the four keys are exposed
 * to the WP REST API for the Gutenberg sidebar (G3.5c+) to read/write.
 *
 * @package Outpost
 * @since   0.1.78
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_POSSE_Meta {

	public const TARGETS          = '_outpost_posse_targets';
	public const SYNDICATION_URLS = '_outpost_syndication_urls';
	public const FAILURES         = '_outpost_posse_failures';
	public const IN_FLIGHT        = '_outpost_posse_in_flight';

	/**
	 * Hook the four register_post_meta calls onto `init`. Called from
	 * outpost.php's plugins_loaded handler.
	 *
	 * @since 0.1.78
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_post_meta_keys' ) );
	}

	/**
	 * Register the four post_meta keys for the `post` object type so
	 * Gutenberg sidebar (G3.5c+) can read/write them via REST.
	 *
	 * Auth callback gates writes on `edit_post` cap for the specific
	 * post the meta is being set on — standard WP pattern.
	 *
	 * @since 0.1.78
	 */
	public static function register_post_meta_keys(): void {
		$auth = static function ( $allowed, $meta_key, $object_id ) {
			unset( $allowed, $meta_key );
			return current_user_can( 'edit_post', (int) $object_id );
		};

		register_post_meta(
			'post',
			self::TARGETS,
			array(
				'type'              => 'array',
				'show_in_rest'      => array(
					'schema' => array( 'items' => array( 'type' => 'string' ) ),
				),
				'single'            => true,
				'auth_callback'     => $auth,
				'sanitize_callback' => array( __CLASS__, 'sanitize_string_array' ),
			)
		);
		register_post_meta(
			'post',
			self::SYNDICATION_URLS,
			array(
				'type'          => 'array',
				'show_in_rest'  => array(
					'schema' => array(
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'destination_id' => array( 'type' => 'string' ),
								'url'            => array( 'type' => 'string' ),
								'posted_at'      => array( 'type' => 'string' ),
							),
						),
					),
				),
				'single'        => true,
				'auth_callback' => $auth,
			)
		);
		register_post_meta(
			'post',
			self::FAILURES,
			array(
				'type'          => 'array',
				'show_in_rest'  => array(
					'schema' => array(
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'destination_id' => array( 'type' => 'string' ),
								'error'          => array( 'type' => 'string' ),
								'attempted_at'   => array( 'type' => 'string' ),
								'attempt_count'  => array( 'type' => 'integer' ),
							),
						),
					),
				),
				'single'        => true,
				'auth_callback' => $auth,
			)
		);
		register_post_meta(
			'post',
			self::IN_FLIGHT,
			array(
				'type'              => 'array',
				'show_in_rest'      => array(
					'schema' => array( 'items' => array( 'type' => 'string' ) ),
				),
				'single'            => true,
				'auth_callback'     => $auth,
				'sanitize_callback' => array( __CLASS__, 'sanitize_string_array' ),
			)
		);
	}

	// ---------- Targets ----------

	/**
	 * @return string[]
	 */
	public static function get_targets( int $post_id ): array {
		return self::read_string_array( $post_id, self::TARGETS );
	}

	/**
	 * @param string[] $destination_ids
	 */
	public static function set_targets( int $post_id, array $destination_ids ): void {
		update_post_meta( $post_id, self::TARGETS, self::sanitize_string_array( $destination_ids ) );
	}

	// ---------- Syndication URLs ----------

	/**
	 * @return array<int,array<string,string>>
	 */
	public static function get_syndication_urls( int $post_id ): array {
		$value = get_post_meta( $post_id, self::SYNDICATION_URLS, true );
		return is_array( $value ) ? array_values( $value ) : array();
	}

	public static function add_syndication_url( int $post_id, string $destination_id, string $url ): void {
		$current = self::get_syndication_urls( $post_id );
		// Replace existing entry for the same destination (re-publish case)
		// rather than accumulating duplicates.
		$current   = array_values(
			array_filter(
				$current,
				static function ( $row ) use ( $destination_id ) {
					return ( $row['destination_id'] ?? '' ) !== $destination_id;
				}
			)
		);
		$current[] = array(
			'destination_id' => $destination_id,
			'url'            => $url,
			'posted_at'      => gmdate( 'c' ),
		);
		update_post_meta( $post_id, self::SYNDICATION_URLS, $current );
	}

	// ---------- Failures ----------

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_failures( int $post_id ): array {
		$value = get_post_meta( $post_id, self::FAILURES, true );
		return is_array( $value ) ? array_values( $value ) : array();
	}

	public static function add_failure( int $post_id, string $destination_id, string $error_message, int $attempt_count ): void {
		$current = self::get_failures( $post_id );
		// Replace per-destination so the latest failure replaces older
		// entries from the same dispatch chain.
		$current   = array_values(
			array_filter(
				$current,
				static function ( $row ) use ( $destination_id ) {
					return ( $row['destination_id'] ?? '' ) !== $destination_id;
				}
			)
		);
		$current[] = array(
			'destination_id' => $destination_id,
			'error'          => $error_message,
			'attempted_at'   => gmdate( 'c' ),
			'attempt_count'  => $attempt_count,
		);
		update_post_meta( $post_id, self::FAILURES, $current );
	}

	// ---------- In-flight ----------

	/**
	 * @return string[]
	 */
	public static function get_in_flight( int $post_id ): array {
		return self::read_string_array( $post_id, self::IN_FLIGHT );
	}

	public static function add_in_flight( int $post_id, string $destination_id ): void {
		$current = self::get_in_flight( $post_id );
		if ( ! in_array( $destination_id, $current, true ) ) {
			$current[] = $destination_id;
		}
		update_post_meta( $post_id, self::IN_FLIGHT, $current );
	}

	public static function remove_in_flight( int $post_id, string $destination_id ): void {
		$current = self::get_in_flight( $post_id );
		$current = array_values(
			array_filter(
				$current,
				static function ( $id ) use ( $destination_id ) {
					return $id !== $destination_id;
				}
			)
		);
		update_post_meta( $post_id, self::IN_FLIGHT, $current );
	}

	// ---------- Helpers ----------

	/**
	 * @param mixed $value Raw input.
	 * @return string[]
	 */
	public static function sanitize_string_array( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $entry ) {
			if ( is_string( $entry ) && '' !== $entry ) {
				$out[] = sanitize_key( $entry );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @return string[]
	 */
	private static function read_string_array( int $post_id, string $meta_key ): array {
		$value = get_post_meta( $post_id, $meta_key, true );
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $entry ) {
			if ( is_string( $entry ) && '' !== $entry ) {
				$out[] = $entry;
			}
		}
		return $out;
	}
}
