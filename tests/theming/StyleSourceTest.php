<?php
/**
 * Unit tests for Outpost_Style_Source: the per-user Outpost Default /
 * Use Site Theme choice and the new-user vs. upgrade default.
 *
 * @package Outpost\Tests\Theming
 */

declare(strict_types=1);

namespace Outpost\Tests\Theming;

use Outpost_Style_Source;
use WP_Mock;

final class StyleSourceTest extends \WP_Mock\Tools\TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, array<string, mixed>> */
	private array $user_meta = array();

	/** @var array<int, string> user_id => user_registered (UTC, MySQL format) */
	private array $registered = array();

	private const UPGRADE_TIME = 1_762_000_000; // 2025-11-01T12:26:40Z

	public function setUp(): void {
		WP_Mock::setUp();
		$this->options    = array();
		$this->user_meta  = array();
		$this->registered = array();

		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			fn ( string $key, $default = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default
		);
		WP_Mock::userFunction( 'add_option' )->andReturnUsing(
			function ( string $key, $value ): bool {
				if ( array_key_exists( $key, $this->options ) ) {
					return false;
				}
				$this->options[ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			fn ( int $user_id, string $key, bool $single ) => $this->user_meta[ $user_id ][ $key ] ?? ''
		);
		WP_Mock::userFunction( 'update_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key, $value ): bool {
				$this->user_meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		WP_Mock::userFunction( 'get_userdata' )->andReturnUsing(
			function ( int $user_id ) {
				if ( ! isset( $this->registered[ $user_id ] ) ) {
					return false;
				}
				$user                  = new \stdClass();
				$user->user_registered = $this->registered[ $user_id ];
				return $user;
			}
		);
		Outpost_Style_Source::set_clock_for_tests( static fn (): int => self::UPGRADE_TIME );
	}

	public function tearDown(): void {
		Outpost_Style_Source::set_clock_for_tests( null );
		WP_Mock::tearDown();
	}

	private function fresh_install(): void {
		Outpost_Style_Source::ensure_schema();
	}

	private function upgrade_from_previous_version(): void {
		$this->options['outpost_rewrite_version'] = '1.0.23';
		Outpost_Style_Source::ensure_schema();
	}

	public function test_fresh_install_records_zero_cutoff(): void {
		$this->fresh_install();
		$this->assertSame(
			array(
				'version'       => 1,
				'legacy_cutoff' => 0,
			),
			$this->options[ Outpost_Style_Source::SCHEMA_OPTION ]
		);
	}

	public function test_upgrade_records_upgrade_time_as_cutoff(): void {
		$this->upgrade_from_previous_version();
		$this->assertSame( self::UPGRADE_TIME, $this->options[ Outpost_Style_Source::SCHEMA_OPTION ]['legacy_cutoff'] );
	}

	public function test_schema_marker_is_written_once(): void {
		$this->fresh_install();
		// A later request on a site that has since run the flush must not
		// reclassify the install as an upgrade.
		$this->options['outpost_rewrite_version'] = '1.0.24';
		Outpost_Style_Source::ensure_schema();
		$this->assertSame( 0, $this->options[ Outpost_Style_Source::SCHEMA_OPTION ]['legacy_cutoff'] );
	}

	public function test_new_user_on_fresh_install_gets_outpost_default(): void {
		$this->fresh_install();
		$this->registered[5] = '2020-01-01 00:00:00';
		$this->assertSame( 'outpost', Outpost_Style_Source::get_source( 5 ) );
	}

	public function test_existing_user_on_upgrade_keeps_site_theme(): void {
		$this->upgrade_from_previous_version();
		$this->registered[5] = '2024-06-01 08:00:00';
		$this->assertSame( 'site', Outpost_Style_Source::get_source( 5 ) );
		$this->assertFalse( Outpost_Style_Source::has_stored_source( 5 ) );
	}

	public function test_user_registered_after_upgrade_gets_outpost_default(): void {
		$this->upgrade_from_previous_version();
		$this->registered[9] = gmdate( 'Y-m-d H:i:s', self::UPGRADE_TIME + 60 );
		$this->assertSame( 'outpost', Outpost_Style_Source::get_source( 9 ) );
	}

	public function test_stored_mode_value_does_not_decide_the_source(): void {
		// A user who joined after the upgrade and already picked a mode
		// still gets the new default; a pre-upgrade user who never picked
		// one still keeps Site Theme.
		$this->upgrade_from_previous_version();
		$this->registered[9]                           = gmdate( 'Y-m-d H:i:s', self::UPGRADE_TIME + 60 );
		$this->user_meta[9]['outpost_appearance_mode'] = 'night';
		$this->registered[5]                           = '2024-06-01 08:00:00';
		$this->assertSame( 'outpost', Outpost_Style_Source::get_source( 9 ) );
		$this->assertSame( 'site', Outpost_Style_Source::get_source( 5 ) );
	}

	public function test_stored_choice_wins_over_migration_default(): void {
		$this->upgrade_from_previous_version();
		$this->registered[5]                             = '2024-06-01 08:00:00';
		$this->user_meta[5]['outpost_appearance_source'] = 'outpost';
		$this->assertSame( 'outpost', Outpost_Style_Source::get_source( 5 ) );
		$this->assertTrue( Outpost_Style_Source::has_stored_source( 5 ) );
	}

	public function test_set_source_persists_valid_value(): void {
		$this->assertTrue( Outpost_Style_Source::set_source( 5, 'SITE' ) );
		$this->assertSame( 'site', $this->user_meta[5]['outpost_appearance_source'] );
	}

	public function test_set_source_rejects_unknown_value_without_writing(): void {
		$this->assertFalse( Outpost_Style_Source::set_source( 5, 'forest' ) );
		$this->assertArrayNotHasKey( 5, $this->user_meta );
	}

	public function test_anonymous_user_gets_outpost_default(): void {
		$this->upgrade_from_previous_version();
		$this->assertSame( 'outpost', Outpost_Style_Source::get_source( 0 ) );
	}

	public function test_upgraded_site_before_marker_treats_users_as_existing(): void {
		// The marker is written on init; a lookup that happens first on an
		// upgraded site must not hand existing users the new default.
		$this->options['outpost_rewrite_version'] = '1.0.23';
		$this->registered[5]                      = '2024-06-01 08:00:00';
		$this->assertSame( 'site', Outpost_Style_Source::get_source( 5 ) );
	}
}
