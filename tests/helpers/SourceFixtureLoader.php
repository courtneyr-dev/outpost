<?php
/**
 * SourceFixtureLoader
 *
 * Single helper that every Source_* unit and integration test reaches
 * for fixture files. Establishes the location convention locked in F8
 * Session Log: `tests/fixtures/sources/{source_id}/{scenario}.{ext}`.
 *
 * Three load methods, each returning the shape downstream tests
 * actually want:
 *
 *   load_oembed_fixture()  decoded oEmbed JSON object as an
 *                          associative array (the shape the source's
 *                          map_extracted() consumes).
 *   load_html_fixture()    raw HTML string (the shape Extractor_Og_Tags
 *                          and Extractor_Mf2 will consume in F16+).
 *   load_json_fixture()    decoded JSON (any shape — for API_Json
 *                          extractor fixtures starting in later F sessions).
 *
 * Tests load fixtures by source id + scenario name; the loader resolves
 * the path. If the file is missing, the loader throws so the failure
 * names the missing fixture rather than producing a confusing
 * "json_decode returned null" downstream.
 *
 * @package Outpost\Tests\Helpers
 */

declare(strict_types=1);

namespace Outpost\Tests\Helpers;

final class SourceFixtureLoader {

	/**
	 * Repository-relative root for source fixtures.
	 */
	private const FIXTURES_SUBPATH = '/tests/fixtures/sources/';

	/**
	 * Load an oEmbed fixture and return the decoded JSON object.
	 *
	 * @param string $source_id Source identifier (e.g. 'spotify').
	 * @param string $scenario  Scenario filename without extension (e.g. 'oembed-track-success').
	 * @return array<string,mixed> Decoded oEmbed object.
	 *
	 * @throws \RuntimeException When the fixture is missing or cannot be parsed as a JSON object.
	 */
	public static function load_oembed_fixture( string $source_id, string $scenario ): array {
		$body    = self::read_fixture_body( $source_id, $scenario, 'json' );
		$decoded = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new \RuntimeException(
				sprintf(
					'SourceFixtureLoader: %s/%s.json is not valid JSON: %s',
					$source_id,
					$scenario,
					json_last_error_msg()
				)
			);
		}
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException(
				sprintf(
					'SourceFixtureLoader: %s/%s.json decoded to a non-array; oEmbed fixtures must be JSON objects.',
					$source_id,
					$scenario
				)
			);
		}
		return $decoded;
	}

	/**
	 * Load an HTML fixture and return its raw content.
	 *
	 * @param string $source_id Source identifier.
	 * @param string $scenario  Scenario filename without extension.
	 * @return string Raw HTML content.
	 *
	 * @throws \RuntimeException When the fixture is missing.
	 */
	public static function load_html_fixture( string $source_id, string $scenario ): string {
		return self::read_fixture_body( $source_id, $scenario, 'html' );
	}

	/**
	 * Load a JSON fixture and return the decoded structure (any shape).
	 *
	 * @param string $source_id Source identifier.
	 * @param string $scenario  Scenario filename without extension.
	 * @return mixed Decoded JSON value.
	 *
	 * @throws \RuntimeException When the fixture is missing or invalid JSON.
	 */
	public static function load_json_fixture( string $source_id, string $scenario ) {
		$body    = self::read_fixture_body( $source_id, $scenario, 'json' );
		$decoded = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new \RuntimeException(
				sprintf(
					'SourceFixtureLoader: %s/%s.json is not valid JSON: %s',
					$source_id,
					$scenario,
					json_last_error_msg()
				)
			);
		}
		return $decoded;
	}

	/**
	 * Load a raw fixture body (any extension). Used directly by tests
	 * that want unparsed content (e.g. the malformed-JSON fixture for
	 * verifying parser error paths, or the 503 fixture for verifying
	 * non-JSON rejection).
	 *
	 * @param string $source_id Source identifier.
	 * @param string $scenario  Scenario filename without extension.
	 * @param string $extension File extension without the leading dot.
	 * @return string Raw fixture body.
	 *
	 * @throws \RuntimeException When the fixture is missing.
	 */
	public static function load_raw_fixture( string $source_id, string $scenario, string $extension ): string {
		return self::read_fixture_body( $source_id, $scenario, $extension );
	}

	/**
	 * Resolve the absolute path to a fixture file.
	 *
	 * @param string $source_id Source identifier.
	 * @param string $scenario  Scenario filename without extension.
	 * @param string $extension File extension without the leading dot.
	 * @return string Absolute path.
	 */
	public static function fixture_path( string $source_id, string $scenario, string $extension ): string {
		$root = dirname( __DIR__, 2 );
		return $root . self::FIXTURES_SUBPATH . $source_id . '/' . $scenario . '.' . $extension;
	}

	/**
	 * Read a fixture file's raw body.
	 *
	 * @throws \RuntimeException When the fixture is missing.
	 */
	private static function read_fixture_body( string $source_id, string $scenario, string $extension ): string {
		$path = self::fixture_path( $source_id, $scenario, $extension );
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException(
				sprintf(
					'SourceFixtureLoader: fixture not found or not readable: %s',
					$path
				)
			);
		}
		$body = file_get_contents( $path );
		if ( false === $body ) {
			throw new \RuntimeException(
				sprintf(
					'SourceFixtureLoader: file_get_contents failed for %s',
					$path
				)
			);
		}
		return $body;
	}
}
