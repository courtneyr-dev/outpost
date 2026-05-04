<?php
/**
 * Verifies each F9 default platform declares the correct iOS strategy
 * chain per F11 prompt's per-platform spec (Doc 1 §4.4).
 *
 * Each test pins ONE platform's chain so a config drift produces a
 * clearly-named failure.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Manual_Share_Platform_Config;
use Outpost_Manual_Share_Platform_Registry;
use WP_Mock;

final class IosStrategyChainsTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Manual_Share_Platform_Registry::reset_for_tests();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Manual_Share_Platform_Registry::reset_for_tests();
	}

	private function chain_for( string $id ): array {
		foreach ( Outpost_Manual_Share_Platform_Registry::default_configs() as $config ) {
			if ( ( $config['id'] ?? '' ) === $id ) {
				return is_array( $config['ios_strategy'] ) ? $config['ios_strategy'] : array();
			}
		}
		return array();
	}

	private function app_url_scheme_for( string $id ): ?string {
		foreach ( Outpost_Manual_Share_Platform_Registry::default_configs() as $config ) {
			if ( ( $config['id'] ?? '' ) === $id ) {
				$scheme = $config['app_url_scheme'] ?? null;
				return is_string( $scheme ) ? $scheme : null;
			}
		}
		return null;
	}

	private function web_intent_url_for( string $id ): ?string {
		foreach ( Outpost_Manual_Share_Platform_Registry::default_configs() as $config ) {
			if ( ( $config['id'] ?? '' ) === $id ) {
				$url = $config['web_intent_url'] ?? null;
				return is_string( $url ) ? $url : null;
			}
		}
		return null;
	}

	// =====================================================================
	// Per-platform chain assertions
	// =====================================================================

	public function test_instagram_feed_chain(): void {
		$this->assertSame(
			array( 'navigator_share_files', 'app_url_scheme', 'manual' ),
			$this->chain_for( 'instagram-feed' )
		);
		$this->assertSame( 'instagram://library?AssetPath=', $this->app_url_scheme_for( 'instagram-feed' ) );
	}

	public function test_instagram_stories_chain(): void {
		$this->assertSame(
			array( 'navigator_share_files', 'app_url_scheme', 'manual' ),
			$this->chain_for( 'instagram-stories' )
		);
		$this->assertSame( 'instagram-stories://share?source=', $this->app_url_scheme_for( 'instagram-stories' ) );
	}

	public function test_facebook_chain(): void {
		$this->assertSame(
			array( 'navigator_share_files', 'web_intent', 'manual' ),
			$this->chain_for( 'facebook' )
		);
		$this->assertNull( $this->app_url_scheme_for( 'facebook' ) );
		$this->assertSame(
			'https://www.facebook.com/sharer.php?u=@source_url',
			$this->web_intent_url_for( 'facebook' )
		);
	}

	public function test_x_twitter_chain(): void {
		$this->assertSame(
			array( 'navigator_share_files', 'web_intent', 'manual' ),
			$this->chain_for( 'x-twitter' )
		);
		$this->assertNull( $this->app_url_scheme_for( 'x-twitter' ) );
		$this->assertStringContainsString(
			'twitter.com/intent/tweet',
			(string) $this->web_intent_url_for( 'x-twitter' )
		);
	}

	public function test_linkedin_chain(): void {
		$this->assertSame(
			array( 'navigator_share_files', 'app_url_scheme', 'manual' ),
			$this->chain_for( 'linkedin' )
		);
		$this->assertSame( 'linkedin://', $this->app_url_scheme_for( 'linkedin' ) );
	}

	public function test_threads_chain(): void {
		$this->assertSame(
			array( 'navigator_share_files', 'web_intent', 'manual' ),
			$this->chain_for( 'threads' )
		);
		$this->assertNull( $this->app_url_scheme_for( 'threads' ) );
		$this->assertStringContainsString(
			'threads.net/intent/post',
			(string) $this->web_intent_url_for( 'threads' )
		);
	}

	public function test_tiktok_chain(): void {
		$this->assertSame(
			array( 'navigator_share_files', 'app_url_scheme', 'manual' ),
			$this->chain_for( 'tiktok' )
		);
		$this->assertSame( 'tiktok://', $this->app_url_scheme_for( 'tiktok' ) );
	}

	public function test_pinterest_chain(): void {
		$this->assertSame(
			array( 'navigator_share_files', 'web_intent', 'manual' ),
			$this->chain_for( 'pinterest' )
		);
		$this->assertNull( $this->app_url_scheme_for( 'pinterest' ) );
	}

	public function test_reddit_manual_chain(): void {
		$this->assertSame(
			array( 'navigator_share_files', 'web_intent', 'manual' ),
			$this->chain_for( 'reddit-manual' )
		);
	}

	public function test_flickr_manual_chain(): void {
		$this->assertSame(
			array( 'navigator_share_files', 'app_url_scheme', 'manual' ),
			$this->chain_for( 'flickr-manual' )
		);
		$this->assertSame( 'flickr://', $this->app_url_scheme_for( 'flickr-manual' ) );
	}

	// =====================================================================
	// Cross-platform invariants
	// =====================================================================

	public function test_every_chain_starts_with_navigator_share_files(): void {
		// PWA-installed iOS Safari supports navigator.share with files
		// since 16.4. Putting it first means every platform attempts the
		// best UX path before falling through.
		foreach ( Outpost_Manual_Share_Platform_Registry::default_configs() as $config ) {
			$chain = $config['ios_strategy'];
			$this->assertSame(
				'navigator_share_files',
				$chain[0],
				sprintf(
					'Platform %s should start its iOS chain with navigator_share_files.',
					$config['id']
				)
			);
		}
	}

	public function test_every_chain_ends_with_manual(): void {
		// Every chain must terminate with `manual` so the worst-case
		// fallback always fires. No platform should be able to leave the
		// user with "nothing happened" after a chip tap.
		foreach ( Outpost_Manual_Share_Platform_Registry::default_configs() as $config ) {
			$chain = $config['ios_strategy'];
			$this->assertSame(
				'manual',
				$chain[ count( $chain ) - 1 ],
				sprintf(
					'Platform %s should terminate its iOS chain with manual fallback.',
					$config['id']
				)
			);
		}
	}

	public function test_every_app_url_scheme_platform_uses_app_url_scheme_in_chain(): void {
		// Inverse: if a platform declares an app_url_scheme, its chain
		// MUST include 'app_url_scheme' as a strategy. Otherwise the
		// scheme is dead config.
		foreach ( Outpost_Manual_Share_Platform_Registry::default_configs() as $config ) {
			if ( empty( $config['app_url_scheme'] ) ) {
				continue;
			}
			$this->assertContains(
				'app_url_scheme',
				$config['ios_strategy'],
				sprintf(
					'Platform %s declares app_url_scheme but does not list it in ios_strategy.',
					$config['id']
				)
			);
		}
	}

	public function test_every_web_intent_url_platform_uses_web_intent_in_chain(): void {
		// Symmetric: web_intent_url + ios chain MUST list 'web_intent'.
		foreach ( Outpost_Manual_Share_Platform_Registry::default_configs() as $config ) {
			if ( empty( $config['web_intent_url'] ) ) {
				continue;
			}
			// Some platforms (X) declare web_intent_url for the iOS path
			// AND have caption_via='intent' for Android EXTRA_TEXT. The
			// iOS chain still uses web_intent. Pinterest is similar.
			$this->assertContains(
				'web_intent',
				$config['ios_strategy'],
				sprintf(
					'Platform %s declares web_intent_url but does not list web_intent in its iOS chain.',
					$config['id']
				)
			);
		}
	}
}
