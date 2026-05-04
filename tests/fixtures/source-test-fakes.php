<?php
/**
 * Test fixture: a configurable Outpost_Source_Base subclass for F5
 * unit tests. Lets each test specify just the capability shape
 * fields it cares about; defaults fill in the rest.
 *
 * Generic example values only — alice / bob domains, example.com.
 *
 * @package Outpost\Tests\Fixtures
 */

declare(strict_types=1);

if ( ! class_exists( 'Outpost_F5TestSource_Stub' ) ) {

	final class Outpost_F5TestSource_Stub extends \Outpost_Source_Base { // phpcs:ignore WordPress.NamingConventions.ValidClassName.NotPascalCase

		/**
		 * Capability shape this stub returns. Provided per-test.
		 *
		 * @var array<string,mixed>
		 */
		private array $caps_override;

		/**
		 * Static counter so each constructed stub picks a unique id
		 * unless one is provided. Avoids registry duplicate-id
		 * collisions when multiple stubs register in one test.
		 *
		 * @var int
		 */
		private static int $instance_counter = 0;

		/**
		 * @param array<string,mixed> $caps_override Per-test capability fields.
		 */
		public function __construct( array $caps_override = array() ) {
			++self::$instance_counter;
			if ( ! isset( $caps_override['id'] ) ) {
				$caps_override['id'] = 'f5-test-stub-' . self::$instance_counter;
			}
			$this->caps_override = $caps_override;
		}

		public function capabilities(): array {
			$defaults = array(
				'id'               => 'f5-test-stub',
				'label'            => 'F5 Test Source Stub',
				'host_patterns'    => array( 'example.com' ),
				'ambiguity'        => 'unambiguous',
				'mode'             => 'note',
				'mode_options'     => null,
				'mode_default'     => null,
				'extractor'        => 'oembed',
				'recipe'           => array( 'endpoint' => 'https://example.com/oembed?url={url}' ),
				'mapping'          => array(),
				'h_entry_property' => null,
				'auth_required'    => false,
				'tags_default'     => array(),
				'caveats'          => array(),
			);
			return array_merge( $defaults, $this->caps_override );
		}
	}
}
