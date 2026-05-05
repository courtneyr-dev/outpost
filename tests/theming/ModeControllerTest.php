<?php
/**
 * Unit tests for Outpost_Mode_Controller (FY-Theming).
 *
 * @package Outpost\Tests\Theming
 */

declare(strict_types=1);

namespace Outpost\Tests\Theming;

use Outpost_Mode_Controller;
use WP_Mock;

final class ModeControllerTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $user_meta = array();

	/** @var int */
	private int $current_user_id = 0;

	public function setUp(): void {
		WP_Mock::setUp();
		$this->user_meta       = array();
		$this->current_user_id = 0;

		WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key, bool $single ) {
				$value = $this->user_meta[ $user_id ][ $key ] ?? '';
				return $single ? $value : array( $value );
			}
		);
		WP_Mock::userFunction( 'update_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key, $value ): bool {
				$this->user_meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'get_current_user_id' )->andReturnUsing(
			fn (): int => $this->current_user_id
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	// --- get_mode --------------------------------------------------------

	public function test_get_mode_defaults_to_system_for_new_user(): void {
		$this->assertSame( 'system', Outpost_Mode_Controller::get_mode( 42 ) );
	}

	public function test_get_mode_returns_persisted_value(): void {
		Outpost_Mode_Controller::set_mode( 42, 'night' );
		$this->assertSame( 'night', Outpost_Mode_Controller::get_mode( 42 ) );
	}

	public function test_get_mode_resolves_zero_to_current_user(): void {
		$this->current_user_id = 7;
		Outpost_Mode_Controller::set_mode( 7, 'day' );

		$this->assertSame( 'day', Outpost_Mode_Controller::get_mode( 0 ) );
	}

	public function test_get_mode_returns_system_for_anonymous_request(): void {
		// current user is 0 (not logged in); default to system.
		$this->assertSame( 'system', Outpost_Mode_Controller::get_mode( 0 ) );
	}

	public function test_get_mode_falls_back_when_value_is_invalid(): void {
		// Direct meta corruption — get_user_meta returns junk.
		$this->user_meta[42][ Outpost_Mode_Controller::META_KEY ] = 'sepia';
		$this->assertSame( 'system', Outpost_Mode_Controller::get_mode( 42 ) );
	}

	public function test_get_mode_normalizes_case(): void {
		// Stored value lowercased on write; if something writes
		// upper-case directly, get_mode tolerates it.
		$this->user_meta[42][ Outpost_Mode_Controller::META_KEY ] = 'Night';
		$this->assertSame( 'night', Outpost_Mode_Controller::get_mode( 42 ) );
	}

	// --- set_mode --------------------------------------------------------

	public function test_set_mode_persists_valid_value(): void {
		$this->assertTrue( Outpost_Mode_Controller::set_mode( 42, 'night' ) );
		$this->assertSame( 'night', $this->user_meta[42][ Outpost_Mode_Controller::META_KEY ] );
	}

	public function test_set_mode_normalizes_input(): void {
		Outpost_Mode_Controller::set_mode( 42, '  Day  ' );
		$this->assertSame( 'day', $this->user_meta[42][ Outpost_Mode_Controller::META_KEY ] );
	}

	public function test_set_mode_rejects_invalid_value(): void {
		$this->assertFalse( Outpost_Mode_Controller::set_mode( 42, 'twilight' ) );
		$this->assertArrayNotHasKey( Outpost_Mode_Controller::META_KEY, $this->user_meta[42] ?? array() );
	}

	public function test_set_mode_rejects_invalid_user_id(): void {
		$this->assertFalse( Outpost_Mode_Controller::set_mode( 0, 'day' ) );
		$this->assertFalse( Outpost_Mode_Controller::set_mode( -1, 'day' ) );
	}

	// --- root_class_for_user --------------------------------------------

	public function test_root_class_for_user_default_is_system(): void {
		$this->assertSame( 'outpost-mode-system', Outpost_Mode_Controller::root_class_for_user( 99 ) );
	}

	public function test_root_class_for_user_reflects_persisted_mode(): void {
		Outpost_Mode_Controller::set_mode( 42, 'day' );
		$this->assertSame( 'outpost-mode-day', Outpost_Mode_Controller::root_class_for_user( 42 ) );

		Outpost_Mode_Controller::set_mode( 42, 'night' );
		$this->assertSame( 'outpost-mode-night', Outpost_Mode_Controller::root_class_for_user( 42 ) );
	}

	public function test_valid_modes_constant_exposes_three_modes(): void {
		$this->assertSame(
			array( 'day', 'night', 'system' ),
			Outpost_Mode_Controller::VALID_MODES
		);
	}
}
