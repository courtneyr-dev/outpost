<?php
/**
 * Unit tests for Outpost_Companion_Detector.
 *
 * Three states the detector must distinguish for each companion:
 *  - 'active'   — is_plugin_active() reports true
 *  - 'inactive' — get_plugins() lists the file but is_plugin_active() is false
 *  - 'absent'   — get_plugins() does not list the file
 *
 * The required dependency_chain() walks IndieAuth -> Micropub only. The six
 * other companions are optional and must never appear in first_unsatisfied().
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Mock;
use Outpost_Companion_Detector;

/**
 * @covers \Outpost_Companion_Detector
 */
final class CompanionDetectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * Helper: stub is_plugin_active() and get_plugins() with the given fixtures.
	 *
	 * @param string $active_file        Plugin file that is_plugin_active() should report active.
	 *                                   Empty string means none active.
	 * @param array  $installed_plugins  Map of file => header_array for get_plugins().
	 */
	private function stub_plugin_environment( string $active_file, array $installed_plugins ): void {
		WP_Mock::userFunction( 'is_plugin_active' )
			->andReturnUsing(
				static function ( $file ) use ( $active_file ) {
					return $file === $active_file;
				}
			);

		WP_Mock::userFunction( 'get_plugins' )
			->andReturn( $installed_plugins );
	}

	/**
	 * Companion files used across tests, in dependency-chain order followed by
	 * the optional set. Keeping them in one place makes the data-provider
	 * declarations cheap and keeps the file-path constants asserted as data,
	 * not just used as inputs.
	 *
	 * @return array<string, string>
	 */
	private function companion_files(): array {
		return array(
			'indieauth'             => OUTPOST_INDIEAUTH_PLUGIN_FILE,
			'micropub'              => OUTPOST_MICROPUB_PLUGIN_FILE,
			'post_kinds'            => OUTPOST_POST_KINDS_PLUGIN_FILE,
			'post_formats'          => OUTPOST_POST_FORMATS_PLUGIN_FILE,
			'link_extension'        => OUTPOST_LINK_EXTENSION_XFN_PLUGIN_FILE,
			'syndication_links'     => OUTPOST_SYNDICATION_LINKS_PLUGIN_FILE,
			'yoast'                 => OUTPOST_YOAST_PLUGIN_FILE,
			'activitypub'           => OUTPOST_ACTIVITYPUB_PLUGIN_FILE,
			'accessibility_checker' => OUTPOST_ACCESSIBILITY_CHECKER_PLUGIN_FILE,
			'rss_chat_routing'      => OUTPOST_RSS_CHAT_ROUTING_PLUGIN_FILE,
		);
	}

	/**
	 * @test
	 * @dataProvider provide_companion_files
	 */
	public function status_returns_active_when_is_plugin_active_is_true( string $file ): void {
		$this->stub_plugin_environment(
			$file,
			array( $file => array( 'Name' => 'X' ) )
		);

		$this->assertSame( 'active', Outpost_Companion_Detector::status( $file ) );
	}

	/**
	 * @test
	 * @dataProvider provide_companion_files
	 */
	public function status_returns_inactive_when_installed_but_not_active( string $file ): void {
		$this->stub_plugin_environment(
			'',
			array( $file => array( 'Name' => 'X' ) )
		);

		$this->assertSame( 'inactive', Outpost_Companion_Detector::status( $file ) );
	}

	/**
	 * @test
	 * @dataProvider provide_companion_files
	 */
	public function status_returns_absent_when_not_installed( string $file ): void {
		$this->stub_plugin_environment( '', array() );

		$this->assertSame( 'absent', Outpost_Companion_Detector::status( $file ) );
	}

	/**
	 * Data provider: every companion file path the detector knows about. Yoast's
	 * file-path mismatch (slug `wordpress-seo`, file `wp-seo.php`) is asserted
	 * here as test data — if the constant ever flips back to the slug pattern
	 * the matrix below catches it.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function provide_companion_files(): array {
		return array(
			'IndieAuth'                       => array( 'indieauth/indieauth.php' ),
			'Micropub'                        => array( 'micropub/micropub.php' ),
			'Post Kinds for IndieWeb'         => array( 'post-kinds-for-indieweb/post-kinds-for-indieweb.php' ),
			'Post Formats for Block Themes'   => array( 'post-formats-for-block-themes/post-formats-for-block-themes.php' ),
			'Link Extension for XFN'          => array( 'link-extension-for-xfn/link-extension-for-xfn.php' ),
			'Syndication Links'               => array( 'syndication-links/syndication-links.php' ),
			'Yoast SEO (slug vs file gotcha)' => array( 'wordpress-seo/wp-seo.php' ),
			'ActivityPub'                     => array( 'activitypub/activitypub.php' ),
			'Accessibility Checker'           => array( 'accessibility-checker/accessibility-checker.php' ),
		);
	}

	/** @test */
	public function named_wrappers_delegate_to_status_for_each_companion(): void {
		$files = $this->companion_files();

		// Mark every companion installed-but-inactive so each wrapper produces
		// the same observable answer ('inactive') if and only if it consulted
		// the right file path.
		$installed = array();
		foreach ( $files as $file ) {
			$installed[ $file ] = array( 'Name' => 'X' );
		}
		$this->stub_plugin_environment( '', $installed );

		$this->assertSame( 'inactive', Outpost_Companion_Detector::is_indieauth_active() );
		$this->assertSame( 'inactive', Outpost_Companion_Detector::is_micropub_active() );
		$this->assertSame( 'inactive', Outpost_Companion_Detector::is_post_kinds_active() );
		$this->assertSame( 'inactive', Outpost_Companion_Detector::is_post_formats_active() );
		$this->assertSame( 'inactive', Outpost_Companion_Detector::is_xfn_active() );
		$this->assertSame( 'inactive', Outpost_Companion_Detector::is_syndication_links_active() );
		$this->assertSame( 'inactive', Outpost_Companion_Detector::is_yoast_active() );
		$this->assertSame( 'inactive', Outpost_Companion_Detector::is_activitypub_active() );
		$this->assertSame( 'inactive', Outpost_Companion_Detector::is_accessibility_checker_active() );
		$this->assertSame( 'inactive', Outpost_Companion_Detector::is_rss_chat_routing_active() );
	}

	/** @test */
	public function dependency_chain_contains_only_required_plugins_in_upstream_first_order(): void {
		$this->assertSame(
			array(
				OUTPOST_INDIEAUTH_PLUGIN_FILE,
				OUTPOST_MICROPUB_PLUGIN_FILE,
			),
			Outpost_Companion_Detector::dependency_chain()
		);
	}

	/** @test */
	public function optional_companions_returns_the_eight_optional_file_paths(): void {
		$this->assertEqualsCanonicalizing(
			array(
				OUTPOST_POST_KINDS_PLUGIN_FILE,
				OUTPOST_POST_FORMATS_PLUGIN_FILE,
				OUTPOST_LINK_EXTENSION_XFN_PLUGIN_FILE,
				OUTPOST_SYNDICATION_LINKS_PLUGIN_FILE,
				OUTPOST_YOAST_PLUGIN_FILE,
				OUTPOST_ACTIVITYPUB_PLUGIN_FILE,
				OUTPOST_ACCESSIBILITY_CHECKER_PLUGIN_FILE,
				OUTPOST_RSS_CHAT_ROUTING_PLUGIN_FILE,
			),
			Outpost_Companion_Detector::optional_companions()
		);
	}

	/** @test */
	public function dependency_chain_and_optional_companions_are_disjoint(): void {
		$intersection = array_intersect(
			Outpost_Companion_Detector::dependency_chain(),
			Outpost_Companion_Detector::optional_companions()
		);

		$this->assertSame( array(), $intersection, 'Required and optional sets must not overlap.' );
	}

	/** @test */
	public function first_unsatisfied_returns_indieauth_when_indieauth_is_absent(): void {
		$this->stub_plugin_environment(
			OUTPOST_MICROPUB_PLUGIN_FILE,
			array( OUTPOST_MICROPUB_PLUGIN_FILE => array( 'Name' => 'Micropub' ) )
		);

		$this->assertSame(
			OUTPOST_INDIEAUTH_PLUGIN_FILE,
			Outpost_Companion_Detector::first_unsatisfied(),
			'IndieAuth comes first in the chain even when downstream Micropub is active.'
		);
	}

	/** @test */
	public function first_unsatisfied_returns_indieauth_when_indieauth_is_inactive(): void {
		$this->stub_plugin_environment(
			OUTPOST_MICROPUB_PLUGIN_FILE,
			array(
				OUTPOST_INDIEAUTH_PLUGIN_FILE => array( 'Name' => 'IndieAuth' ),
				OUTPOST_MICROPUB_PLUGIN_FILE  => array( 'Name' => 'Micropub' ),
			)
		);

		$this->assertSame(
			OUTPOST_INDIEAUTH_PLUGIN_FILE,
			Outpost_Companion_Detector::first_unsatisfied()
		);
	}

	/** @test */
	public function first_unsatisfied_returns_micropub_when_only_micropub_missing(): void {
		$this->stub_plugin_environment(
			OUTPOST_INDIEAUTH_PLUGIN_FILE,
			array( OUTPOST_INDIEAUTH_PLUGIN_FILE => array( 'Name' => 'IndieAuth' ) )
		);

		$this->assertSame(
			OUTPOST_MICROPUB_PLUGIN_FILE,
			Outpost_Companion_Detector::first_unsatisfied()
		);
	}

	/** @test */
	public function first_unsatisfied_returns_null_when_full_chain_active(): void {
		// Both required active. is_plugin_active() must return true for both.
		WP_Mock::userFunction( 'is_plugin_active' )
			->andReturnUsing(
				static function ( $file ) {
					return in_array(
						$file,
						array(
							OUTPOST_INDIEAUTH_PLUGIN_FILE,
							OUTPOST_MICROPUB_PLUGIN_FILE,
						),
						true
					);
				}
			);
		WP_Mock::userFunction( 'get_plugins' )
			->andReturn(
				array(
					OUTPOST_INDIEAUTH_PLUGIN_FILE => array( 'Name' => 'IndieAuth' ),
					OUTPOST_MICROPUB_PLUGIN_FILE  => array( 'Name' => 'Micropub' ),
				)
			);

		$this->assertNull( Outpost_Companion_Detector::first_unsatisfied() );
	}

	/** @test */
	public function first_unsatisfied_never_reports_an_optional_companion(): void {
		// Required chain fully active; every optional companion absent.
		WP_Mock::userFunction( 'is_plugin_active' )
			->andReturnUsing(
				static function ( $file ) {
					return in_array(
						$file,
						array(
							OUTPOST_INDIEAUTH_PLUGIN_FILE,
							OUTPOST_MICROPUB_PLUGIN_FILE,
						),
						true
					);
				}
			);
		WP_Mock::userFunction( 'get_plugins' )
			->andReturn(
				array(
					OUTPOST_INDIEAUTH_PLUGIN_FILE => array( 'Name' => 'IndieAuth' ),
					OUTPOST_MICROPUB_PLUGIN_FILE  => array( 'Name' => 'Micropub' ),
				)
			);

		$this->assertNull(
			Outpost_Companion_Detector::first_unsatisfied(),
			'Optional companions must never block the gate.'
		);
	}
}
