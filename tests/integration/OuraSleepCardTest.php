<?php
/**
 * Issue #260: with Post Kinds active, an Oura night becomes a Post Kinds
 * sleep card filed under the sleep kind, dated at noon of its night, with
 * no clock time anywhere in what the picker writes.
 *
 * Post Kinds isn't installed in the test site, so setUp registers its
 * sleep card block and `kind` taxonomy the way Post Kinds 1.9.1 does
 * (src/blocks/sleep-card/block.json, includes/class-taxonomy.php).
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Fetch_Recent_Oura;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class OuraSleepCardTest extends TestCase {

	private const BLOCK = 'post-kinds-indieweb/sleep-card';

	/** A night that starts before midnight, Chicago time: 7 h 42 min. */
	private const NIGHT = array(
		'id'                   => 'abc',
		'day'                  => '2026-11-13',
		'bedtime_start'        => '2026-11-12T23:40:00-06:00',
		'bedtime_end'          => '2026-11-13T07:22:00-06:00',
		'total_sleep_duration' => 27720,
		'score'                => 81,
	);

	private string $timezone = '';

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Fetch_Recent_Oura' ) || ! function_exists( 'register_block_type' ) ) {
			$this->markTestSkipped( 'Requires wp-env with Outpost loaded.' );
		}
		$this->timezone = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/Chicago' );
	}

	protected function tearDown(): void {
		update_option( 'timezone_string', $this->timezone );
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			unregister_block_type( self::BLOCK );
		}
		if ( taxonomy_exists( 'kind' ) ) {
			$term = get_term_by( 'slug', 'sleep', 'kind' );
			if ( $term instanceof \WP_Term ) {
				wp_delete_term( $term->term_id, 'kind' );
			}
			unregister_taxonomy( 'kind' );
		}
		parent::tearDown();
	}

	private function register_post_kinds( bool $with_sleep_term = true ): int {
		register_block_type(
			self::BLOCK,
			array(
				'attributes' => array(
					'durationMinutes'   => array( 'type' => 'integer' ),
					'bedtime'           => array( 'type' => 'string' ),
					'wakeTime'          => array( 'type' => 'string' ),
					'showTimesPublicly' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_taxonomy(
			'kind',
			'post',
			array(
				'show_in_rest' => true,
				'rest_base'    => 'kind',
			)
		);
		if ( ! $with_sleep_term ) {
			return 0;
		}
		$term = wp_insert_term( 'Sleep', 'kind', array( 'slug' => 'sleep' ) );
		$this->assertIsArray( $term );
		return (int) $term['term_id'];
	}

	/**
	 * @test
	 */
	public function a_night_becomes_a_sleep_card_under_the_sleep_kind_at_noon(): void {
		$term_id = $this->register_post_kinds();
		$payload = Outpost_Fetch_Recent_Oura::map_sleep_item( self::NIGHT )['post_payload'];

		$this->assertSame(
			array(
				array(
					'name'       => self::BLOCK,
					'attributes' => array( 'durationMinutes' => 462 ),
				),
			),
			$payload['blocks']
		);
		$this->assertSame( array( 'kind' => array( $term_id ) ), $payload['terms'] );
		$this->assertSame( '2026-11-12T12:00:00', $payload['date'], 'Noon of the night the sleep began.' );
	}

	/**
	 * @test
	 */
	public function a_bedtime_after_midnight_files_under_the_night_before(): void {
		$this->register_post_kinds();
		$night                  = self::NIGHT;
		$night['bedtime_start'] = '2026-11-13T00:40:00-06:00';
		$payload                = Outpost_Fetch_Recent_Oura::map_sleep_item( $night )['post_payload'];

		$this->assertSame( '2026-11-12T12:00:00', $payload['date'] );
	}

	/**
	 * @test
	 */
	public function no_clock_time_reaches_what_the_picker_writes(): void {
		$this->register_post_kinds();
		$payload = Outpost_Fetch_Recent_Oura::map_sleep_item( self::NIGHT )['post_payload'];
		$written = (string) wp_json_encode( $payload );

		foreach ( array( '23:40', '07:22', 'T23', 'T07', 'T05', 'bedtime', 'wakeTime' ) as $clock ) {
			$this->assertStringNotContainsString( $clock, $written, "{$clock} stays out of the post." );
		}
	}

	/**
	 * @test
	 */
	public function without_the_sleep_term_the_card_still_goes_in(): void {
		// Post Kinds files a post under the kind of its first card on save,
		// so the card alone is enough when the term doesn't exist yet.
		$this->register_post_kinds( false );
		$payload = Outpost_Fetch_Recent_Oura::map_sleep_item( self::NIGHT )['post_payload'];

		$this->assertSame( self::BLOCK, $payload['blocks'][0]['name'] );
		$this->assertArrayNotHasKey( 'terms', $payload );
	}

	/**
	 * @test
	 */
	public function a_night_with_no_bedtime_leaves_the_date_to_the_editor(): void {
		$this->register_post_kinds();
		$night = self::NIGHT;
		unset( $night['bedtime_start'] );
		$payload = Outpost_Fetch_Recent_Oura::map_sleep_item( $night )['post_payload'];

		$this->assertSame( self::BLOCK, $payload['blocks'][0]['name'] );
		$this->assertArrayNotHasKey( 'date', $payload );
	}

	/**
	 * @test
	 */
	public function without_post_kinds_the_paragraph_stays(): void {
		$payload = Outpost_Fetch_Recent_Oura::map_sleep_item( self::NIGHT )['post_payload'];

		$this->assertStringStartsWith( '<p>', $payload['content'] );
		$this->assertArrayNotHasKey( 'blocks', $payload );
		$this->assertArrayNotHasKey( 'terms', $payload );
		$this->assertArrayNotHasKey( 'date', $payload );
	}
}
