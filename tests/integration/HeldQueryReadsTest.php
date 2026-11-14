<?php
/**
 * The encryption-key dismissal and the OAuth status notice read query values
 * without casts and name only registered providers (T10-S4-28, Phase 4
 * A-02 and A-03).
 *
 * - An array-shaped query value reads as empty with no PHP warning.
 * - Dismissal order stays: manage_options, the key's presence, the nonce,
 *   then the value and the write.
 * - The OAuth notice shows a registered provider's label; an unknown
 *   provider id produces no notice at all.
 *
 * PHP warnings become exceptions for the duration of each test, and
 * wp_die() is caught through a test handler.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Encryption_Key_Notice;
use PHPUnit\Framework\TestCase;

final class HeldQueryReadsTest extends TestCase {

	private const DISMISSAL_DATABASE_META = 'outpost_dismissed_encryption_key_notice_database';

	/** @var array<int, int> */
	private array $users = array();

	/** @var array<string, mixed> */
	private array $saved_get = array();

	/** @var array<string, mixed> */
	private array $saved_request = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Encryption_Key_Notice' ) || ! class_exists( 'Outpost_OAuth_Settings_Page' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap.' );
		}
		$this->saved_get     = $_GET;
		$this->saved_request = $_REQUEST;
		set_error_handler(
			static function ( int $errno, string $errstr ): bool {
				throw new \ErrorException( $errstr, 0, $errno );
			},
			E_WARNING | E_NOTICE | E_DEPRECATED
		);
		add_filter( 'wp_die_handler', array( $this, 'die_handler' ) );
		add_filter( 'wp_redirect', '__return_false' );
	}

	protected function tearDown(): void {
		restore_error_handler();
		remove_filter( 'wp_die_handler', array( $this, 'die_handler' ) );
		remove_filter( 'wp_redirect', '__return_false' );
		$_GET     = $this->saved_get;
		$_REQUEST = $this->saved_request;
		wp_set_current_user( 0 );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->users as $id ) {
			wp_delete_user( $id );
		}
		parent::tearDown();
	}

	/** @return callable */
	public function die_handler(): callable {
		return static function (): void {
			throw new \OverflowException( 'wp_die' );
		};
	}

	private function user( string $role ): int {
		$id            = (int) wp_insert_user(
			array(
				'user_login' => 's428_' . $role . '_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 's428_' . uniqid() . '@example.test',
				'role'       => $role,
			)
		);
		$this->users[] = $id;
		wp_set_current_user( $id );
		return $id;
	}

	/** @param array<string, mixed> $query */
	private function query( array $query ): void {
		$_GET     = $query;
		$_REQUEST = $query;
	}

	private function notice( array $query ): string {
		$this->user( 'administrator' );
		$this->query( $query );
		$method = new \ReflectionMethod( 'Outpost_OAuth_Settings_Page', 'maybe_render_status_notice' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( null );
		return (string) ob_get_clean();
	}

	public function test_an_array_shaped_dismissal_value_writes_nothing_and_raises_no_warning(): void {
		$id = $this->user( 'administrator' );
		$this->query(
			array(
				Outpost_Encryption_Key_Notice::DISMISS_QUERY_KEY => array( 'database' ),
				'_wpnonce'                                       => wp_create_nonce( 'outpost_dismiss_encryption_key_notice' ),
			)
		);

		Outpost_Encryption_Key_Notice::maybe_handle_dismissal();

		$this->assertSame( '', get_user_meta( $id, self::DISMISSAL_DATABASE_META, true ) );
	}

	public function test_a_valid_dismissal_is_recorded(): void {
		$id = $this->user( 'administrator' );
		$this->query(
			array(
				Outpost_Encryption_Key_Notice::DISMISS_QUERY_KEY => 'database',
				'_wpnonce'                                       => wp_create_nonce( 'outpost_dismiss_encryption_key_notice' ),
			)
		);

		Outpost_Encryption_Key_Notice::maybe_handle_dismissal();

		$this->assertSame( '1', get_user_meta( $id, self::DISMISSAL_DATABASE_META, true ) );
	}

	public function test_a_dismissal_without_manage_options_does_nothing(): void {
		$id = $this->user( 'editor' );
		$this->query(
			array(
				Outpost_Encryption_Key_Notice::DISMISS_QUERY_KEY => 'database',
				'_wpnonce'                                       => wp_create_nonce( 'outpost_dismiss_encryption_key_notice' ),
			)
		);

		Outpost_Encryption_Key_Notice::maybe_handle_dismissal();

		$this->assertSame( '', get_user_meta( $id, self::DISMISSAL_DATABASE_META, true ) );
	}

	public function test_a_dismissal_with_a_bad_nonce_stops_before_any_write(): void {
		$id = $this->user( 'administrator' );
		$this->query(
			array(
				Outpost_Encryption_Key_Notice::DISMISS_QUERY_KEY => 'database',
				'_wpnonce'                                       => 'not-a-valid-nonce',
			)
		);

		try {
			Outpost_Encryption_Key_Notice::maybe_handle_dismissal();
			$this->fail( 'A bad nonce must stop the request.' );
		} catch ( \OverflowException $stopped ) {
			$this->assertSame( 'wp_die', $stopped->getMessage() );
		}
		$this->assertSame( '', get_user_meta( $id, self::DISMISSAL_DATABASE_META, true ) );
	}

	public function test_the_oauth_notice_names_the_registered_provider_label(): void {
		$provider = \Outpost_OAuth_Controller::get_provider( 'oura' );
		$this->assertNotNull( $provider, 'Control: Oura is registered.' );

		$html = $this->notice(
			array(
				'outpost_oauth_status'   => 'connected',
				'outpost_oauth_provider' => 'oura',
			)
		);

		$this->assertStringContainsString( esc_html( $provider->label() . ' connected successfully.' ), $html );
	}

	public function test_an_unknown_provider_gets_no_notice(): void {
		$html = $this->notice(
			array(
				'outpost_oauth_status'   => 'connected',
				'outpost_oauth_provider' => 'unregistered_slug',
			)
		);

		$this->assertSame( '', $html );
	}

	public function test_array_shaped_oauth_values_get_no_notice_and_no_warning(): void {
		$this->assertSame( '', $this->notice( array( 'outpost_oauth_status' => array( 'connected' ), 'outpost_oauth_provider' => 'oura' ) ) );
		$this->assertSame( '', $this->notice( array( 'outpost_oauth_status' => 'connected', 'outpost_oauth_provider' => array( 'oura' ) ) ) );
	}

	public function test_the_oauth_page_refuses_a_user_without_manage_options(): void {
		$this->user( 'editor' );
		$this->query( array( 'outpost_oauth_status' => 'connected', 'outpost_oauth_provider' => 'oura' ) );

		ob_start();
		try {
			\Outpost_OAuth_Settings_Page::render();
			$this->fail( 'The page must refuse a user without manage_options.' );
		} catch ( \OverflowException $stopped ) {
			$this->assertSame( 'wp_die', $stopped->getMessage() );
		} finally {
			$html = (string) ob_get_clean();
		}
		$this->assertStringNotContainsString( 'connected successfully', $html );
	}
}
