<?php
/**
 * Unit tests for Outpost's native WordPress abilities.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Abilities;
use WP_Mock;

final class AbilitiesTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		\outpost_test_reset_hooks();
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn ( string $text ): string => $text );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_register_hooks_category_and_abilities_lifecycles(): void {
		Outpost_Abilities::register();

		$category_hooks = \outpost_test_actions_for( 'wp_abilities_api_categories_init' );
		$ability_hooks  = \outpost_test_actions_for( 'wp_abilities_api_init' );

		$this->assertCount( 1, $category_hooks );
		$this->assertSame( array( Outpost_Abilities::class, 'register_category' ), $category_hooks[0]['callback'] );
		$this->assertCount( 1, $ability_hooks );
		$this->assertSame( array( Outpost_Abilities::class, 'register_abilities' ), $ability_hooks[0]['callback'] );
	}

	public function test_registers_outpost_category(): void {
		$registered = array();
		WP_Mock::userFunction( 'wp_register_ability_category' )
			->once()
			->andReturnUsing(
				static function ( string $slug, array $args ) use ( &$registered ): void {
					$registered = array( $slug, $args );
				}
			);

		Outpost_Abilities::register_category();

		$this->assertSame( 'outpost', $registered[0] );
		$this->assertSame( 'Outpost', $registered[1]['label'] );
	}

	public function test_registers_three_public_read_abilities_with_schemas(): void {
		$registered = array();
		WP_Mock::userFunction( 'wp_register_ability' )
			->times( 3 )
			->andReturnUsing(
				static function ( string $name, array $args ) use ( &$registered ): void {
					$registered[ $name ] = $args;
				}
			);

		Outpost_Abilities::register_abilities();

		$this->assertCount( 3, $registered );
		$this->assertSame(
			array(
				'outpost/get-syndicate-targets',
				'outpost/get-share-status',
				'outpost/get-composer-config',
			),
			array_keys( $registered )
		);

		foreach ( $registered as $name => $args ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $name );
			$this->assertSame( 'outpost', $args['category'] );
			$this->assertIsArray( $args['input_schema'] );
			$this->assertIsArray( $args['output_schema'] );
			$this->assertTrue( $args['meta']['show_in_rest'] );
			$this->assertSame(
				array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				$args['meta']['annotations']
			);
			$this->assertSame( array( 'public' => true ), $args['meta']['mcp'] );
		}
	}

	public function test_input_schemas_reject_unknown_fields_and_constrain_post_id(): void {
		$registered = $this->registered_abilities();

		$targets = $registered['outpost/get-syndicate-targets']['input_schema'];
		$this->assertSame( 'string', $targets['properties']['mode']['type'] );
		$this->assertFalse( $targets['additionalProperties'] );
		$this->assertSame( array(), $targets['default'] );

		$status = $registered['outpost/get-share-status']['input_schema'];
		$this->assertSame( array( 'post_id' ), $status['required'] );
		$this->assertSame( 'integer', $status['properties']['post_id']['type'] );
		$this->assertSame( 1, $status['properties']['post_id']['minimum'] );
		$this->assertFalse( $status['additionalProperties'] );

		$config = $registered['outpost/get-composer-config']['input_schema'];
		$this->assertSame( array(), $config['properties'] );
		$this->assertFalse( $config['additionalProperties'] );
		$this->assertSame( array(), $config['default'] );
	}

	/**
	 * @dataProvider edit_posts_permission_provider
	 */
	public function test_edit_posts_permission_matrix( string $role, bool $allowed ): void {
		unset( $role );
		WP_Mock::userFunction( 'current_user_can' )
			->once()
			->with( 'edit_posts' )
			->andReturn( $allowed );

		$this->assertSame( $allowed, Outpost_Abilities::can_read_outpost() );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public function edit_posts_permission_provider(): array {
		return array(
			'logged out'  => array( 'logged-out', false ),
			'subscriber'  => array( 'subscriber', false ),
			'contributor' => array( 'contributor', true ),
			'administrator' => array( 'administrator', true ),
		);
	}

	/**
	 * @dataProvider edit_post_permission_provider
	 */
	public function test_share_status_permission_matrix( string $role, bool $allowed ): void {
		unset( $role );
		$post = new \stdClass();
		WP_Mock::userFunction( 'get_post' )->once()->with( 42 )->andReturn( $post );
		WP_Mock::userFunction( 'current_user_can' )
			->once()
			->with( 'edit_post', 42 )
			->andReturn( $allowed );

		$this->assertSame( $allowed, Outpost_Abilities::can_get_share_status( array( 'post_id' => 42 ) ) );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public function edit_post_permission_provider(): array {
		return array(
			'logged out'    => array( 'logged-out', false ),
			'subscriber'    => array( 'subscriber', false ),
			'contributor'   => array( 'contributor-own-post', true ),
			'administrator' => array( 'administrator', true ),
		);
	}

	public function test_share_status_permission_rejects_missing_post_before_capability_check(): void {
		WP_Mock::userFunction( 'get_post' )->once()->with( 999 )->andReturn( null );
		WP_Mock::userFunction( 'current_user_can' )->never();

		$this->assertFalse( Outpost_Abilities::can_get_share_status( array( 'post_id' => 999 ) ) );
	}

	public function test_share_status_permission_rejects_invalid_input(): void {
		WP_Mock::userFunction( 'get_post' )->never();
		WP_Mock::userFunction( 'current_user_can' )->never();

		$this->assertFalse( Outpost_Abilities::can_get_share_status( array() ) );
		$this->assertFalse( Outpost_Abilities::can_get_share_status( array( 'post_id' => 0 ) ) );
		$this->assertFalse( Outpost_Abilities::can_get_share_status( array( 'post_id' => '42' ) ) );
	}

	/**
	 * Capture all ability registration arguments.
	 *
	 * @return array<string, array>
	 */
	private function registered_abilities(): array {
		$registered = array();
		WP_Mock::userFunction( 'wp_register_ability' )
			->times( 3 )
			->andReturnUsing(
				static function ( string $name, array $args ) use ( &$registered ): void {
					$registered[ $name ] = $args;
				}
			);
		Outpost_Abilities::register_abilities();
		return $registered;
	}
}
