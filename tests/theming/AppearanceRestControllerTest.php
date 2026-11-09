<?php
/**
 * Unit tests for Outpost_Appearance_REST_Controller: bearer transport,
 * scope gate, style-source persistence, no-write reads, and private
 * (uncacheable) responses.
 *
 * @package Outpost\Tests\Theming
 */

declare(strict_types=1);

namespace Outpost\Tests\Theming;

use Outpost_Appearance_REST_Controller;
use Outpost_Theme_Json_Reader;
use Outpost_Token_Resolver;
use WP_Mock;

final class AppearanceRestControllerTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $user_meta = array();

	/** @var array<int, array{0:int,1:string}> */
	private array $meta_writes = array();

	private int $current_user = 7;

	public function setUp(): void {
		WP_Mock::setUp();
		$this->user_meta    = array();
		$this->meta_writes  = array();
		$this->current_user = 7;

		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => is_string( $v ) ? trim( $v ) : '' );
		WP_Mock::userFunction( 'get_current_user_id' )->andReturnUsing( fn (): int => $this->current_user );
		WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			fn ( int $user_id, string $key, bool $single = false ) => $this->user_meta[ $user_id ][ $key ] ?? ''
		);
		WP_Mock::userFunction( 'update_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key, $value ): bool {
				$this->meta_writes[]                 = array( $user_id, $key );
				$this->user_meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'get_option' )->andReturn( false );
		WP_Mock::userFunction( 'get_userdata' )->andReturn( false );
		WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		WP_Mock::userFunction( 'set_transient' )->andReturn( true );
		WP_Mock::userFunction( 'delete_transient' )->andReturn( true );
		if ( ! function_exists( 'is_wp_error' ) ) {
			WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( static fn( $thing ) => $thing instanceof \WP_Error );
		}
		Outpost_Theme_Json_Reader::set_reader_for_tests( static fn () => null );
		Outpost_Token_Resolver::set_theme_version_for_tests( static fn (): string => 'tt5:1.0' );
	}

	public function tearDown(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		Outpost_Theme_Json_Reader::set_reader_for_tests( null );
		Outpost_Token_Resolver::set_theme_version_for_tests( null );
		WP_Mock::tearDown();
	}

	private function post( array $body ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/outpost/v1/appearance/tokens' );
		$request->set_body( (string) wp_json_encode( $body ) );
		$request->set_header( 'content-type', 'application/json' );
		return $request;
	}

	public function test_anonymous_request_is_refused_and_writes_nothing(): void {
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
		$result = Outpost_Appearance_REST_Controller::permission_check( $this->post( array( 'style_source' => 'site' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->meta_writes, 'A refused request must not touch user meta.' );
	}

	public function test_bearer_token_without_a_writing_scope_is_refused(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'media' ) );

		$result = Outpost_Appearance_REST_Controller::permission_check( $this->post( array() ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'outpost_appearance_scope', $result->get_error_code() );
	}

	public function test_bearer_token_with_create_scope_passes(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
		WP_Mock::userFunction( 'current_user_can' )->with( 'edit_posts' )->andReturn( true );
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( array( 'create', 'media' ) );

		$this->assertTrue( Outpost_Appearance_REST_Controller::permission_check( $this->post( array() ) ) );
	}

	public function test_token_only_post_reads_state_without_writing(): void {
		$response = Outpost_Appearance_REST_Controller::handle_post( $this->post( array( 'access_token' => 'x' ) ) ); // outpost-lint:fixture-credential
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( array(), $this->meta_writes, 'A read must not write user meta.' );
		$data = $response->get_data();
		$this->assertSame( 'outpost', $data['style_source'] );
		$this->assertFalse( $data['style_source_stored'] );
		$this->assertSame( 'system', $data['mode_preference'] );
		$this->assertSame( 'home', $data['start_screen'], 'A user without a stored start screen opens on Home.' );
		$this->assertFalse( $data['start_screen_stored'] );
		$this->assertSame( '', $data['css'], 'Outpost Default without overrides needs no extra CSS.' );
	}

	public function test_start_screen_save_persists_and_is_returned(): void {
		$response = Outpost_Appearance_REST_Controller::handle_post( $this->post( array( 'start_screen' => 'reply' ) ) );
		$data     = $response->get_data();
		$this->assertSame( 'reply', $this->user_meta[7]['outpost_start_screen'] );
		$this->assertSame( 'reply', $data['start_screen'] );
		$this->assertTrue( $data['start_screen_stored'] );
	}

	public function test_invalid_start_screen_is_rejected_without_writes(): void {
		$result = Outpost_Appearance_REST_Controller::handle_post( $this->post( array( 'start_screen' => 'settings' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'outpost_appearance_invalid_start_screen', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->meta_writes );
	}

	public function test_an_invalid_start_screen_does_not_half_apply_other_changes(): void {
		$result = Outpost_Appearance_REST_Controller::handle_post(
			$this->post(
				array(
					'mode_preference' => 'night',
					'style_source'    => 'site',
					'start_screen'    => 'nowhere',
				)
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $this->meta_writes, 'A rejected request must not store the valid fields beside it.' );
	}

	public function test_start_screen_is_independent_of_appearance(): void {
		$this->user_meta[7]['outpost_appearance_mode']   = 'night';
		$this->user_meta[7]['outpost_appearance_source'] = 'site';
		Outpost_Appearance_REST_Controller::handle_post( $this->post( array( 'start_screen' => 'photo' ) ) );
		$this->assertSame( 'night', $this->user_meta[7]['outpost_appearance_mode'] );
		$this->assertSame( 'site', $this->user_meta[7]['outpost_appearance_source'] );
		$this->assertSame( array( array( 7, 'outpost_start_screen' ) ), $this->meta_writes );
	}

	public function test_style_source_save_persists_and_returns_site_css(): void {
		$response = Outpost_Appearance_REST_Controller::handle_post( $this->post( array( 'style_source' => 'site' ) ) );
		$data     = $response->get_data();
		$this->assertSame( 'site', $this->user_meta[7]['outpost_appearance_source'] );
		$this->assertSame( 'site', $data['style_source'] );
		$this->assertTrue( $data['style_source_stored'] );
		$this->assertStringContainsString( '.outpost-source-site.outpost-mode-night', $data['css'] );
		$this->assertStringContainsString( '@media (prefers-color-scheme: dark)', $data['css'] );
	}

	public function test_invalid_style_source_is_rejected_without_writes(): void {
		$result = Outpost_Appearance_REST_Controller::handle_post( $this->post( array( 'style_source' => 'forest' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->meta_writes );
	}

	public function test_mode_and_source_are_independent(): void {
		Outpost_Appearance_REST_Controller::handle_post( $this->post( array( 'mode_preference' => 'night' ) ) );
		$this->assertSame( 'night', $this->user_meta[7]['outpost_appearance_mode'] );
		$this->assertArrayNotHasKey( 'outpost_appearance_source', $this->user_meta[7] );

		Outpost_Appearance_REST_Controller::handle_post( $this->post( array( 'style_source' => 'site' ) ) );
		$this->assertSame( 'night', $this->user_meta[7]['outpost_appearance_mode'], 'Changing the source must not change the mode.' );
	}

	public function test_overrides_survive_a_source_change(): void {
		$this->user_meta[7]['outpost_appearance_overrides'] = array(
			'day' => array( 'colors' => array( 'accent' => '#0055aa' ) ),
		);
		$response = Outpost_Appearance_REST_Controller::handle_post( $this->post( array( 'style_source' => 'outpost' ) ) );
		$this->assertSame( '#0055aa', $this->user_meta[7]['outpost_appearance_overrides']['day']['colors']['accent'] );
		$this->assertStringContainsString( '.outpost-source-outpost', $response->get_data()['css'], 'Overrides under Outpost Default emit scoped CSS.' );
	}

	public function test_responses_are_private_and_uncacheable(): void {
		$response = Outpost_Appearance_REST_Controller::handle_post( $this->post( array() ) );
		$headers  = $response->get_headers();
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'private', $headers['Cache-Control'] );
		$this->assertStringContainsString( 'Authorization', $headers['Vary'] );

		$get = Outpost_Appearance_REST_Controller::handle_get( new \WP_REST_Request( 'GET', '/outpost/v1/appearance/tokens' ) );
		$this->assertStringContainsString( 'no-store', $get->get_headers()['Cache-Control'] );
	}

	public function test_one_users_save_does_not_touch_another_user(): void {
		$this->user_meta[8]['outpost_appearance_source'] = 'outpost';
		Outpost_Appearance_REST_Controller::handle_post( $this->post( array( 'style_source' => 'site' ) ) );
		$this->assertSame( 'outpost', $this->user_meta[8]['outpost_appearance_source'] );
		foreach ( $this->meta_writes as $write ) {
			$this->assertSame( 7, $write[0], 'Only the requesting user is written.' );
		}
	}
}
