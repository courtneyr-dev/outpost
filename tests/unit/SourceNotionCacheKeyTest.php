<?php
/**
 * Unit tests for Outpost_Source_Notion::cache_key() — the cache scoping that
 * stops one user's private Notion page from being served to another.
 *
 * The key is pure (page id + user id + a hashed token fingerprint), so it is
 * unit-testable without WordPress; the cross-user runtime behavior is proven in
 * tests/integration/SourceNotionCacheTest.php.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Source_Notion;

final class SourceNotionCacheKeyTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		\WP_Mock::setUp();
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
	}

	/**
	 * @return string
	 */
	private function key( string $page_id, int $user_id, string $token ): string {
		$ref = new \ReflectionMethod( Outpost_Source_Notion::class, 'cache_key' );
		return (string) $ref->invoke( null, $page_id, $user_id, $token );
	}

	public function test_same_inputs_produce_the_same_key(): void {
		$this->assertSame(
			$this->key( 'abc123', 7, 'tok' ),
			$this->key( 'abc123', 7, 'tok' )
		);
	}

	public function test_different_users_get_different_keys_for_the_same_page(): void {
		$a = $this->key( 'abc123', 7, 'tok' );
		$b = $this->key( 'abc123', 8, 'tok' );
		$this->assertNotSame( $a, $b, 'Two users must not share a cache entry for the same page.' );
	}

	public function test_token_rotation_invalidates_the_key(): void {
		$before = $this->key( 'abc123', 7, 'old-token' );
		$after  = $this->key( 'abc123', 7, 'new-token' );
		$this->assertNotSame( $before, $after, 'A reconnect / token rotation must change the key.' );
	}

	public function test_different_pages_get_different_keys(): void {
		$this->assertNotSame(
			$this->key( 'page-a', 7, 'tok' ),
			$this->key( 'page-b', 7, 'tok' )
		);
	}

	public function test_key_never_contains_the_raw_token(): void {
		$token = 'secret_ntn_ABCDEFG1234567';
		$key   = $this->key( 'abc123', 7, $token );
		$this->assertStringNotContainsString( $token, $key, 'The raw token must never appear in a transient key.' );
		$this->assertStringNotContainsString( 'secret_ntn', $key );
	}

	public function test_key_is_prefixed_and_names_the_page_and_user(): void {
		$key = $this->key( 'abc123', 7, 'tok' );
		$this->assertStringStartsWith( 'outpost_notion_page_abc123_7_', $key );
	}
}
