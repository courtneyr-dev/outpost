<?php
/**
 * Test double for Post Kinds' \PKIW\Mood_Vocabulary (PKIW #211).
 *
 * Loaded only inside a separate PHPUnit process, so the rest of the suite
 * keeps running without the class, as it does on an older Post Kinds.
 *
 * @package Outpost\Tests
 */

declare(strict_types=1);

namespace PKIW;

final class Mood_Vocabulary {

	// What get_moods() returns; null loads the en_GB fixture's moods.
	public static $moods = null;

	public static function get_moods( ?string $spelling = null ) {
		if ( null === self::$moods ) {
			$fixture     = json_decode( (string) file_get_contents( __DIR__ . '/moods-en_GB.json' ), true );
			self::$moods = $fixture['moods'];
		}
		return self::$moods;
	}
}
