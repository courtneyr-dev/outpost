<?php
/**
 * Stored secrets never render into settings forms (T10-S3-11, F-13).
 *
 * Covers every registered password / API-key field, found from the
 * registry rather than listed by hand. The tests read the actual tab HTML
 * and decrypt the stored option to check what was kept.
 *
 * - Initial, post-save, and redisplay renders contain no stored or newly
 *   submitted secret; each secret input has no value attribute.
 * - A blank submission keeps the stored secret; a non-empty one replaces
 *   it; the labeled "Remove the saved …" control deletes it; saving another
 *   setting leaves it alone.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Encryption;
use Outpost_Settings_Handler;
use Outpost_Settings_Page;
use Outpost_Settings_Registry;
use PHPUnit\Framework\TestCase;

final class SettingsSecretsTest extends TestCase {

	private const TAB = 'api_keys';

	private int $admin_id = 0;

	/** @var array<string, array<string, mixed>> */
	private array $secret_fields = array();

	/** @var array<string, mixed> */
	private array $saved_post = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Settings_Handler' ) || ! class_exists( 'Outpost_Encryption' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap.' );
		}
		$this->saved_post = $_POST;
		$this->admin_id   = (int) wp_insert_user(
			array(
				'user_login' => 's311_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 's311_' . uniqid() . '@example.test',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $this->admin_id );
		\Outpost_Encryption_Key_Resolver::resolve();
		delete_option( Outpost_Settings_Registry::option_name_for_tab( self::TAB ) );

		foreach ( Outpost_Settings_Registry::get_fields( self::TAB ) as $id => $config ) {
			if ( 'password' === ( $config['type'] ?? '' ) ) {
				$this->secret_fields[ $id ] = $config;
			}
		}
	}

	protected function tearDown(): void {
		$_POST = $this->saved_post;
		delete_option( Outpost_Settings_Registry::option_name_for_tab( self::TAB ) );
		if ( $this->admin_id > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->admin_id );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private static function secret_for( string $field_id, string $round = 'one' ): string {
		return 'SYNTHETIC-' . strtoupper( $field_id ) . '-' . strtoupper( $round );
	}

	/** Every secret field filled with round `$round`. @return array<string, string> */
	private function all_secrets( string $round = 'one' ): array {
		$out = array();
		foreach ( array_keys( $this->secret_fields ) as $id ) {
			$out[ $id ] = self::secret_for( $id, $round );
		}
		return $out;
	}

	private function render(): string {
		ob_start();
		Outpost_Settings_Page::render_tab_form( self::TAB, Outpost_Settings_Registry::get_fields( self::TAB ) );
		return (string) ob_get_clean();
	}

	/** Decrypt what's stored for a field, or null when nothing is. */
	private function stored( string $field_id ): ?string {
		$option = get_option( Outpost_Settings_Registry::option_name_for_tab( self::TAB ), array() );
		$value  = is_array( $option ) ? ( $option[ $field_id ] ?? null ) : null;
		if ( ! is_array( $value ) || empty( $value['encrypted'] ) ) {
			return null;
		}
		return Outpost_Encryption::decrypt( (string) $value['encrypted'] );
	}

	private function assert_no_secret_in( string $html, string $why ): void {
		foreach ( array_keys( $this->secret_fields ) as $id ) {
			foreach ( array( 'one', 'two' ) as $round ) {
				$this->assertStringNotContainsString( self::secret_for( $id, $round ), $html, "$why: $id ($round) must not render." );
			}
			$name = preg_quote( 'outpost_settings[' . $id . ']', '/' );
			$this->assertSame( 1, preg_match( '/<input[^>]*name="' . $name . '"[^>]*>/', $html, $m ), "$why: $id input renders." );
			$this->assertStringNotContainsString( 'value=', $m[0], "$why: $id input carries no value attribute." );
			$this->assertStringContainsString( 'type="password"', $m[0] );
		}
	}

	public function test_every_registered_secret_field_is_covered(): void {
		$this->assertSame(
			array( 'beehiiv_api_key', 'buttondown_api_key', 'kit_api_secret', 'write_as_api_token' ),
			array_values( array_intersect( array( 'beehiiv_api_key', 'buttondown_api_key', 'kit_api_secret', 'write_as_api_token' ), array_keys( $this->secret_fields ) ) )
		);
		foreach ( $this->secret_fields as $config ) {
			$this->assertTrue( ! empty( $config['sensitive'] ), 'Every password field is stored encrypted.' );
		}
	}

	public function test_initial_and_saved_renders_contain_no_secret(): void {
		$this->assert_no_secret_in( $this->render(), 'empty initial render' );

		Outpost_Settings_Handler::save_submission( self::TAB, $this->all_secrets(), array() );

		$html = $this->render();
		$this->assert_no_secret_in( $html, 'render after saving' );
		foreach ( $this->secret_fields as $id => $config ) {
			$this->assertStringContainsString( 'Remove the saved ' . $config['label'], $html, "$id offers an explicit delete control." );
		}
	}

	public function test_a_redisplay_with_a_submitted_secret_does_not_echo_it(): void {
		Outpost_Settings_Handler::save_submission( self::TAB, $this->all_secrets(), array() );
		$_POST['outpost_settings'] = $this->all_secrets( 'two' );

		$this->assert_no_secret_in( $this->render(), 'redisplay with a pending submission' );
	}

	public function test_a_blank_submission_keeps_every_stored_secret(): void {
		Outpost_Settings_Handler::save_submission( self::TAB, $this->all_secrets(), array() );

		Outpost_Settings_Handler::save_submission( self::TAB, array_fill_keys( array_keys( $this->secret_fields ), '' ), array() );

		foreach ( array_keys( $this->secret_fields ) as $id ) {
			$this->assertSame( self::secret_for( $id ), $this->stored( $id ), "$id kept on a blank save." );
		}
	}

	public function test_saving_an_unrelated_setting_keeps_every_stored_secret(): void {
		Outpost_Settings_Handler::save_submission( self::TAB, $this->all_secrets(), array() );
		$other = array();
		foreach ( Outpost_Settings_Registry::get_fields( self::TAB ) as $id => $config ) {
			if ( 'checkbox' === ( $config['type'] ?? '' ) ) {
				$other[ $id ] = '1';
			}
		}
		$this->assertNotEmpty( $other, 'The tab has a non-secret setting to change.' );

		Outpost_Settings_Handler::save_submission( self::TAB, $other, array() );

		foreach ( array_keys( $this->secret_fields ) as $id ) {
			$this->assertSame( self::secret_for( $id ), $this->stored( $id ), "$id survives an unrelated save." );
		}
	}

	public function test_a_non_empty_submission_replaces_only_that_secret(): void {
		Outpost_Settings_Handler::save_submission( self::TAB, $this->all_secrets(), array() );
		$ids    = array_keys( $this->secret_fields );
		$target = $ids[0];

		Outpost_Settings_Handler::save_submission( self::TAB, array( $target => self::secret_for( $target, 'two' ) ), array() );

		$this->assertSame( self::secret_for( $target, 'two' ), $this->stored( $target ) );
		foreach ( array_slice( $ids, 1 ) as $id ) {
			$this->assertSame( self::secret_for( $id ), $this->stored( $id ) );
		}
	}

	public function test_the_remove_control_deletes_only_that_secret(): void {
		Outpost_Settings_Handler::save_submission( self::TAB, $this->all_secrets(), array() );
		$ids    = array_keys( $this->secret_fields );
		$target = $ids[1];

		Outpost_Settings_Handler::save_submission( self::TAB, array(), array( $target ) );

		$this->assertNull( $this->stored( $target ) );
		foreach ( array_diff( $ids, array( $target ) ) as $id ) {
			$this->assertSame( self::secret_for( $id ), $this->stored( $id ) );
		}
		$this->assertStringNotContainsString( 'Remove the saved ' . $this->secret_fields[ $target ]['label'], $this->render(), 'Nothing left to remove.' );
	}
}
