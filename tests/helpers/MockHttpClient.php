<?php
/**
 * MockHttpClient
 *
 * Fixture-driven response builder for tests that exercise code paths
 * touching `wp_safe_remote_get`. The pattern is: register a URL → body
 * mapping at test setup, then mock `wp_safe_remote_get` to delegate
 * to `response_for()`. Unmatched URLs throw so tests cannot silently
 * pass against a request the caller didn't expect.
 *
 * IMPORTANT — F8 architectural note (CLAUDE.md):
 *
 * F5's `Outpost_Source_Extractor_Oembed` does NOT make HTTP calls.
 * The preview endpoint (`Outpost_Preview_Endpoint`) owns
 * `wp_safe_remote_get` and feeds the response body to
 * `Extractor_Oembed::parse()`. The F8 prompt's literal request for
 * `Extractor_Oembed::set_http_client()` doesn't fit F5's design — the
 * extractor never sees a URL, only a body string.
 *
 * Net effect: MockHttpClient lives at the test layer, not the
 * production layer. Tests that exercise the full preview-endpoint
 * pipeline (currently wp-env-pending integration stubs) will register
 * fixtures here and wire `wp_safe_remote_get` mocks to delegate. Unit
 * tests for `Extractor_Oembed::parse()` and `Source_Spotify` work
 * directly off `SourceFixtureLoader::load_oembed_fixture()` without
 * touching MockHttpClient at all — there's no HTTP layer to mock.
 *
 * @package Outpost\Tests\Helpers
 */

declare(strict_types=1);

namespace Outpost\Tests\Helpers;

final class MockHttpClient {

	/**
	 * URL → response-array mapping.
	 *
	 * @var array<string, array<string,mixed>>
	 */
	private array $registered = array();

	/**
	 * Register a URL → body mapping.
	 *
	 * @param string              $url     Exact URL to match (no pattern matching — keep tests deterministic).
	 * @param string              $body    Response body.
	 * @param int                 $status  HTTP status code (default 200).
	 * @param array<string,string> $headers Optional additional response headers.
	 */
	public function register( string $url, string $body, int $status = 200, array $headers = array() ): self {
		$this->registered[ $url ] = $this->build_response( $body, $status, $headers );
		return $this;
	}

	/**
	 * Register a URL → fixture mapping. Convenience over manually
	 * loading a fixture body and calling `register()`.
	 *
	 * @param string $url       Exact URL to match.
	 * @param string $source_id Source identifier (e.g. 'spotify').
	 * @param string $scenario  Scenario filename without extension.
	 * @param string $extension Fixture file extension without the leading dot (default 'json').
	 * @param int    $status    HTTP status code (default 200).
	 *
	 * @throws \RuntimeException When the fixture is missing.
	 */
	public function register_fixture(
		string $url,
		string $source_id,
		string $scenario,
		string $extension = 'json',
		int $status = 200
	): self {
		$body = SourceFixtureLoader::load_raw_fixture( $source_id, $scenario, $extension );
		$content_type = 'json' === $extension ? 'application/json' : 'text/html';
		return $this->register( $url, $body, $status, array( 'content-type' => $content_type ) );
	}

	/**
	 * Register an error response (no body, just a status code).
	 *
	 * @param string $url    Exact URL to match.
	 * @param int    $status HTTP status code (e.g. 503).
	 * @param string $body   Optional response body (default empty).
	 */
	public function register_error( string $url, int $status, string $body = '' ): self {
		return $this->register( $url, $body, $status );
	}

	/**
	 * Return the registered response for a URL, or throw if unmatched.
	 *
	 * Returned array matches the shape `wp_safe_remote_get` produces,
	 * so tests can wire it through `WP_Mock::userFunction(
	 * 'wp_safe_remote_get' )->andReturnUsing(...)` without further
	 * adaptation.
	 *
	 * @param string $url URL to look up.
	 * @return array<string,mixed> wp_safe_remote_get-shaped response array.
	 *
	 * @throws \RuntimeException When no registered URL matches.
	 */
	public function response_for( string $url ): array {
		if ( ! isset( $this->registered[ $url ] ) ) {
			throw new \RuntimeException(
				sprintf(
					'MockHttpClient: unmatched URL %s. Tests must register every URL the code under test will request; unmatched URLs would mask which fixture was actually consumed.',
					$url
				)
			);
		}
		return $this->registered[ $url ];
	}

	/**
	 * Forget all registered URLs.
	 */
	public function reset(): void {
		$this->registered = array();
	}

	/**
	 * Construct a wp_safe_remote_get-shaped response array.
	 *
	 * @param string              $body    Response body.
	 * @param int                 $status  HTTP status code.
	 * @param array<string,string> $headers Optional headers.
	 * @return array<string,mixed>
	 */
	private function build_response( string $body, int $status, array $headers ): array {
		return array(
			'headers'       => $headers,
			'body'          => $body,
			'response'      => array(
				'code'    => $status,
				'message' => $this->status_message( $status ),
			),
			'cookies'       => array(),
			'filename'      => null,
			'http_response' => null,
		);
	}

	/**
	 * Map a status code to a canonical reason phrase. Tests rarely
	 * need this, but `wp_safe_remote_get` callers sometimes inspect
	 * `response.message`, so we populate it for shape parity.
	 */
	private function status_message( int $status ): string {
		$map = array(
			200 => 'OK',
			301 => 'Moved Permanently',
			302 => 'Found',
			400 => 'Bad Request',
			401 => 'Unauthorized',
			403 => 'Forbidden',
			404 => 'Not Found',
			429 => 'Too Many Requests',
			500 => 'Internal Server Error',
			502 => 'Bad Gateway',
			503 => 'Service Unavailable',
			504 => 'Gateway Timeout',
		);
		return $map[ $status ] ?? '';
	}
}
