<?php
/**
 * Unit tests for Outpost_Encryption_Key_Notice.
 *
 * Guideline 11: the notice may render only on the Plugins screens and
 * Outpost's own pages, and a dismissal is permanent for its reason.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Encryption_Key_Notice;
use Outpost_Encryption_Key_Resolver;
use PHPUnit\Framework\TestCase;
use WP_Mock;

require_once dirname( __DIR__, 2 ) . '/includes/encryption/class-outpost-encryption-key-resolver.php';
require_once dirname( __DIR__, 2 ) . '/includes/admin/class-outpost-encryption-key-notice.php';

final class EncryptionKeyNoticeTest extends TestCase {

	/** @var array<string, string> */
	private array $user_meta = array();

	protected function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		Outpost_Encryption_Key_Resolver::reset_for_tests();
		$this->user_meta = array();
		unset( $_GET[ Outpost_Encryption_Key_Notice::DISMISS_QUERY_KEY ], $_GET['_wpnonce'], $_GET['action'] );

		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static fn( $name, $fallback = false ) => 'outpost_encryption_key' === $name ? base64_encode( str_repeat( 'k', 32 ) ) : $fallback
		);
		WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			fn( $uid, $key ) => $this->user_meta[ $key ] ?? ''
		);
		WP_Mock::userFunction( 'wp_nonce_url' )->andReturnUsing( static fn( $url ) => $url . '&_wpnonce=n' );
		WP_Mock::passthruFunction( 'esc_url' );
		WP_Mock::passthruFunction( 'esc_html__' );
	}

	protected function tearDown(): void {
		unset( $_GET[ Outpost_Encryption_Key_Notice::DISMISS_QUERY_KEY ], $_GET['_wpnonce'], $_GET['action'] );
		WP_Mock::tearDown();
		parent::tearDown();
	}

	private function given_screen( string $id ): void {
		$screen     = new \WP_Screen();
		$screen->id = $id;
		WP_Mock::userFunction( 'get_current_screen' )->andReturn( $screen );
	}

	private function render(): string {
		ob_start();
		Outpost_Encryption_Key_Notice::maybe_render();
		return (string) ob_get_clean();
	}

	/**
	 * @dataProvider provide_unrelated_screens
	 */
	public function test_notice_stays_off_unrelated_screens( string $screen_id ): void {
		$this->given_screen( $screen_id );
		$this->assertSame( '', $this->render(), "The key notice must not render on '{$screen_id}'." );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_unrelated_screens(): array {
		return array(
			'dashboard'     => array( 'dashboard' ),
			'posts list'    => array( 'edit-post' ),
			'media library' => array( 'upload' ),
			'users'         => array( 'users' ),
			'settings'      => array( 'options-general' ),
		);
	}

	/**
	 * @dataProvider provide_actionable_screens
	 */
	public function test_notice_renders_on_plugins_and_outpost_screens( string $screen_id ): void {
		$this->given_screen( $screen_id );
		$html = $this->render();
		$this->assertStringContainsString( 'outpost-encryption-key-notice', $html );
		$this->assertStringContainsString( 'https://courtneyr-dev.github.io/outpost/settings/#api-keys-tab', $html, 'The instructions link must point at a page that exists.' );
		$this->assertStringNotContainsString( 'page=outpost-docs', $html );
		$this->assertStringContainsString( Outpost_Encryption_Key_Notice::DISMISS_QUERY_KEY, $html, 'Dismiss must use Outpost\'s own query key.' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_actionable_screens(): array {
		return array(
			'plugins'          => array( 'plugins' ),
			'Outpost settings' => array( 'outpost_page_outpost-settings' ),
		);
	}

	public function test_dismissal_is_permanent_across_versions(): void {
		$this->given_screen( 'plugins' );
		$this->user_meta['outpost_dismissed_encryption_key_notice_database'] = '1';
		$this->assertSame( '', $this->render(), 'A dismissal must not depend on the plugin version.' );
	}

	public function test_dismissal_handler_ignores_core_action_parameter(): void {
		$_GET['action'] = 'outpost_dismiss_encryption_key_notice';
		WP_Mock::userFunction( 'check_admin_referer' )->never();
		WP_Mock::userFunction( 'update_user_meta' )->never();
		Outpost_Encryption_Key_Notice::maybe_handle_dismissal();
		$this->addToAssertionCount( 1 ); // Mockery verifies the expectations in tearDown().
	}

	public function test_dismissal_checks_nonce_before_writing(): void {
		$_GET[ Outpost_Encryption_Key_Notice::DISMISS_QUERY_KEY ] = 'database';
		WP_Mock::userFunction( 'check_admin_referer' )->once()->with( 'outpost_dismiss_encryption_key_notice' )->andReturn( 1 );
		WP_Mock::passthruFunction( 'sanitize_key' );
		WP_Mock::passthruFunction( 'wp_unslash' );
		WP_Mock::userFunction( 'update_user_meta' )->once()->with( 7, 'outpost_dismissed_encryption_key_notice_database', '1' );
		WP_Mock::userFunction( 'remove_query_arg' )->andReturn( '/wp-admin/plugins.php' );
		WP_Mock::userFunction( 'wp_safe_redirect' )->once();
		Outpost_Encryption_Key_Notice::maybe_handle_dismissal();
		$this->addToAssertionCount( 1 ); // Mockery verifies the expectations in tearDown().
	}

	public function test_dismissal_rejects_unknown_reason(): void {
		$_GET[ Outpost_Encryption_Key_Notice::DISMISS_QUERY_KEY ] = 'anything';
		WP_Mock::userFunction( 'check_admin_referer' )->once()->andReturn( 1 );
		WP_Mock::passthruFunction( 'sanitize_key' );
		WP_Mock::passthruFunction( 'wp_unslash' );
		WP_Mock::userFunction( 'update_user_meta' )->never();
		WP_Mock::userFunction( 'remove_query_arg' )->andReturn( '/wp-admin/plugins.php' );
		WP_Mock::userFunction( 'wp_safe_redirect' )->once();
		Outpost_Encryption_Key_Notice::maybe_handle_dismissal();
		$this->addToAssertionCount( 1 ); // Mockery verifies the expectations in tearDown().
	}
}
