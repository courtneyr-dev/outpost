<?php
/**
 * Outpost_Post_Kinds_Adapter::mood_vocabulary() with and without Post Kinds'
 * \PKIW\Mood_Vocabulary (PKIW #207 / #211).
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Post_Kinds_Adapter;
use PHPUnit\Framework\TestCase;

final class PostKindsAdapterMoodVocabularyTest extends TestCase {

	private const FIXTURE_DIR = __DIR__ . '/../fixtures/pkiw/';

	/** @return array<string, mixed> */
	private static function fixture( string $locale ): array {
		return json_decode( (string) file_get_contents( self::FIXTURE_DIR . 'moods-' . $locale . '.json' ), true );
	}

	public function test_without_pkiw_vocabulary_returns_no_moods_and_keeps_mood_slug(): void {
		$this->assertFalse( class_exists( '\PKIW\Mood_Vocabulary' ), 'Precondition: older Post Kinds, no vocabulary class.' );

		$adapter = new Outpost_Post_Kinds_Adapter();

		$this->assertSame( array(), $adapter->mood_vocabulary() );
		$this->assertContains( 'post-kinds.mood', $adapter->feature_slugs() );
		$this->assertCount( 36, $adapter->feature_slugs() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_with_pkiw_vocabulary_relays_its_moods_unchanged(): void {
		require_once self::FIXTURE_DIR . 'mood-vocabulary-stub.php';

		$adapter = new Outpost_Post_Kinds_Adapter();
		$moods   = $adapter->mood_vocabulary();

		// Independent expectation: the fixture file, not the stub's return value.
		$this->assertSame( self::fixture( 'en_GB' )['moods'], $moods );
		$labels = array_column( $moods, 'label' );
		$this->assertContains( 'Energised', $labels );
		$this->assertNotContains( 'Energized', $labels, 'Outpost must not respell labels.' );
		$this->assertContains( 'post-kinds.mood', $adapter->feature_slugs() );
		$this->assertCount( 36, $adapter->feature_slugs() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_with_pkiw_vocabulary_drops_entries_off_the_contract(): void {
		require_once self::FIXTURE_DIR . 'mood-vocabulary-stub.php';
		\PKIW\Mood_Vocabulary::$moods = array(
			array(
				'key'      => 'happy',
				'label'    => 'Happy',
				'variants' => array( 'Happy', 7 ),
			),
			array( 'key' => 'no-label' ),
			'not-an-array',
			array(
				'key'   => 'custom_site_mood',
				'label' => 'Frazzled but fine',
			),
		);

		$adapter = new Outpost_Post_Kinds_Adapter();

		$this->assertSame(
			array(
				array(
					'key'      => 'happy',
					'label'    => 'Happy',
					'variants' => array( 'Happy' ),
				),
				array(
					'key'      => 'custom_site_mood',
					'label'    => 'Frazzled but fine',
					'variants' => array(),
				),
			),
			$adapter->mood_vocabulary()
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_with_pkiw_vocabulary_returning_a_non_array_yields_no_moods(): void {
		require_once self::FIXTURE_DIR . 'mood-vocabulary-stub.php';
		\PKIW\Mood_Vocabulary::$moods = 'unexpected';

		$this->assertSame( array(), ( new Outpost_Post_Kinds_Adapter() )->mood_vocabulary() );
	}
}
