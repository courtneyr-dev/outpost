<?php
/**
 * Outpost_Fetch_Recent_REST unit tests (G-fetch-recent-picker).
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Fetch_Recent_REST;
use ReflectionClass;
use WP_Mock;
use WP_Mock\Tools\TestCase;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

final class FetchRecentRestTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		$ref  = new ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	private function provider_config_with_three_items(): array {
		return array(
			'label'          => 'Test',
			'callback'       => static function ( $count ) {
				return array(
					array(
						'id'           => 'test-1',
						'title'        => 'First item',
						'subtitle'     => 'subtitle 1',
						'fetched_at'   => '2026-05-06T00:00:00+00:00',
						'post_kind'    => 'workout',
						'post_payload' => array(
							'title'                  => 'Item 1',
							'content'                => '<p>Body 1</p>',
							'post_meta'              => array(),
							'syndication_source_url' => null,
						),
					),
					array(
						'id'           => 'test-2',
						'title'        => 'Second item',
						'fetched_at'   => '2026-05-05T00:00:00+00:00',
						'post_kind'    => 'sleep',
						'post_payload' => array(
							'title'                  => 'Item 2',
							'content'                => '<p>Body 2</p>',
							'post_meta'              => array(),
							'syndication_source_url' => null,
						),
					),
					array(
						'id'           => 'test-3',
						'title'        => 'Third item',
						'fetched_at'   => '2026-05-04T00:00:00+00:00',
						'post_kind'    => 'note',
						'post_payload' => array(
							'title'                  => 'Item 3',
							'content'                => '<p>Body 3</p>',
							'post_meta'              => array(),
							'syndication_source_url' => null,
						),
					),
				);
			},
			'capability'     => 'edit_posts',
			'oauth_provider' => null,
		);
	}

	public function test_get_providers_filters_malformed_entries(): void {
		WP_Mock::onFilter( 'outpost_fetch_recent_providers' )
			->withAnyArgs()
			->reply(
				array(
					'good'    => $this->provider_config_with_three_items(),
					'no_label' => array( 'callback' => '__return_null' ),
					'no_callback' => array( 'label' => 'No callback' ),
					42       => $this->provider_config_with_three_items(),
				)
			);

		$providers = Outpost_Fetch_Recent_REST::get_providers();

		$this->assertArrayHasKey( 'good', $providers );
		$this->assertArrayNotHasKey( 'no_label', $providers );
		$this->assertArrayNotHasKey( 'no_callback', $providers );
		$this->assertArrayNotHasKey( 42, $providers );
	}

	public function test_get_providers_falls_back_to_empty_when_filter_returns_non_array(): void {
		WP_Mock::onFilter( 'outpost_fetch_recent_providers' )
			->withAnyArgs()
			->reply( 'not-an-array' );

		$this->assertSame( array(), Outpost_Fetch_Recent_REST::get_providers() );
	}

	public function test_normalize_item_drops_entries_missing_id_or_title(): void {
		$this->assertNull(
			Outpost_Fetch_Recent_REST::normalize_item( array( 'title' => 'no id' ) )
		);
		$this->assertNull(
			Outpost_Fetch_Recent_REST::normalize_item( array( 'id' => 'no-title' ) )
		);
		$this->assertNull( Outpost_Fetch_Recent_REST::normalize_item( 'not-an-array' ) );
	}

	public function test_normalize_item_fills_canonical_shape_with_defaults(): void {
		$item = Outpost_Fetch_Recent_REST::normalize_item(
			array(
				'id'    => 'sample',
				'title' => 'Sample',
			)
		);

		$this->assertSame( 'sample', $item['id'] );
		$this->assertSame( 'Sample', $item['title'] );
		$this->assertNull( $item['subtitle'] );
		$this->assertNull( $item['icon_url'] );
		$this->assertSame( 'note', $item['post_kind'] );
		$this->assertSame( '', $item['post_payload']['content'] );
		$this->assertSame( array(), $item['post_payload']['post_meta'] );
	}

	public function test_resolve_items_caps_to_count(): void {
		$items = Outpost_Fetch_Recent_REST::resolve_items(
			$this->provider_config_with_three_items(),
			2
		);

		$this->assertCount( 2, $items );
		$this->assertSame( 'test-1', $items[0]['id'] );
		$this->assertSame( 'test-2', $items[1]['id'] );
	}

	public function test_resolve_items_returns_empty_when_callback_invalid(): void {
		$items = Outpost_Fetch_Recent_REST::resolve_items(
			array( 'callback' => 'not_a_real_function' ),
			10
		);

		$this->assertSame( array(), $items );
	}

	public function test_resolve_items_drops_malformed_entries_in_callback_result(): void {
		$config = array(
			'callback' => static function () {
				return array(
					array( 'id' => 'good', 'title' => 'Good' ),
					'not an array',
					array( 'id' => 'no-title' ),
					array( 'id' => 'second-good', 'title' => 'Second good' ),
				);
			},
		);

		$items = Outpost_Fetch_Recent_REST::resolve_items( $config, 10 );

		$this->assertCount( 2, $items );
		$this->assertSame( 'good', $items[0]['id'] );
		$this->assertSame( 'second-good', $items[1]['id'] );
	}

	public function test_handle_request_returns_404_for_unknown_provider(): void {
		WP_Mock::onFilter( 'outpost_fetch_recent_providers' )
			->withAnyArgs()
			->reply( array() );

		$request = new WP_REST_Request();
		$request->set_param( 'provider_id', 'nonexistent' );
		$request->set_param( 'count', 10 );

		$result = Outpost_Fetch_Recent_REST::handle_request( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'outpost_fetch_recent_unknown_provider', $result->get_error_code() );
	}

	public function test_handle_request_returns_403_for_insufficient_capability(): void {
		WP_Mock::onFilter( 'outpost_fetch_recent_providers' )
			->withAnyArgs()
			->reply(
				array(
					'admin' => array(
						'label'      => 'Admin only',
						'callback'   => static function () { return array(); },
						'capability' => 'manage_options',
					),
				)
			);
		WP_Mock::userFunction( 'current_user_can' )->andReturnUsing(
			static function ( $cap ) { return 'edit_posts' === $cap; }
		);

		$request = new WP_REST_Request();
		$request->set_param( 'provider_id', 'admin' );
		$request->set_param( 'count', 10 );

		$result = Outpost_Fetch_Recent_REST::handle_request( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'outpost_fetch_recent_forbidden', $result->get_error_code() );
	}

	public function test_handle_request_returns_not_connected_when_oauth_required_but_missing(): void {
		WP_Mock::onFilter( 'outpost_fetch_recent_providers' )
			->withAnyArgs()
			->reply(
				array(
					'oura' => array(
						'label'          => 'Oura',
						'callback'       => static function () { return array(); },
						'capability'     => 'edit_posts',
						'oauth_provider' => 'oura',
					),
				)
			);
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 7 );
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
		// Outpost_Credentials_Store::get is stubbed at class level by bootstrap;
		// override via filter on get_user_meta which the store reads from.
		WP_Mock::userFunction( 'get_user_meta' )->andReturn( '' );

		$request = new WP_REST_Request();
		$request->set_param( 'provider_id', 'oura' );
		$request->set_param( 'count', 10 );

		$result = Outpost_Fetch_Recent_REST::handle_request( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertSame( 200, $result->get_status() );
		$this->assertSame( 'not_connected', $data['reason'] );
		$this->assertSame( array(), $data['items'] );
	}

	public function test_handle_request_returns_items_for_test_provider(): void {
		WP_Mock::onFilter( 'outpost_fetch_recent_providers' )
			->withAnyArgs()
			->reply( array( 'test' => $this->provider_config_with_three_items() ) );
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );

		$request = new WP_REST_Request();
		$request->set_param( 'provider_id', 'test' );
		$request->set_param( 'count', 10 );

		$result = Outpost_Fetch_Recent_REST::handle_request( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$data = $result->get_data();
		$this->assertSame( 'test', $data['provider_id'] );
		$this->assertCount( 3, $data['items'] );
		$this->assertSame( 'test-1', $data['items'][0]['id'] );
	}

	public function test_handle_list_request_returns_provider_summaries(): void {
		WP_Mock::onFilter( 'outpost_fetch_recent_providers' )
			->withAnyArgs()
			->reply(
				array(
					'test' => $this->provider_config_with_three_items(),
					'oura' => array(
						'label'          => 'Oura',
						'callback'       => static function () { return array(); },
						'oauth_provider' => 'oura',
					),
				)
			);
		WP_Mock::userFunction( 'current_user_can' )->andReturn( true );

		$result = Outpost_Fetch_Recent_REST::handle_list_request();
		$data   = $result->get_data();

		$this->assertCount( 2, $data['providers'] );
		$ids = array_column( $data['providers'], 'id' );
		$this->assertContains( 'test', $ids );
		$this->assertContains( 'oura', $ids );
	}
}
