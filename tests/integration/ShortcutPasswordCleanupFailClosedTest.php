<?php
/**
 * Deactivation and uninstall stop when a Shortcut password survives cleanup
 * (T10-S4-27, Phase 4 S4-09).
 *
 * The rule that limits Outpost's application password to the Shortcut route
 * is Outpost code. If deleting that password fails and deactivation or
 * uninstall still goes ahead, the surviving password works on every route
 * core accepts application passwords for. Cleanup now enumerates again after
 * deleting and reports whether any Outpost password is left; deactivation
 * and uninstall stop with wp_die() when one is. WordPress persists
 * `active_plugins` after the deactivation hooks and deletes plugin files
 * after uninstall.php, so stopping there keeps Outpost, and the rule, loaded.
 *
 * A failed deletion is injected with core's `update_user_metadata`
 * short-circuit for `_application_passwords`, so the delete call returns
 * false and nothing is written, the way a failed database write behaves.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_IOS_Shortcut_App_Password;
use PHPUnit\Framework\TestCase;
use WP_Application_Passwords;

final class ShortcutPasswordCleanupFailClosedTest extends TestCase {

	/** @var array<int, int> */
	private array $users = array();

	/** @var array<int, int> User ids whose application-password writes fail. */
	private array $failing = array();

	/** @var mixed Stored active_plugins before a test changed it. */
	private $saved_active = null;

	/** @var mixed The bootstrap's option_active_plugins filters, while lifted. */
	private $saved_active_filters = null;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WP_Application_Passwords' ) || ! function_exists( 'outpost_deactivate' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap.' );
		}
		require_once dirname( __DIR__, 2 ) . '/includes/class-outpost-uninstaller.php';
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available_for_user', '__return_true' );
		add_filter( 'update_user_metadata', array( $this, 'maybe_fail_write' ), 10, 3 );
		add_filter( 'wp_die_handler', array( $this, 'die_handler' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'wp_is_application_passwords_available_for_user', '__return_true' );
		remove_filter( 'update_user_metadata', array( $this, 'maybe_fail_write' ), 10 );
		remove_filter( 'wp_die_handler', array( $this, 'die_handler' ) );
		$this->failing = array();
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->users as $id ) {
			wp_delete_user( $id );
		}
		delete_option( 'outpost_settings' );
		if ( null !== $this->saved_active ) {
			update_option( 'active_plugins', $this->saved_active );
		}
		if ( null !== $this->saved_active_filters ) {
			$GLOBALS['wp_filter']['option_active_plugins'] = $this->saved_active_filters;
		}
		parent::tearDown();
	}

	/**
	 * @param mixed $check     Short-circuit value.
	 * @param int   $object_id User id.
	 * @param string $meta_key Meta key.
	 * @return mixed
	 */
	public function maybe_fail_write( $check, $object_id, $meta_key ) {
		if ( '_application_passwords' === $meta_key && in_array( (int) $object_id, $this->failing, true ) ) {
			return false;
		}
		return $check;
	}

	/** @return callable */
	public function die_handler(): callable {
		return static function ( $message ): void {
			throw new \OverflowException( 'wp_die: ' . ( is_string( $message ) ? $message : 'stopped' ) );
		};
	}

	private function user_with_passwords(): int {
		$id            = (int) wp_insert_user(
			array(
				'user_login' => 's427_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 's427_' . uniqid() . '@example.test',
				'role'       => 'administrator',
			)
		);
		$this->users[] = $id;
		Outpost_IOS_Shortcut_App_Password::create( $id );
		WP_Application_Passwords::create_new_application_password( $id, array( 'name' => 'Mobile app' ) );
		return $id;
	}

	/**
	 * When Outpost can't be turned back on (here its required plugins are
	 * inactive), uninstall still stops before deleting any data and says the
	 * password isn't limited and must be revoked by hand.
	 */
	public function test_uninstall_says_so_when_it_cannot_reactivate(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$basename = basename( dirname( __DIR__, 2 ) ) . '/outpost.php';
		$this->lift_active_plugins_filter();
		$this->saved_active = get_option( 'active_plugins' );
		update_option( 'active_plugins', array() );
		$fail          = $this->user_with_passwords();
		$this->failing = array( $fail );
		update_option( 'outpost_settings', array( 'kept' => '1' ) );

		try {
			\Outpost_Uninstaller::run( $basename );
			$this->fail( 'Uninstall must stop while a Shortcut password survives.' );
		} catch ( \OverflowException $stopped ) {
			$this->assertStringContainsString( 'could not turn itself back on', $stopped->getMessage() );
			$this->assertStringContainsString( 'Revoke it', $stopped->getMessage() );
		}
		$this->assertNotContains( $basename, $this->stored_active_plugins() );
		$this->assertSame( array( 'kept' => '1' ), get_option( 'outpost_settings' ), 'No plugin data is deleted.' );
	}

	/**
	 * Remove the bootstrap's option_active_plugins filters until tearDown.
	 */
	private function lift_active_plugins_filter(): void {
		$this->saved_active_filters = $GLOBALS['wp_filter']['option_active_plugins'] ?? null;
		unset( $GLOBALS['wp_filter']['option_active_plugins'] );
	}

	/**
	 * The stored active_plugins value. The integration bootstrap filters
	 * option_active_plugins to always include Outpost, so the raw row is
	 * what the next request would read.
	 *
	 * @return array<int, string>
	 */
	private function stored_active_plugins(): array {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'active_plugins' ) );
		return array_values( (array) maybe_unserialize( (string) $raw ) );
	}

	/** @return array<int, string> */
	private function names( int $user_id ): array {
		return array_column( WP_Application_Passwords::get_user_application_passwords( $user_id ), 'name' );
	}

	/**
	 * Cleanup reports success only when no outpost password is left.
	 */
	public function test_cleanup_reports_success_only_when_no_outpost_password_is_left(): void {
		$ok   = $this->user_with_passwords();
		$fail = $this->user_with_passwords();
		$this->failing = array( $fail );

		$this->assertFalse( \Outpost_Uninstaller::delete_shortcut_application_passwords(), 'One survivor means failure.' );
		$this->assertSame( array( 'Mobile app' ), $this->names( $ok ), 'Mixed: the deletable one is gone, the unrelated one stays.' );
		$this->assertNotNull( Outpost_IOS_Shortcut_App_Password::find( $fail ), 'Mixed: the failed one survives.' );

		$this->failing = array();
		$this->assertTrue( \Outpost_Uninstaller::delete_shortcut_application_passwords(), 'A retry that deletes everything reports success.' );
		$this->assertSame( array( 'Mobile app' ), $this->names( $fail ) );
	}

	/**
	 * @return array<string, array{0: bool}>
	 */
	public function deactivation_scopes(): array {
		return array(
			'single-site deactivation' => array( false ),
			'network deactivation'     => array( true ),
		);
	}

	/**
	 * @dataProvider deactivation_scopes
	 */
	public function test_deactivation_stops_when_a_shortcut_password_survives( bool $network_wide ): void {
		$fail          = $this->user_with_passwords();
		$this->failing = array( $fail );

		try {
			outpost_deactivate( $network_wide );
			$this->fail( 'Deactivation must stop while a Shortcut password survives.' );
		} catch ( \OverflowException $stopped ) {
			$this->assertStringStartsWith( 'wp_die: ', $stopped->getMessage() );
		}
		$this->assertNotNull( Outpost_IOS_Shortcut_App_Password::find( $fail ), 'The password survives, still scoped by the loaded plugin.' );
		$this->assertContains( 'Mobile app', $this->names( $fail ) );
	}

	/**
	 * @dataProvider deactivation_scopes
	 */
	public function test_deactivation_completes_when_cleanup_succeeds( bool $network_wide ): void {
		$id = $this->user_with_passwords();

		outpost_deactivate( $network_wide );

		$this->assertSame( array( 'Mobile app' ), $this->names( $id ) );
	}

	/**
	 * WordPress runs uninstall after deactivation, without loading Outpost,
	 * so keeping the files can't keep the password scoped (final delta
	 * review D-01). A failed cleanup reactivates Outpost, so the next request
	 * loads the route rule again, and stops before deleting any plugin data.
	 */
	public function test_uninstall_reactivates_and_stops_when_a_password_survives(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$basename = basename( dirname( __DIR__, 2 ) ) . '/outpost.php';
		// The bootstrap filters active_plugins to always include Outpost,
		// which would make reactivation a no-op here; lift it for this test.
		$this->lift_active_plugins_filter();
		// Outpost's required plugins are active on a real site.
		$this->saved_active = get_option( 'active_plugins' );
		update_option( 'active_plugins', array( 'indieauth/indieauth.php', 'micropub/micropub.php' ) );
		deactivate_plugins( $basename, true );
		$this->assertNotContains( $basename, $this->stored_active_plugins(), 'Control: uninstall starts with Outpost inactive.' );
		$fail          = $this->user_with_passwords();
		$this->failing = array( $fail );
		update_option( 'outpost_settings', array( 'kept' => '1' ) );

		try {
			\Outpost_Uninstaller::run( $basename );
			$this->fail( 'Uninstall must stop while a Shortcut password survives.' );
		} catch ( \OverflowException $stopped ) {
			$this->assertStringContainsString( 'turned itself back on', $stopped->getMessage() );
		} finally {
			$active = in_array( $basename, $this->stored_active_plugins(), true );
			deactivate_plugins( $basename, true );
		}
		$this->assertTrue( $active, 'Outpost is saved as active again, so its route rule loads on the next request.' );
		$this->assertSame( array( 'kept' => '1' ), get_option( 'outpost_settings' ), 'No plugin data is deleted.' );
		$this->assertNotNull( Outpost_IOS_Shortcut_App_Password::find( $fail ) );

		$this->failing = array();
		\Outpost_Uninstaller::run( $basename );
		$this->assertFalse( get_option( 'outpost_settings' ), 'A clean retry uninstalls.' );
		$this->assertSame( array( 'Mobile app' ), $this->names( $fail ) );
	}
}
