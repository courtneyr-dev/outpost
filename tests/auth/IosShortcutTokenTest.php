<?php
/**
 * Unit tests for Outpost_IOS_Shortcut_Token (FX-iOS-Shortcut).
 *
 * Covers token lifecycle: generate, store, validate, revoke,
 * regenerate, first-seen recording.
 *
 * @package Outpost\Tests\Auth
 */

declare(strict_types=1);

namespace Outpost\Tests\Auth;

use Outpost_IOS_Shortcut_Token;
use WP_Mock;

final class IosShortcutTokenTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> Per-user-id meta map. */
	private array $user_meta = array();

	/** @var array<string, int> Reverse map: token -> user_id (test resolver). */
	private array $token_index = array();

	public function setUp(): void {
		WP_Mock::setUp();
		$this->user_meta   = array();
		$this->token_index = array();

		// outpost-lint:fixture-credential — synthetic tokens for tests only.
		WP_Mock::userFunction( 'wp_generate_password' )->andReturnUsing(
			static fn ( int $length, bool $special, bool $extra ): string => substr(
				str_replace( array( '+', '/', '=' ), 'A', base64_encode( random_bytes( $length ) ) ),
				0,
				$length
			)
		);

		WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key, bool $single ) {
				$value = $this->user_meta[ $user_id ][ $key ] ?? '';
				return $single ? $value : array( $value );
			}
		);

		WP_Mock::userFunction( 'update_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key, $value ): bool {
				if ( Outpost_IOS_Shortcut_Token::META_KEY === $key ) {
					$old = $this->user_meta[ $user_id ][ $key ] ?? null;
					if ( null !== $old ) {
						unset( $this->token_index[ (string) $old ] );
					}
					$this->token_index[ (string) $value ] = $user_id;
				}
				$this->user_meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);

		WP_Mock::userFunction( 'delete_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key ): bool {
				if ( Outpost_IOS_Shortcut_Token::META_KEY === $key
					&& isset( $this->user_meta[ $user_id ][ $key ] ) ) {
					unset( $this->token_index[ (string) $this->user_meta[ $user_id ][ $key ] ] );
				}
				unset( $this->user_meta[ $user_id ][ $key ] );
				return true;
			}
		);

		Outpost_IOS_Shortcut_Token::set_resolver_for_tests(
			fn ( string $presented ): ?int => $this->token_index[ $presented ] ?? null
		);
	}

	public function tearDown(): void {
		Outpost_IOS_Shortcut_Token::set_resolver_for_tests( null );
		WP_Mock::tearDown();
	}

	public function test_generate_token_string_returns_32_chars(): void {
		$token = Outpost_IOS_Shortcut_Token::generate_token_string();
		$this->assertSame( 32, strlen( $token ) );
	}

	public function test_generate_token_returns_alphanumeric_only(): void {
		$token = Outpost_IOS_Shortcut_Token::generate_token_string();
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]+$/', $token );
	}

	public function test_regenerate_persists_token_in_user_meta(): void {
		$token = Outpost_IOS_Shortcut_Token::regenerate( 42 );

		$this->assertSame( $token, Outpost_IOS_Shortcut_Token::get_token( 42 ) );
	}

	public function test_regenerate_clears_first_seen_marker(): void {
		// Seed a first-seen marker as if a prior connection happened.
		$this->user_meta[42][ Outpost_IOS_Shortcut_Token::FIRST_SEEN_META_KEY ] = '2026-05-04T00:00:00+00:00';

		Outpost_IOS_Shortcut_Token::regenerate( 42 );

		$this->assertNull( Outpost_IOS_Shortcut_Token::get_first_seen( 42 ) );
	}

	public function test_regenerate_replaces_prior_token(): void {
		$first  = Outpost_IOS_Shortcut_Token::regenerate( 42 );
		$second = Outpost_IOS_Shortcut_Token::regenerate( 42 );

		$this->assertNotSame( $first, $second );
		$this->assertSame( $second, Outpost_IOS_Shortcut_Token::get_token( 42 ) );
	}

	public function test_get_token_returns_null_when_no_token_issued(): void {
		$this->assertNull( Outpost_IOS_Shortcut_Token::get_token( 99 ) );
	}

	public function test_revoke_removes_token_and_first_seen(): void {
		Outpost_IOS_Shortcut_Token::regenerate( 42 );
		$this->user_meta[42][ Outpost_IOS_Shortcut_Token::FIRST_SEEN_META_KEY ] = '2026-05-04T12:00:00+00:00';

		Outpost_IOS_Shortcut_Token::revoke( 42 );

		$this->assertNull( Outpost_IOS_Shortcut_Token::get_token( 42 ) );
		$this->assertNull( Outpost_IOS_Shortcut_Token::get_first_seen( 42 ) );
	}

	public function test_record_first_seen_writes_iso_8601_timestamp(): void {
		Outpost_IOS_Shortcut_Token::regenerate( 42 );

		Outpost_IOS_Shortcut_Token::record_first_seen_if_unset( 42 );

		$value = Outpost_IOS_Shortcut_Token::get_first_seen( 42 );
		$this->assertNotNull( $value );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', (string) $value );
	}

	public function test_record_first_seen_is_idempotent(): void {
		// Pre-seed an older first-seen marker; subsequent calls must
		// NOT overwrite (the timestamp tracks FIRST seen, not last).
		$this->user_meta[42][ Outpost_IOS_Shortcut_Token::FIRST_SEEN_META_KEY ] = '2026-04-01T00:00:00+00:00';

		Outpost_IOS_Shortcut_Token::record_first_seen_if_unset( 42 );

		$this->assertSame( '2026-04-01T00:00:00+00:00', Outpost_IOS_Shortcut_Token::get_first_seen( 42 ) );
	}

	public function test_get_first_seen_returns_null_when_never_set(): void {
		$this->assertNull( Outpost_IOS_Shortcut_Token::get_first_seen( 42 ) );
	}

	public function test_resolve_token_returns_null_for_empty_string(): void {
		$this->assertNull( Outpost_IOS_Shortcut_Token::resolve_token_to_user_id( '' ) );
	}

	public function test_resolve_token_returns_user_id_when_token_matches(): void {
		$token = Outpost_IOS_Shortcut_Token::regenerate( 42 );

		$resolved = Outpost_IOS_Shortcut_Token::resolve_token_to_user_id( $token );

		$this->assertSame( 42, $resolved );
	}

	public function test_resolve_token_returns_null_for_unknown_token(): void {
		// outpost-lint:fixture-credential — synthetic test token.
		$resolved = Outpost_IOS_Shortcut_Token::resolve_token_to_user_id( 'unknown-token-not-in-store' );

		$this->assertNull( $resolved );
	}

	public function test_resolve_token_finds_correct_user_among_many(): void {
		Outpost_IOS_Shortcut_Token::regenerate( 1 );
		$alice_token = Outpost_IOS_Shortcut_Token::regenerate( 2 );
		Outpost_IOS_Shortcut_Token::regenerate( 3 );

		$this->assertSame( 2, Outpost_IOS_Shortcut_Token::resolve_token_to_user_id( $alice_token ) );
	}
}
