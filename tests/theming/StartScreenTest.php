<?php
/**
 * Unit tests for Outpost_Start_Screen: the per-user screen the composer
 * opens to on a normal launch.
 *
 * @package Outpost\Tests\Theming
 */

declare(strict_types=1);

namespace Outpost\Tests\Theming;

use Outpost_Start_Screen;
use WP_Mock;

final class StartScreenTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $user_meta = array();

	/** @var array<int, array{0:int,1:string}> */
	private array $meta_writes = array();

	public function setUp(): void {
		WP_Mock::setUp();
		$this->user_meta   = array();
		$this->meta_writes = array();
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			fn ( int $user_id, string $key, bool $single = false ) => $this->user_meta[ $user_id ][ $key ] ?? ''
		);
		WP_Mock::userFunction( 'update_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key, $value ): bool {
				$this->meta_writes[]                 = array( $user_id, $key );
				$this->user_meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_a_user_without_a_stored_choice_starts_on_home(): void {
		$this->assertSame( 'home', Outpost_Start_Screen::get( 5 ) );
		$this->assertFalse( Outpost_Start_Screen::has_stored( 5 ) );
	}

	public function test_every_destination_can_be_stored_and_read_back(): void {
		$expected = array( 'home', 'note', 'reply', 'photo', 'listen', 'life', 'recipe', 'about' );
		$this->assertSame( $expected, Outpost_Start_Screen::VALID_SCREENS );
		foreach ( $expected as $screen ) {
			$this->assertTrue( Outpost_Start_Screen::set( 5, $screen ), "set {$screen}" );
			$this->assertSame( $screen, Outpost_Start_Screen::get( 5 ), "get {$screen}" );
		}
		$this->assertTrue( Outpost_Start_Screen::has_stored( 5 ) );
	}

	public function test_unknown_values_are_rejected_without_a_write(): void {
		foreach ( array( 'settings', '', '../note', 'article', 'note;' ) as $value ) {
			$this->assertFalse( Outpost_Start_Screen::set( 5, $value ), "rejects '{$value}'" );
		}
		$this->assertSame( array(), $this->meta_writes );
	}

	public function test_case_and_whitespace_are_normalized(): void {
		$this->assertTrue( Outpost_Start_Screen::set( 5, ' Reply' ) );
		$this->assertSame( 'reply', $this->user_meta[5][ Outpost_Start_Screen::META_KEY ] );
	}

	public function test_a_corrupt_stored_value_falls_back_to_home(): void {
		$this->user_meta[5][ Outpost_Start_Screen::META_KEY ] = 'settings';
		$this->assertSame( 'home', Outpost_Start_Screen::get( 5 ) );
		$this->assertFalse( Outpost_Start_Screen::has_stored( 5 ) );
		$this->user_meta[6][ Outpost_Start_Screen::META_KEY ] = array( 'note' );
		$this->assertSame( 'home', Outpost_Start_Screen::get( 6 ) );
	}

	public function test_saving_the_same_value_twice_writes_once(): void {
		Outpost_Start_Screen::set( 5, 'photo' );
		Outpost_Start_Screen::set( 5, 'photo' );
		$this->assertCount( 1, $this->meta_writes );
	}

	public function test_anonymous_users_get_home_and_cannot_save(): void {
		$this->assertSame( 'home', Outpost_Start_Screen::get( 0 ) );
		$this->assertFalse( Outpost_Start_Screen::set( 0, 'note' ) );
		$this->assertFalse( Outpost_Start_Screen::set( -1, 'note' ) );
		$this->assertSame( array(), $this->meta_writes );
	}

	public function test_users_are_independent(): void {
		Outpost_Start_Screen::set( 5, 'recipe' );
		$this->assertSame( 'home', Outpost_Start_Screen::get( 6 ) );
	}
}
