<?php
/**
 * Outpost's rewrite rules leave the stored table on successful deactivation
 * and uninstall, and come back exactly on activation (T10-S5-02, Phase 5 O-1).
 *
 * WordPress runs the deactivation hook while Outpost is still loaded, so its
 * `init`-time `add_rewrite_rule()` entries are still registered, and
 * `flush_rewrite_rules()` regenerates the table from them. Under WP-CLI's
 * `plugin uninstall --deactivate`, uninstall.php runs in that same process.
 * Each test also regenerates the table again afterward, the way any later
 * code in the same request could.
 *
 * A stopped deactivation or uninstall (a Shortcut password that can't be
 * deleted) keeps the rules: the plugin stays active and /post/ must work.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_IOS_Shortcut_App_Password;
use Outpost_Route_Handler;
use Outpost_Uninstaller;
use PHPUnit\Framework\TestCase;
use WP_Application_Passwords;

final class RewriteLifecycleTest extends TestCase {

	/** @var array<int, int> */
	private array $users = array();

	/** @var array<int, int> User IDs whose application-password writes fail. */
	private array $failing = array();

	/** @var mixed */
	private $saved_rewrite_version;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'outpost_deactivate' ) || ! class_exists( 'Outpost_Route_Handler' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap.' );
		}
		require_once dirname( __DIR__, 2 ) . '/includes/class-outpost-uninstaller.php';
		$this->saved_rewrite_version = get_option( 'outpost_rewrite_version' );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available_for_user', '__return_true' );
		add_filter( 'update_user_metadata', array( $this, 'maybe_fail_write' ), 10, 3 );
		add_filter( 'wp_die_handler', array( $this, 'die_handler' ) );

		Outpost_Route_Handler::register_rewrite_rules();
		flush_rewrite_rules();
		$this->assertSame( $this->expected(), $this->stored(), 'Control: the stored table starts with exactly the Outpost rules.' );
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
		false === $this->saved_rewrite_version ? delete_option( 'outpost_rewrite_version' ) : update_option( 'outpost_rewrite_version', $this->saved_rewrite_version );
		Outpost_Route_Handler::register_rewrite_rules();
		flush_rewrite_rules();
		parent::tearDown();
	}

	/**
	 * Fail application-password writes for the users in $this->failing.
	 *
	 * @param mixed  $check     Short-circuit value.
	 * @param int    $object_id User ID.
	 * @param string $meta_key  Meta key.
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

	/** @return array<string, string> The rules Outpost means to register, as stored. */
	private function expected(): array {
		$out = array();
		foreach ( Outpost_Route_Handler::rules() as $regex => $target ) {
			$out[ $regex ] = 'index.php?' . Outpost_Route_Handler::QUERY_VAR . '=' . $target;
		}
		ksort( $out );
		return $out;
	}

	/** @return array<string, string> Outpost rules in the stored rewrite table. */
	private function stored(): array {
		$out = array();
		foreach ( (array) get_option( 'rewrite_rules', array() ) as $regex => $query ) {
			if ( false !== strpos( (string) $query, Outpost_Route_Handler::QUERY_VAR . '=' ) ) {
				$out[ (string) $regex ] = (string) $query;
			}
		}
		ksort( $out );
		return $out;
	}

	/** Regenerate the stored table the way later code in the same request could. */
	private function regenerate(): void {
		global $wp_rewrite;
		$wp_rewrite->flush_rules( false );
	}

	private function user_with_shortcut_password(): int {
		$id            = (int) wp_insert_user(
			array(
				'user_login' => 's502_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 's502_' . uniqid() . '@example.test',
				'role'       => 'administrator',
			)
		);
		$this->users[] = $id;
		Outpost_IOS_Shortcut_App_Password::create( $id );
		WP_Application_Passwords::create_new_application_password( $id, array( 'name' => 'Mobile app' ) );
		return $id;
	}

	/**
	 * A successful deactivation leaves no Outpost rule in the stored table, even after a later regeneration.
	 */
	public function test_deactivation_leaves_no_outpost_rules(): void {
		outpost_deactivate( false );

		$this->assertSame( array(), $this->stored(), 'Right after deactivation.' );
		$this->regenerate();
		$this->assertSame( array(), $this->stored(), 'After the table is regenerated in the same request.' );
	}

	/**
	 * Activation after a deactivation restores exactly the intended rules.
	 */
	public function test_activation_restores_exactly_the_intended_rules(): void {
		outpost_deactivate( false );
		$this->assertSame( array(), $this->stored() );

		outpost_activate();

		$this->assertSame( $this->expected(), $this->stored() );
	}

	/**
	 * A deactivation stopped by a surviving Shortcut password keeps the rules.
	 */
	public function test_a_stopped_deactivation_keeps_the_rules(): void {
		$this->failing = array( $this->user_with_shortcut_password() );

		try {
			outpost_deactivate( false );
			$this->fail( 'Deactivation must stop while a Shortcut password survives.' );
		} catch ( \OverflowException $stopped ) {
			$this->assertStringStartsWith( 'wp_die: ', $stopped->getMessage() );
		}
		$this->regenerate();
		$this->assertSame( $this->expected(), $this->stored() );
	}

	/**
	 * A successful uninstall leaves no Outpost rule in the stored table, even after a later regeneration.
	 */
	public function test_uninstall_leaves_no_outpost_rules(): void {
		Outpost_Uninstaller::run();

		$this->assertSame( array(), $this->stored(), 'Right after uninstall.' );
		$this->regenerate();
		$this->assertSame( array(), $this->stored(), 'After the table is regenerated in the same request.' );
	}

	/**
	 * An uninstall stopped by a surviving Shortcut password keeps the rules.
	 */
	public function test_a_stopped_uninstall_keeps_the_rules(): void {
		$this->failing = array( $this->user_with_shortcut_password() );

		try {
			Outpost_Uninstaller::run();
			$this->fail( 'Uninstall must stop while a Shortcut password survives.' );
		} catch ( \OverflowException $stopped ) {
			$this->assertStringStartsWith( 'wp_die: ', $stopped->getMessage() );
		}
		$this->regenerate();
		$this->assertSame( $this->expected(), $this->stored() );
	}
}
