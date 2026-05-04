<?php
/**
 * Test fixture: a Companion_TestRestricted-style adapter with
 * `accepts_modes` restricted to a single mode. Used by F2's
 * CompanionRegistryTest to prove the per-mode chip-filter mechanism
 * works without depending on F9's ManualShare adapter.
 *
 * The class name matches the registry's naming-convention regex
 * (`^Outpost_[A-Z][A-Za-z0-9_]*_Adapter$`) so the registry's
 * `outpost_companion_adapters` filter can register it. Tests pass
 * generic example-only values: no real plugin file, no instance
 * handles, no case-study identifiers.
 *
 * @package Outpost\Tests\Fixtures
 */

declare(strict_types=1);

if ( ! class_exists( 'Outpost_F2TestRestricted_Adapter' ) ) {

	/**
	 * Test adapter with accepts_modes restricted to [ 'photo' ].
	 *
	 * Intended for unit tests only. NOT registered in the default
	 * companion list — tests inject it through the
	 * `outpost_companion_adapters` filter.
	 */
	final class Outpost_F2TestRestricted_Adapter extends \Outpost_Companion_Base { // phpcs:ignore WordPress.NamingConventions.ValidClassName.NotPascalCase

		public const ID = 'f2-test-restricted';

		public function file(): string {
			// Fictional plugin file path — the test that uses this fixture
			// stubs `is_plugin_active` to control the detection state, so
			// the path itself never gets resolved against real disk.
			return 'f2-test-restricted/plugin.php';
		}

		public function label(): string {
			return 'F2 Test Restricted';
		}

		/**
		 * @return string[]
		 */
		public function feature_slugs(): array {
			return array( 'f2-test.restricted' );
		}

		/**
		 * Photo-only chip.
		 *
		 * @return array{
		 *     id: string,
		 *     label: string,
		 *     detected: bool,
		 *     accepts_modes: string[],
		 *     accepts_media: string[],
		 *     max_attachments: int|null,
		 *     alt_passthrough: bool,
		 *     char_limit: int|null,
		 *     caveats: string[],
		 *     requires_auth: bool
		 * }|null
		 */
		public function capabilities(): ?array {
			if ( ! $this->is_active() ) {
				return null;
			}
			return array(
				'id'              => self::ID,
				'label'           => 'Photo-only test target',
				'detected'        => true,
				'accepts_modes'   => array( 'photo' ),
				'accepts_media'   => array( 'image' ),
				'max_attachments' => 4,
				'alt_passthrough' => true,
				'char_limit'      => null,
				'caveats'         => array(),
				'requires_auth'   => false,
			);
		}
	}
}
