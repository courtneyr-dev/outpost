<?php
/**
 * Outpost_Bridgy_Publish_Log
 *
 * Read/write helper for `outpost_bridgy_publish_log` post-meta. Mirrors
 * F10's Manual_Share Audit_Log shape but per-silo and Bridgy-specific:
 *
 *     array(
 *         'id'           => 'uuid-...',           // entry id
 *         'version'      => 1,
 *         'silo_id'      => 'mastodon',           // bridgy silo id
 *         'silo_chip_id' => 'bridgy-mastodon',    // F14 chip id
 *         'fired_at'     => '2026-05-04T18:32:11+00:00',
 *         'outcome'      => 'pending'|'success'|'failure',
 *         'completed_at' => null|ISO 8601,
 *         'silo_url'     => null|string,
 *         'error_code'   => null|string,
 *         'error_message'=> null|string,
 *     )
 *
 * Sibling to F10/F12/F13's Manual_Share_Audit_Log. Kept separate
 * because the audit shape differs: Bridgy entries care about
 * `error_code` + `error_message` (Bridgy returns structured errors via
 * webmention response), while Manual_Share entries care about
 * `strategy` + `reminder_dismissed_until`.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Bridgy_Publish_Log {

	private const META_KEY      = 'outpost_bridgy_publish_log';
	private const ENTRY_VERSION = 1;

	public const OUTCOME_PENDING = 'pending';
	public const OUTCOME_SUCCESS = 'success';
	public const OUTCOME_FAILURE = 'failure';

	/**
	 * Append a new log entry — typically called when Outpost fires
	 * a webmention to brid.gy. Outcome starts as 'pending' until the
	 * webmention response handler updates it.
	 *
	 * @param int    $post_id      Post id.
	 * @param string $silo_chip_id Silo chip id (e.g. 'bridgy-mastodon').
	 * @param string $silo_id      Bridgy silo id (e.g. 'mastodon').
	 * @return array<string,mixed> The new entry.
	 */
	public static function add_entry( int $post_id, string $silo_chip_id, string $silo_id ): array {
		$entry      = array(
			'id'            => self::generate_id(),
			'version'       => self::ENTRY_VERSION,
			'silo_id'       => $silo_id,
			'silo_chip_id'  => $silo_chip_id,
			'fired_at'      => gmdate( 'c' ),
			'outcome'       => self::OUTCOME_PENDING,
			'completed_at'  => null,
			'silo_url'      => null,
			'error_code'    => null,
			'error_message' => null,
		);
		$existing   = self::get_entries( $post_id );
		$existing[] = $entry;
		update_post_meta( $post_id, self::META_KEY, $existing );
		return $entry;
	}

	/**
	 * Update an existing entry by id. Only the listed fields are
	 * touchable; unknown patch keys drop. Updates `completed_at` to
	 * `now()` automatically when outcome becomes success or failure.
	 *
	 * Allowed patch keys: outcome, silo_url, error_code, error_message.
	 *
	 * @param int                  $post_id  Post id.
	 * @param string               $entry_id Entry id from add_entry().
	 * @param array<string,mixed> $patch    Fields to update.
	 * @return bool True on success, false when the entry id was not found.
	 */
	public static function update_entry( int $post_id, string $entry_id, array $patch ): bool {
		$entries = self::get_entries( $post_id );
		$found   = false;
		foreach ( $entries as &$entry ) {
			if ( ( $entry['id'] ?? '' ) === $entry_id ) {
				if ( array_key_exists( 'outcome', $patch ) ) {
					$entry['outcome'] = self::sanitize_outcome( (string) $patch['outcome'] );
					if ( self::OUTCOME_PENDING !== $entry['outcome']
						&& null === $entry['completed_at'] ) {
						$entry['completed_at'] = gmdate( 'c' );
					}
				}
				if ( array_key_exists( 'silo_url', $patch ) ) {
					$entry['silo_url'] = self::sanitize_silo_url( $patch['silo_url'] );
				}
				if ( array_key_exists( 'error_code', $patch ) ) {
					$entry['error_code'] = is_string( $patch['error_code'] ) && '' !== $patch['error_code']
						? sanitize_key( $patch['error_code'] )
						: null;
				}
				if ( array_key_exists( 'error_message', $patch ) ) {
					$entry['error_message'] = is_string( $patch['error_message'] ) && '' !== $patch['error_message']
						? sanitize_text_field( $patch['error_message'] )
						: null;
				}
				$found = true;
				break;
			}
		}
		unset( $entry );

		if ( ! $found ) {
			return false;
		}

		update_post_meta( $post_id, self::META_KEY, $entries );
		return true;
	}

	/**
	 * Read entries for a post.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function get_entries( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $entry ) {
			if ( is_array( $entry ) && isset( $entry['id'], $entry['version'] ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * The post-meta key. Exposed so REST registration + the renderer
	 * reference one constant.
	 */
	public static function meta_key(): string {
		return self::META_KEY;
	}

	private static function generate_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return (string) wp_generate_uuid4();
		}
		return bin2hex( random_bytes( 16 ) );
	}

	private static function sanitize_outcome( string $outcome ): string {
		$allowed = array(
			self::OUTCOME_PENDING,
			self::OUTCOME_SUCCESS,
			self::OUTCOME_FAILURE,
		);
		return in_array( $outcome, $allowed, true ) ? $outcome : self::OUTCOME_PENDING;
	}

	/**
	 * @param mixed $value Raw silo URL from response handler.
	 */
	private static function sanitize_silo_url( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$clean = esc_url_raw( $value );
		if ( '' === $clean ) {
			return null;
		}
		if ( 0 !== strpos( $clean, 'http://' ) && 0 !== strpos( $clean, 'https://' ) ) {
			return null;
		}
		return $clean;
	}
}
