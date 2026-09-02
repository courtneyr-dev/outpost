<?php
/**
 * Integration test: Notion source cache is per-user (item 7).
 *
 * The old `fetch_page()` read a transient keyed on the page id alone, BEFORE
 * checking credentials — so user A's private Notion page could be returned to
 * user B (a different or absent connection). The fix checks credentials first
 * and scopes the cache key to the page + user + a hashed token fingerprint.
 *
 * Notion's HTTP API is mocked with `pre_http_request`; credentials are seeded
 * through the real `Outpost_Credentials_Store` (encrypted user meta). No live
 * Notion connection is used.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SourceNotionCacheTest extends TestCase {

	private int $user_a = 0;
	private int $user_b = 0;
	private int $user_c = 0;

	/** @var array<int,string> user id => access token returned to Notion API mock */
	private array $tokens = array();

	private string $page_url = 'https://www.notion.so/My-Private-Page-0123456789abcdef0123456789abcdef';
	private string $page_id  = '0123456789abcdef0123456789abcdef';

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_insert_user' ) || ! class_exists( 'Outpost_Source_Notion' )
			|| ! class_exists( 'Outpost_Credentials_Store' ) ) {
			$this->markTestSkipped( 'Run via `npm run test:integration`.' );
		}
		$this->user_a = $this->make_user();
		$this->user_b = $this->make_user();
		$this->user_c = $this->make_user();
		add_filter( 'pre_http_request', array( $this, 'mock_notion' ), 10, 3 );
	}

	protected function tearDown(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		remove_filter( 'pre_http_request', array( $this, 'mock_notion' ), 10 );
		foreach ( array( $this->user_a, $this->user_b, $this->user_c ) as $uid ) {
			if ( $uid ) {
				wp_delete_user( $uid );
			}
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function make_user(): int {
		return (int) wp_insert_user(
			array(
				'user_login' => 'notion_u_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'notion_u_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
	}

	private function connect( int $user_id, string $token ): void {
		$this->tokens[ $user_id ] = $token;
		\Outpost_Credentials_Store::set( 'notion', array( 'access_token' => $token ), $user_id );
	}

	/**
	 * Mock Notion's API: the response embeds which token authenticated, so a
	 * cache leak across users is detectable in the returned content.
	 */
	public function mock_notion( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'api.notion.com' ) ) {
			return $pre;
		}
		$auth  = isset( $args['headers']['Authorization'] ) ? (string) $args['headers']['Authorization'] : '';
		$token = trim( str_ireplace( 'Bearer', '', $auth ) );
		$body  = wp_json_encode(
			array(
				'object'     => strpos( (string) $url, '/blocks/' ) !== false ? 'list' : 'page',
				'id'         => $this->page_id,
				'results'    => array(),
				'seen_by'    => $token, // marker: which credential fetched this.
			)
		);
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => $body,
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * @test
	 */
	public function one_users_cached_page_is_never_served_to_another(): void {
		$this->connect( $this->user_a, 'token-A-private' );
		$this->connect( $this->user_b, 'token-B-private' );

		$a = \Outpost_Source_Notion::fetch_page( $this->page_url, $this->user_a );
		$this->assertIsArray( $a );
		$this->assertSame( 'token-A-private', $a['page']['seen_by'] ?? null, 'A fetches with A\'s token.' );

		// B fetches the SAME page id — must NOT get A's cached content; B\'s own
		// token must authenticate the fetch.
		$b = \Outpost_Source_Notion::fetch_page( $this->page_url, $this->user_b );
		$this->assertIsArray( $b );
		$this->assertSame( 'token-B-private', $b['page']['seen_by'] ?? null, 'B must fetch with B\'s own token, not read A\'s cache.' );
	}

	/**
	 * @test
	 */
	public function a_disconnected_user_gets_an_error_not_a_cached_page(): void {
		$this->connect( $this->user_a, 'token-A-private' );
		$a = \Outpost_Source_Notion::fetch_page( $this->page_url, $this->user_a );
		$this->assertIsArray( $a, 'A primes the cache.' );

		// C has no Notion connection. Credentials are checked before the cache,
		// so C gets a not-connected error, never A\'s cached page.
		$c = \Outpost_Source_Notion::fetch_page( $this->page_url, $this->user_c );
		$this->assertInstanceOf( \WP_Error::class, $c );
		$this->assertSame( 'outpost_notion_not_connected', $c->get_error_code() );
	}

	/**
	 * @test
	 */
	public function the_same_user_reuses_their_own_cache(): void {
		$this->connect( $this->user_a, 'token-A-private' );
		$fetches = 0;
		$counter = function ( $pre, $args, $url ) use ( &$fetches ) {
			if ( false !== strpos( (string) $url, 'api.notion.com' ) ) {
				++$fetches;
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $counter, 9, 3 );
		try {
			\Outpost_Source_Notion::fetch_page( $this->page_url, $this->user_a );
			$after_first = $fetches;
			\Outpost_Source_Notion::fetch_page( $this->page_url, $this->user_a );
			$after_second = $fetches;
		} finally {
			remove_filter( 'pre_http_request', $counter, 9 );
		}
		$this->assertGreaterThan( 0, $after_first, 'First call hits the API.' );
		$this->assertSame( $after_first, $after_second, 'Second call for the same user is served from cache (no new API calls).' );

		\delete_transient( 'outpost_notion_page_' . $this->page_id . '_' . $this->user_a . '_' . substr( hash( 'sha256', 'token-A-private' ), 0, 16 ) );
	}
}
