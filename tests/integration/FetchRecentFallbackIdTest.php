<?php
/**
 * T11 residual of F-44: when a provider record has no id, the fallback id
 * must not be an unkeyed hash of a clock time, which a 14-day window makes
 * easy to reverse. It stays stable for the same record.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Fetch_Recent_Oura;
use Outpost_Fetch_Recent_Whoop;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class FetchRecentFallbackIdTest extends TestCase {

	private const OURA_START  = '2026-11-10T17:52:36+00:00';
	private const WHOOP_START = '2026-11-11T22:04:51.000Z';

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Fetch_Recent_Oura' ) || ! class_exists( 'Outpost_Fetch_Recent_Whoop' ) ) {
			$this->markTestSkipped( 'Requires wp-env with Outpost loaded.' );
		}
	}

	/**
	 * @test
	 */
	public function oura_workout_fallback_id_is_not_an_md5_of_its_start(): void {
		$record = array(
			'activity'       => 'running',
			'start_datetime' => self::OURA_START,
		);
		$a = Outpost_Fetch_Recent_Oura::map_workout_item( $record );
		$b = Outpost_Fetch_Recent_Oura::map_workout_item( $record );
		$this->assertSame( $a['id'], $b['id'], 'The fallback id is stable.' );
		$this->assertStringNotContainsString( md5( self::OURA_START ), $a['id'] );
		$this->assertStringNotContainsString( md5( '' ), $a['id'], 'Sanity: the start was read.' );
	}

	/**
	 * @test
	 */
	public function whoop_fallback_ids_are_not_an_md5_of_a_clock_time(): void {
		$cycle = array(
			'start' => self::WHOOP_START,
			'score' => array( 'strain' => 10.2 ),
		);
		$a = Outpost_Fetch_Recent_Whoop::map_cycle_item( $cycle );
		$b = Outpost_Fetch_Recent_Whoop::map_cycle_item( $cycle );
		$this->assertSame( $a['id'], $b['id'], 'The fallback id is stable.' );
		$this->assertStringNotContainsString( md5( self::WHOOP_START ), $a['id'] );

		$recovery = array(
			'created_at' => self::WHOOP_START,
			'score'      => array( 'recovery_score' => 61 ),
		);
		$r1 = Outpost_Fetch_Recent_Whoop::map_recovery_item( $recovery );
		$r2 = Outpost_Fetch_Recent_Whoop::map_recovery_item( $recovery );
		$this->assertSame( $r1['id'], $r2['id'], 'The recovery fallback id is stable.' );
		$this->assertStringNotContainsString( md5( self::WHOOP_START ), $r1['id'] );
	}
}
