<?php
/**
 * Issue #259: with Post Kinds active, a Polar workout becomes a Post Kinds
 * exercise card holding the activity, distance and duration, filed under
 * the exercise kind. The start time and calories never reach what the
 * picker writes.
 *
 * Post Kinds isn't installed in the test site, so setUp registers its
 * exercise card block and `kind` taxonomy the way Post Kinds 1.9.1 does
 * (src/blocks/exercise-card/block.json, includes/class-taxonomy.php).
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Fetch_Recent_Polar;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class PolarExerciseCardTest extends TestCase {

	private const BLOCK = 'post-kinds-indieweb/exercise-card';

	/** A Polar AccessLink exercise: a 5.23 km run of 29 min 12.6 s. */
	private const RUN = array(
		'id'         => 'ex-42',
		'sport'      => 'RUNNING',
		'start-time' => '2026-11-12T06:41:09',
		'distance'   => 5234.0,
		'duration'   => 'PT29M12.6S',
		'calories'   => 412,
	);

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Fetch_Recent_Polar' ) || ! function_exists( 'register_block_type' ) ) {
			$this->markTestSkipped( 'Requires wp-env with Outpost loaded.' );
		}
	}

	protected function tearDown(): void {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ) ) {
			unregister_block_type( self::BLOCK );
		}
		if ( taxonomy_exists( 'kind' ) ) {
			$term = get_term_by( 'slug', 'exercise', 'kind' );
			if ( $term instanceof \WP_Term ) {
				wp_delete_term( $term->term_id, 'kind' );
			}
			unregister_taxonomy( 'kind' );
		}
		parent::tearDown();
	}

	private function register_post_kinds(): int {
		register_block_type(
			self::BLOCK,
			array(
				'attributes' => array(
					'activity'            => array( 'type' => 'string' ),
					'distance'            => array( 'type' => 'number' ),
					'distanceUnit'        => array(
						'type'    => 'string',
						'default' => 'km',
					),
					'duration'            => array( 'type' => 'string' ),
					'showDetailsPublicly' => array(
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
		$term = wp_insert_term( 'Exercise', 'kind', array( 'slug' => 'exercise' ) );
		$this->assertIsArray( $term );
		return (int) $term['term_id'];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function payload( array $exercise ): array {
		return Outpost_Fetch_Recent_Polar::map_exercise_item( $exercise, 'https://www.polaraccesslink.com/v3/exercises/ex-42' )['post_payload'];
	}

	/**
	 * @test
	 */
	public function a_workout_becomes_an_exercise_card_under_the_exercise_kind(): void {
		$term_id = $this->register_post_kinds();
		$payload = self::payload( self::RUN );

		$this->assertSame(
			array(
				array(
					'name'       => self::BLOCK,
					'attributes' => array(
						'activity'     => 'Running',
						'distance'     => 5.23,
						'distanceUnit' => 'km',
						'duration'     => 'PT29M13S',
					),
				),
			),
			$payload['blocks']
		);
		$this->assertSame( array( 'kind' => array( $term_id ) ), $payload['terms'] );
		$this->assertArrayNotHasKey( 'showDetailsPublicly', $payload['blocks'][0]['attributes'], 'Details stay private until the author opts in.' );
	}

	/**
	 * @test
	 */
	public function no_start_time_or_calories_reach_what_the_picker_writes(): void {
		$this->register_post_kinds();
		$written = (string) wp_json_encode( self::payload( self::RUN ) );

		foreach ( array( '06:41', 'T06', '2026-11-12', 'start', '412', 'calorie' ) as $private ) {
			$this->assertStringNotContainsString( $private, $written, "{$private} stays out of the post." );
		}
	}

	/**
	 * @test
	 */
	public function a_workout_without_distance_or_duration_carries_the_activity_alone(): void {
		$this->register_post_kinds();
		$payload = self::payload(
			array(
				'id'    => 'ex-43',
				'sport' => 'YOGA',
			)
		);

		$this->assertSame( array( 'activity' => 'Yoga' ), $payload['blocks'][0]['attributes'] );
	}

	/**
	 * @test
	 */
	public function without_post_kinds_the_paragraph_stays(): void {
		$payload = self::payload( self::RUN );

		$this->assertStringStartsWith( '<p>', $payload['content'] );
		$this->assertArrayNotHasKey( 'blocks', $payload );
		$this->assertArrayNotHasKey( 'terms', $payload );
	}
}
