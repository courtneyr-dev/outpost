<?php
/**
 * Outpost_Bridgy_Publish_Webmention_Response_Handler
 *
 * Processes webmention responses from brid.gy. When Outpost sends a
 * webmention to a brid.gy publish endpoint, Bridgy responds with:
 *
 *   - HTTP 201 + JSON body containing the silo URL on success
 *   - HTTP 4xx/5xx + JSON body containing error code + message
 *     on failure
 *
 * The handler:
 *
 *   1. Parses the response body (JSON; falls back to plain text on
 *      non-JSON shapes).
 *   2. On success: extracts the silo URL, updates the publish-log
 *      entry's outcome=success + silo_url + completed_at, AND writes
 *      the silo URL to F12's `outpost_syndication_links` post-meta
 *      so the post's `u-syndication` rendering reflects the live
 *      Bridgy-published URL.
 *   3. On failure: updates the publish-log entry with outcome=failure
 *      + error_code + error_message.
 *
 * Webmention SENDING is deferred to a future session — Outpost has
 * no existing webmention sender (per F12 #11). This handler ships
 * the response-processing path so when a sender lands, the wiring
 * is one connector away. F14 tests cover the response parsing in
 * isolation against synthetic Bridgy response bodies.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Bridgy_Publish_Webmention_Response_Handler {

	/**
	 * Process a webmention response from brid.gy.
	 *
	 * @param int    $post_id      Post id the publish was for.
	 * @param string $log_entry_id The pending publish-log entry id.
	 * @param int    $http_status  HTTP status code from the webmention call.
	 * @param string $body         Response body.
	 * @return bool True when the entry was updated; false when the entry
	 *              wasn't found.
	 */
	public static function process_response(
		int $post_id,
		string $log_entry_id,
		int $http_status,
		string $body
	): bool {
		$parsed = self::parse_body( $body );

		if ( $http_status >= 200 && $http_status < 300 ) {
			$silo_url = self::extract_silo_url( $parsed, $body );
			if ( null === $silo_url ) {
				// Bridgy returned 2xx without a silo URL — defensive.
				return Outpost_Bridgy_Publish_Log::update_entry(
					$post_id,
					$log_entry_id,
					array(
						'outcome'       => Outpost_Bridgy_Publish_Log::OUTCOME_FAILURE,
						'error_code'    => 'no_silo_url',
						'error_message' => 'Bridgy returned success but no silo URL.',
					)
				);
			}
			$silo_chip_id = self::silo_chip_id_from_log( $post_id, $log_entry_id );
			$updated      = Outpost_Bridgy_Publish_Log::update_entry(
				$post_id,
				$log_entry_id,
				array(
					'outcome'  => Outpost_Bridgy_Publish_Log::OUTCOME_SUCCESS,
					'silo_url' => $silo_url,
				)
			);
			if ( $updated && '' !== $silo_chip_id ) {
				// Write to F12's outpost_syndication_links so the
				// post's u-syndication rendering picks up the new URL.
				Outpost_Manual_Share_Syndication_Writeback::add_or_update_link(
					$post_id,
					$silo_chip_id,
					$silo_url
				);
			}
			return $updated;
		}

		// Failure path — extract error code + message, update log.
		$error_code    = self::extract_error_code( $parsed, $http_status );
		$error_message = self::extract_error_message( $parsed, $body );

		return Outpost_Bridgy_Publish_Log::update_entry(
			$post_id,
			$log_entry_id,
			array(
				'outcome'       => Outpost_Bridgy_Publish_Log::OUTCOME_FAILURE,
				'error_code'    => $error_code,
				'error_message' => $error_message,
			)
		);
	}

	/**
	 * @return array<string,mixed>|null Decoded JSON, or null on non-JSON body.
	 */
	private static function parse_body( string $body ): ?array {
		$decoded = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return null;
		}
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * @param array<string,mixed>|null $parsed
	 */
	private static function extract_silo_url( ?array $parsed, string $body ): ?string {
		if ( is_array( $parsed ) ) {
			// Bridgy commonly returns { url: "https://..." } on success.
			$candidate = $parsed['url'] ?? null;
			if ( is_string( $candidate ) && '' !== $candidate ) {
				return self::sanitize_url( $candidate );
			}
			$candidate = $parsed['silo_url'] ?? null;
			if ( is_string( $candidate ) && '' !== $candidate ) {
				return self::sanitize_url( $candidate );
			}
		}
		// Fall back to a plain-text URL body (Bridgy historically also
		// returns the URL as the body).
		$trimmed = trim( $body );
		if ( '' !== $trimmed
			&& ( 0 === strpos( $trimmed, 'http://' ) || 0 === strpos( $trimmed, 'https://' ) ) ) {
			return self::sanitize_url( $trimmed );
		}
		return null;
	}

	/**
	 * @param array<string,mixed>|null $parsed
	 */
	private static function extract_error_code( ?array $parsed, int $http_status ): string {
		if ( is_array( $parsed ) ) {
			$code = $parsed['error'] ?? ( $parsed['code'] ?? null );
			if ( is_string( $code ) && '' !== $code ) {
				return sanitize_key( $code );
			}
		}
		return 'http_' . $http_status;
	}

	/**
	 * @param array<string,mixed>|null $parsed
	 */
	private static function extract_error_message( ?array $parsed, string $body ): string {
		if ( is_array( $parsed ) ) {
			$msg = $parsed['message'] ?? ( $parsed['error_description'] ?? null );
			if ( is_string( $msg ) && '' !== $msg ) {
				return $msg;
			}
		}
		// Fall back to body trimmed to a reasonable size.
		$trimmed = trim( $body );
		if ( strlen( $trimmed ) > 500 ) {
			$trimmed = substr( $trimmed, 0, 500 ) . '…';
		}
		return $trimmed;
	}

	private static function sanitize_url( string $url ): ?string {
		$clean = esc_url_raw( $url );
		if ( '' === $clean ) {
			return null;
		}
		if ( 0 !== strpos( $clean, 'http://' ) && 0 !== strpos( $clean, 'https://' ) ) {
			return null;
		}
		return $clean;
	}

	/**
	 * Look up the silo chip id for a publish-log entry. Used to
	 * route the silo URL into F12's syndication_links via the
	 * matching platform identifier.
	 */
	private static function silo_chip_id_from_log( int $post_id, string $log_entry_id ): string {
		foreach ( Outpost_Bridgy_Publish_Log::get_entries( $post_id ) as $entry ) {
			if ( ( $entry['id'] ?? '' ) === $log_entry_id ) {
				return (string) ( $entry['silo_chip_id'] ?? '' );
			}
		}
		return '';
	}
}
