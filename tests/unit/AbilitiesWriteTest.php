<?php
/**
 * Unit tests for Outpost's switched write abilities.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Abilities;
use Outpost_Manual_Share_Platform_Registry;
use WP_Error;
use WP_Mock;
use WP_Mock\InvokedFilterValue;

final class AbilitiesWriteTest extends \WP_Mock\Tools\TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, array<string, mixed>> */
	private array $meta_store = array();

	private bool $post_exists = true;

	private bool $current_user_can = true;

	private bool $persist_writes = true;

	private int $post_reads = 0;

	private int $meta_reads = 0;

	private int $post_writes = 0;

	private int $pre_http_requests = 0;

	public function setUp(): void {
		WP_Mock::setUp();
		$ref  = new \ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
		Outpost_Manual_Share_Platform_Registry::reset_for_tests();

		$this->options           = array();
		$this->meta_store        = array();
		$this->post_exists       = true;
		$this->current_user_can  = true;
		$this->persist_writes    = true;
		$this->post_reads        = 0;
		$this->meta_reads        = 0;
		$this->post_writes       = 0;
		$this->pre_http_requests = 0;

		unset(
			$_SERVER['HTTP_AUTHORIZATION'],
			$_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
			$_SERVER['HTTP_X_WP_NONCE'],
			$_REQUEST['_wpnonce'],
			$GLOBALS['wp_rest_auth_cookie']
		);

		WP_Mock::userFunction( '__' )->andReturnUsing( static fn ( string $text ): string => $text );
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			function ( string $option, $default = false ) {
				return $this->options[ $option ] ?? $default;
			}
		);
		WP_Mock::userFunction( 'get_post' )->andReturnUsing(
			function ( int $post_id ) {
				++$this->post_reads;
				if ( ! $this->post_exists ) {
					return null;
				}
				return new \WP_Post(
					array(
						'ID'           => $post_id,
						'post_title'   => 'Sample post',
						'post_content' => '',
					)
				);
			}
		);
		WP_Mock::userFunction( 'current_user_can' )->andReturnUsing(
			function (): bool {
				return $this->current_user_can;
			}
		);
		WP_Mock::userFunction( 'get_post_meta' )->andReturnUsing(
			function ( int $post_id, string $key, bool $single ) {
				unset( $single );
				++$this->meta_reads;
				return $this->meta_store[ $post_id ][ $key ] ?? '';
			}
		);
		WP_Mock::userFunction( 'update_post_meta' )->andReturnUsing(
			function ( int $post_id, string $key, $value ): bool {
				++$this->post_writes;
				if ( ! $this->persist_writes ) {
					return false;
				}
				$this->meta_store[ $post_id ][ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( static fn ( string $url ) => parse_url( $url ) );
		WP_Mock::userFunction( 'wp_http_validate_url' )->andReturnUsing(
			static function ( string $url ) {
				$parts = parse_url( $url );
				return is_array( $parts ) && ! empty( $parts['host'] ) ? $url : false;
			}
		);
		WP_Mock::userFunction( 'wp_strip_all_tags' )->andReturnUsing( static fn ( string $text ): string => strip_tags( $text ) );
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn ( $value ) => $value );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn ( string $value ): string => $value );
		WP_Mock::userFunction( 'get_permalink' )->andReturnUsing( static fn ( int $post_id ): string => 'https://example.com/posts/' . $post_id );
		WP_Mock::userFunction( 'get_attached_media' )->andReturn( array() );
		WP_Mock::userFunction( 'wp_generate_uuid4' )->andReturn( '00000000-0000-4000-8000-000000000042' );
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing( static fn ( string $url ): string => $url );

		WP_Mock::onFilter( 'outpost_manual_share_platforms' )
			->withAnyArgs()
			->reply( Outpost_Manual_Share_Platform_Registry::default_configs() );
		WP_Mock::onFilter( 'pre_http_request' )
			->withAnyArgs()
			->reply(
				new InvokedFilterValue(
					function ( $preempt ) {
						++$this->pre_http_requests;
						return $preempt;
					}
				)
			);
	}

	public function tearDown(): void {
		Outpost_Manual_Share_Platform_Registry::reset_for_tests();
		WP_Mock::tearDown();
		unset(
			$_SERVER['HTTP_AUTHORIZATION'],
			$_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
			$_SERVER['HTTP_X_WP_NONCE'],
			$_REQUEST['_wpnonce'],
			$GLOBALS['wp_rest_auth_cookie']
		);
	}

	/**
	 * @dataProvider permission_provider
	 */
	public function test_write_permission_matrix( string $method, string $option, string $role, bool $allowed ): void {
		unset( $role );
		$this->options[ $option ] = '1';
		$this->current_user_can    = $allowed;

		$result = call_user_func( array( Outpost_Abilities::class, $method ), array( 'post_id' => 42 ) );

		$this->assertSame( $allowed, $result );
	}

	/**
	 * @return array<string, array{string, string, string, bool}>
	 */
	public function permission_provider(): array {
		$cases = array();
		foreach (
			array(
				'manual share'        => array( 'can_manual_share', Outpost_Abilities::OPTION_MANUAL_SHARE ),
				'capture syndication' => array( 'can_capture_syndication', Outpost_Abilities::OPTION_CAPTURE_SYNDICATION ),
			) as $ability => $config
		) {
			$cases[ $ability . ' logged out' ]    = array( $config[0], $config[1], 'logged-out', false );
			$cases[ $ability . ' subscriber' ]    = array( $config[0], $config[1], 'subscriber', false );
			$cases[ $ability . ' contributor' ]   = array( $config[0], $config[1], 'contributor-own-post', true );
			$cases[ $ability . ' administrator' ] = array( $config[0], $config[1], 'administrator', true );
		}
		return $cases;
	}

	/**
	 * @dataProvider switched_ability_provider
	 */
	public function test_switch_off_blocks_permission_and_execution_without_side_effects( string $permission_method, string $execute_method, array $input ): void {
		WP_Mock::userFunction( 'update_option' )->never();
		WP_Mock::userFunction( 'set_transient' )->never();
		WP_Mock::userFunction( 'wp_insert_comment' )->never();
		WP_Mock::userFunction( 'wp_remote_request' )->never();

		$permission = call_user_func( array( Outpost_Abilities::class, $permission_method ), $input );
		$execution  = call_user_func( array( Outpost_Abilities::class, $execute_method ), $input );

		foreach ( array( $permission, $execution ) as $result ) {
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'outpost_ability_disabled', $result->get_error_code() );
			$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
		}
		$this->assertSame( 0, $this->post_reads );
		$this->assertSame( 0, $this->meta_reads );
		$this->assertSame( 0, $this->post_writes );
		$this->assertSame( 0, $this->pre_http_requests );
	}

	/**
	 * @return array<string, array{string, string, array<string, mixed>}>
	 */
	public function switched_ability_provider(): array {
		return array(
			'manual share' => array(
				'can_manual_share',
				'execute_manual_share',
				array( 'post_id' => 42, 'platform_id' => 'instagram-feed' ),
			),
			'capture syndication' => array(
				'can_capture_syndication',
				'execute_capture_syndication',
				array( 'post_id' => 42, 'audit_log_id' => 'audit-1', 'silo_url' => 'https://instagram.com/p/example' ),
			),
		);
	}

	public function test_cookie_session_inside_rest_without_nonce_is_refused_for_both_writes(): void {
		$this->enable_both_abilities();
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$request                        = new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/outpost/manual-share/run' );
		Outpost_Abilities::enter_request( null, array(), $request );

		$this->assertFalse( Outpost_Abilities::can_manual_share( array( 'post_id' => 42 ) ) );
		$this->assertFalse( Outpost_Abilities::can_capture_syndication( array( 'post_id' => 42 ) ) );
		$this->assertSame( 0, $this->post_reads, 'The credential gate runs before the post lookup.' );

		Outpost_Abilities::leave_request( null, array(), $request );
	}

	public function test_read_scoped_token_inside_rest_is_refused_for_both_writes(): void {
		$this->enable_both_abilities();
		$request                         = new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/outpost/manual-share/run' );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'read' ) );
		Outpost_Abilities::enter_request( null, array(), $request );

		$this->assertFalse( Outpost_Abilities::can_manual_share( array( 'post_id' => 42 ) ) );
		$this->assertFalse( Outpost_Abilities::can_capture_syndication( array( 'post_id' => 42 ) ) );
		$this->assertSame( 0, $this->post_reads, 'The scope gate runs before the post lookup.' );

		Outpost_Abilities::leave_request( null, array(), $request );
	}

	public function test_php_caller_without_request_uses_capability_check_alone(): void {
		$this->enable_both_abilities();

		$this->assertTrue( Outpost_Abilities::can_manual_share( array( 'post_id' => 42 ) ) );
		$this->assertTrue( Outpost_Abilities::can_capture_syndication( array( 'post_id' => 42 ) ) );
		$this->assertSame( 2, $this->post_reads );
	}

	public function test_enabled_permission_rejects_a_missing_post_before_capability_check(): void {
		$this->options[ Outpost_Abilities::OPTION_MANUAL_SHARE ] = '1';
		$this->post_exists = false;

		$this->assertFalse( Outpost_Abilities::can_manual_share( array( 'post_id' => 42 ) ) );
		$this->assertSame( 1, $this->post_reads );
	}

	public function test_manual_share_dry_run_returns_before_any_builder_writes(): void {
		$this->options[ Outpost_Abilities::OPTION_MANUAL_SHARE ] = '1';

		$result = Outpost_Abilities::execute_manual_share(
			array(
				'post_id'     => 42,
				'platform_id' => 'instagram-feed',
				'device'      => 'android',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['dry_run'] );
		$this->assertFalse( $result['written'] );
		$this->assertNull( $result['payload'] );
		$this->assertSame( 0, $this->meta_reads );
		$this->assertSame( 0, $this->post_writes );
		$this->assertSame( 0, $this->pre_http_requests );
	}

	/**
	 * @dataProvider truthy_dry_run_values
	 *
	 * @param mixed $dry_run Value core's boolean validation accepts.
	 */
	public function test_truthy_dry_run_values_never_write( $dry_run ): void {
		$this->options[ Outpost_Abilities::OPTION_MANUAL_SHARE ] = '1';
		$this->enable_capture_with_audit_entry( 'instagram-feed' );
		$writes_before = $this->post_writes;

		$share = Outpost_Abilities::execute_manual_share(
			array(
				'post_id'     => 42,
				'platform_id' => 'instagram-feed',
				'device'      => 'android',
				'dry_run'     => $dry_run,
			)
		);
		$capture = Outpost_Abilities::execute_capture_syndication(
			array(
				'post_id'      => 42,
				'audit_log_id' => 'audit-1',
				'silo_url'     => 'https://instagram.com/p/example',
				'dry_run'      => $dry_run,
			)
		);

		$this->assertTrue( $share['dry_run'] );
		$this->assertFalse( $share['written'] );
		$this->assertSame( 'dry_run', $capture['status'] );
		$this->assertFalse( $capture['written'] );
		$this->assertSame( $writes_before, $this->post_writes );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function truthy_dry_run_values(): array {
		return array(
			'bool true'      => array( true ),
			'string true'    => array( 'true' ),
			'string TRUE'    => array( 'TRUE' ),
			'string one'     => array( '1' ),
			'int one'        => array( 1 ),
		);
	}

	public function test_string_false_dry_run_writes(): void {
		$this->enable_capture_with_audit_entry( 'instagram-feed' );

		$result = Outpost_Abilities::execute_capture_syndication(
			array(
				'post_id'      => 42,
				'audit_log_id' => 'audit-1',
				'silo_url'     => 'https://instagram.com/p/example',
				'dry_run'      => 'false',
			)
		);

		$this->assertSame( 'recorded', $result['status'] );
		$this->assertTrue( $result['written'] );
	}

	public function test_capture_trims_the_url_so_padding_cannot_skip_the_mismatch_warning(): void {
		$this->enable_capture_with_audit_entry( 'instagram-feed' );

		$result = Outpost_Abilities::execute_capture_syndication(
			array(
				'post_id'      => 42,
				'audit_log_id' => ' audit-1 ',
				'silo_url'     => '  https://linkedin.com/posts/example',
				'dry_run'      => false,
			)
		);

		$this->assertSame( 'mismatch_warning', $result['status'] );
		$this->assertSame( 'https://linkedin.com/posts/example', $result['silo_url'] );
		$this->assertSame( 'audit-1', $result['audit_log_id'] );
		$this->assertFalse( $result['written'] );
	}

	public function test_capture_accepts_a_string_confirm_mismatch_and_writes(): void {
		$this->enable_capture_with_audit_entry( 'instagram-feed' );

		$result = Outpost_Abilities::execute_capture_syndication(
			array(
				'post_id'          => 42,
				'audit_log_id'     => 'audit-1',
				'silo_url'         => 'https://linkedin.com/posts/example',
				'confirm_mismatch' => 'true',
				'dry_run'          => 0,
			)
		);

		$this->assertSame( 'recorded', $result['status'] );
		$this->assertTrue( $result['written'] );
		$this->assertSame( 2, $this->post_writes );
	}

	public function test_capture_rejects_an_empty_audit_log_id_with_400(): void {
		$this->options[ Outpost_Abilities::OPTION_CAPTURE_SYNDICATION ] = '1';

		$result = Outpost_Abilities::execute_capture_syndication(
			array(
				'post_id'      => 42,
				'audit_log_id' => '  ',
				'silo_url'     => 'https://instagram.com/p/example',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'outpost_invalid_audit_log_id', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
		$this->assertSame( 0, $this->meta_reads );
	}

	public function test_manual_share_rejects_an_unknown_platform(): void {
		$this->options[ Outpost_Abilities::OPTION_MANUAL_SHARE ] = '1';

		$result = Outpost_Abilities::execute_manual_share(
			array( 'post_id' => 42, 'platform_id' => 'unknown', 'dry_run' => false )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'outpost_unknown_platform_id', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
		$this->assertSame( 0, $this->post_writes );
	}

	public function test_manual_share_android_reports_a_persisted_audit_entry(): void {
		$this->options[ Outpost_Abilities::OPTION_MANUAL_SHARE ] = '1';

		$result = Outpost_Abilities::execute_manual_share(
			array(
				'post_id'     => 42,
				'platform_id' => 'instagram-feed',
				'device'      => 'android',
				'dry_run'     => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['written'] );
		$this->assertSame( 'instagram-feed', $result['payload']['platform'] );
		$this->assertSame( 1, $this->post_writes );
		$this->assertCount( 1, $this->meta_store[42]['outpost_manual_share_log'] );
	}

	public function test_manual_share_reports_false_when_the_audit_entry_does_not_persist(): void {
		$this->options[ Outpost_Abilities::OPTION_MANUAL_SHARE ] = '1';
		$this->persist_writes = false;

		$result = Outpost_Abilities::execute_manual_share(
			array(
				'post_id'     => 42,
				'platform_id' => 'instagram-feed',
				'device'      => 'android',
				'dry_run'     => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['written'] );
		$this->assertSame( 1, $this->post_writes );
		$this->assertArrayNotHasKey( 42, $this->meta_store );
	}

	public function test_manual_share_ios_passes_the_pwa_mode_hint_and_reports_the_write(): void {
		$this->options[ Outpost_Abilities::OPTION_MANUAL_SHARE ] = '1';

		$result = Outpost_Abilities::execute_manual_share(
			array(
				'post_id'     => 42,
				'platform_id' => 'instagram-feed',
				'device'      => 'ios',
				'in_pwa_mode' => true,
				'dry_run'     => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['written'] );
		$this->assertTrue( $result['payload']['in_pwa_mode'] );
		$this->assertSame( 1, $this->post_writes );
	}

	public function test_manual_share_desktop_builds_the_stub_without_writing(): void {
		$this->options[ Outpost_Abilities::OPTION_MANUAL_SHARE ] = '1';

		$result = Outpost_Abilities::execute_manual_share(
			array(
				'post_id'     => 42,
				'platform_id' => 'instagram-feed',
				'device'      => 'desktop',
				'dry_run'     => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['written'] );
		$this->assertSame( 'stub', $result['payload']['status'] );
		$this->assertSame( 0, $this->post_writes );
	}

	public function test_capture_rejects_an_invalid_url_before_reading_the_audit_log(): void {
		$this->options[ Outpost_Abilities::OPTION_CAPTURE_SYNDICATION ] = '1';

		$result = Outpost_Abilities::execute_capture_syndication(
			array(
				'post_id'      => 42,
				'audit_log_id' => 'audit-1',
				'silo_url'     => 'javascript://example.com/x',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'outpost_invalid_scheme', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
		$this->assertSame( 0, $this->meta_reads );
		$this->assertSame( 0, $this->post_writes );
	}

	public function test_capture_returns_404_for_a_missing_audit_entry(): void {
		$this->options[ Outpost_Abilities::OPTION_CAPTURE_SYNDICATION ] = '1';

		$result = Outpost_Abilities::execute_capture_syndication(
			array(
				'post_id'      => 42,
				'audit_log_id' => 'missing',
				'silo_url'     => 'https://instagram.com/p/example',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'outpost_audit_log_entry_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
		$this->assertSame( 0, $this->post_writes );
	}

	public function test_capture_mismatch_warning_precedes_dry_run_without_writing(): void {
		$this->enable_capture_with_audit_entry( 'instagram-feed' );

		$result = Outpost_Abilities::execute_capture_syndication(
			array(
				'post_id'          => 42,
				'audit_log_id'      => 'audit-1',
				'silo_url'          => 'https://linkedin.com/posts/example',
				'confirm_mismatch' => false,
				'dry_run'          => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'mismatch_warning', $result['status'] );
		$this->assertFalse( $result['written'] );
		$this->assertNull( $result['syndication_links'] );
		$this->assertSame( 0, $this->post_writes );
	}

	public function test_capture_dry_run_reads_the_entry_without_writing(): void {
		$this->enable_capture_with_audit_entry( 'instagram-feed' );

		$result = Outpost_Abilities::execute_capture_syndication(
			array(
				'post_id'      => 42,
				'audit_log_id' => 'audit-1',
				'silo_url'     => 'https://instagram.com/p/example',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'dry_run', $result['status'] );
		$this->assertFalse( $result['written'] );
		$this->assertNull( $result['syndication_links'] );
		$this->assertSame( 0, $this->post_writes );
		$this->assertSame( 0, $this->pre_http_requests );
	}

	public function test_capture_records_the_link_and_updates_the_audit_entry(): void {
		$this->enable_capture_with_audit_entry( 'instagram-feed' );

		$result = Outpost_Abilities::execute_capture_syndication(
			array(
				'post_id'      => 42,
				'audit_log_id' => 'audit-1',
				'silo_url'     => 'https://instagram.com/p/example',
				'dry_run'      => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'recorded', $result['status'] );
		$this->assertTrue( $result['written'] );
		$this->assertCount( 1, $result['syndication_links'] );
		$this->assertSame( 2, $this->post_writes );
		$this->assertSame( 'https://instagram.com/p/example', $this->meta_store[42]['outpost_manual_share_log'][0]['silo_url'] );
		$this->assertSame( 'fired', $this->meta_store[42]['outpost_manual_share_log'][0]['outcome'] );
	}

	private function enable_both_abilities(): void {
		$this->options[ Outpost_Abilities::OPTION_MANUAL_SHARE ]        = '1';
		$this->options[ Outpost_Abilities::OPTION_CAPTURE_SYNDICATION ] = '1';
	}

	private function enable_capture_with_audit_entry( string $platform_id ): void {
		$this->options[ Outpost_Abilities::OPTION_CAPTURE_SYNDICATION ] = '1';
		$this->meta_store[42]['outpost_manual_share_log']               = array(
			array(
				'id'           => 'audit-1',
				'version'      => 1,
				'platform_id'  => $platform_id,
				'fired_at'     => '2026-11-13T12:00:00+00:00',
				'strategy'     => 'navigator_share',
				'outcome'      => 'unknown',
				'completed_at' => null,
				'silo_url'     => null,
			),
		);
	}
}
