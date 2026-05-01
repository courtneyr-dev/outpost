<?php
/**
 * Unit tests for Outpost_PWA_Assets.
 *
 * The resolver reads `build/pwa/.vite/manifest.json` from disk; tests redirect
 * OUTPOST_PLUGIN_DIR via a temp directory each `setUp()` so we can simulate
 * missing / unreadable / invalid / valid manifest states without touching the
 * real plugin tree.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Outpost_PWA_Assets;

/**
 * @covers \Outpost_PWA_Assets
 */
final class PWAAssetsTest extends TestCase {

	private string $tmp_root;
	private string $manifest_dir;
	private string $manifest_path;

	protected function setUp(): void {
		parent::setUp();

		$this->tmp_root      = sys_get_temp_dir() . '/outpost-pwa-assets-' . uniqid( '', true );
		$this->manifest_dir  = $this->tmp_root . '/build/pwa/.vite';
		$this->manifest_path = $this->manifest_dir . '/manifest.json';
		mkdir( $this->tmp_root . '/build/pwa', 0700, true );
		// .vite/ subdirectory is created only when a test wants the manifest
		// file to actually exist.

		Outpost_PWA_Assets::override_paths_for_tests(
			$this->manifest_path,
			'http://example.test/wp-content/plugins/outpost/build/pwa/'
		);
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->tmp_root );
		Outpost_PWA_Assets::override_paths_for_tests( null, null );
		parent::tearDown();
	}

	/** @test */
	public function manifest_returns_null_when_file_is_missing(): void {
		// Manifest dir was not created — file does not exist.
		$this->assertNull( Outpost_PWA_Assets::manifest() );
		$this->assertNull( Outpost_PWA_Assets::entry_url() );
		$this->assertSame( array(), Outpost_PWA_Assets::entry_css_urls() );
	}

	/** @test */
	public function manifest_returns_null_when_json_is_invalid(): void {
		mkdir( $this->manifest_dir, 0700, true );
		file_put_contents( $this->manifest_path, 'not valid json {' );
		$this->assertNull( Outpost_PWA_Assets::manifest() );
		$this->assertNull( Outpost_PWA_Assets::entry_url() );
	}

	/** @test */
	public function entry_url_returns_null_when_entry_record_is_missing(): void {
		$this->write_manifest( array() );
		$this->assertNull( Outpost_PWA_Assets::entry_url() );
		$this->assertSame( array(), Outpost_PWA_Assets::entry_css_urls() );
	}

	/** @test */
	public function entry_url_returns_full_url_for_a_well_formed_manifest(): void {
		$this->write_manifest(
			array(
				'pwa/src/index.tsx' => array(
					'file'    => 'assets/index-abc123.js',
					'name'    => 'index',
					'src'     => 'pwa/src/index.tsx',
					'isEntry' => true,
				),
			)
		);

		$url = Outpost_PWA_Assets::entry_url();
		$this->assertNotNull( $url );
		$this->assertStringEndsWith( 'build/pwa/assets/index-abc123.js', $url );
	}

	/** @test */
	public function entry_css_urls_returns_each_associated_stylesheet(): void {
		$this->write_manifest(
			array(
				'pwa/src/index.tsx' => array(
					'file'    => 'assets/index-abc.js',
					'css'     => array( 'assets/index-def.css', 'assets/structure-ghi.css' ),
					'isEntry' => true,
				),
			)
		);

		$urls = Outpost_PWA_Assets::entry_css_urls();
		$this->assertCount( 2, $urls );
		$this->assertStringEndsWith( 'build/pwa/assets/index-def.css', $urls[0] );
		$this->assertStringEndsWith( 'build/pwa/assets/structure-ghi.css', $urls[1] );
	}

	/** @test */
	public function entry_css_urls_returns_empty_when_entry_has_no_css(): void {
		$this->write_manifest(
			array(
				'pwa/src/index.tsx' => array(
					'file'    => 'assets/index-abc.js',
					'isEntry' => true,
				),
			)
		);
		$this->assertSame( array(), Outpost_PWA_Assets::entry_css_urls() );
	}

	/** @test */
	public function manifest_is_cached_per_request(): void {
		$this->write_manifest(
			array(
				'pwa/src/index.tsx' => array( 'file' => 'assets/first.js' ),
			)
		);

		$first = Outpost_PWA_Assets::entry_url();
		$this->assertNotNull( $first );
		$this->assertStringEndsWith( 'first.js', $first );

		// Replace manifest on disk; the cached version should still be returned.
		$this->write_manifest(
			array(
				'pwa/src/index.tsx' => array( 'file' => 'assets/second.js' ),
			)
		);
		$second = Outpost_PWA_Assets::entry_url();
		$this->assertSame( $first, $second, 'Cached manifest must persist across calls in one request.' );

		// reset_cache_for_tests() forces a re-read.
		Outpost_PWA_Assets::reset_cache_for_tests();
		$third = Outpost_PWA_Assets::entry_url();
		$this->assertNotSame( $first, $third );
		$this->assertStringEndsWith( 'second.js', (string) $third );
	}

	/**
	 * Write a manifest fixture to disk. Does NOT reset the resolver's cache
	 * — caller decides when to invalidate so tests of the cache-per-request
	 * behavior can rewrite the file in place and observe the cached value.
	 *
	 * @param array<string, array<string, mixed>> $manifest_array
	 */
	private function write_manifest( array $manifest_array ): void {
		if ( ! is_dir( $this->manifest_dir ) ) {
			mkdir( $this->manifest_dir, 0700, true );
		}
		file_put_contents( $this->manifest_path, (string) json_encode( $manifest_array ) );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$entries = scandir( $dir );
		if ( false === $entries ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}
