<?php
/**
 * Outpost's composer helper routes take only `create` or `update` tokens (F-56).
 *
 * Outpost's PWA always asks for `create profile update media`. Appearance
 * writes used to accept `profile` or `draft` alone, and preview, geocode,
 * media lookup, composer-config and syndicate-targets accepted `read` alone,
 * so a token issued to another client for less could act here.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ComposerScopesTest extends TestCase {

	/** Files whose permission callbacks must pass the shared scope list. */
	private const ROUTE_FILES = array(
		'includes/class-appearance-rest-controller.php',
		'includes/class-composer-config-endpoint.php',
		'includes/class-geocode-endpoint.php',
		'includes/class-media-lookup-endpoint.php',
		'includes/class-preview-endpoint.php',
		'includes/class-syndicate-targets-endpoint.php',
	);

	public function setUp(): void {
		WP_Mock::setUp();
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn( $v ) => $v );
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => is_string( $v ) ? trim( $v ) : '' );
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
	}

	/**
	 * @param list<string> $scopes Scopes IndieAuth verified on the token.
	 */
	private static function passes_with( array $scopes ): bool {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid'; // outpost-lint:fixture-credential
		WP_Mock::onFilter( 'indieauth_response' )->with( null )->reply( array( 'scope' => implode( ' ', $scopes ) ) );
		WP_Mock::onFilter( 'indieauth_scopes' )->with( null )->reply( $scopes );

		$probe = new class() {
			use \Outpost_Bearer_Auth;

			public static function run( \WP_REST_Request $request ): bool {
				return self::bearer_has_scope( $request, self::COMPOSER_SCOPES );
			}
		};
		return $probe::run( new \WP_REST_Request( 'GET', '/outpost/v1/preview' ) );
	}

	/**
	 * @return array<string, array{0: list<string>, 1: bool}>
	 */
	public static function token_scopes(): array {
		return array(
			'read alone'          => array( array( 'read' ), false ),
			'profile alone'       => array( array( 'profile' ), false ),
			'draft alone'         => array( array( 'draft' ), false ),
			'profile and media'   => array( array( 'profile', 'media' ), false ),
			'create'              => array( array( 'create' ), true ),
			'update'              => array( array( 'update' ), true ),
			"Outpost PWA's scopes" => array( array( 'create', 'profile', 'update', 'media' ), true ),
		);
	}

	/**
	 * @dataProvider token_scopes
	 *
	 * @param list<string> $scopes Token scopes.
	 * @param bool         $passes Expected result.
	 */
	public function test_composer_scope_gate( array $scopes, bool $passes ): void {
		$this->assertSame( $passes, self::passes_with( $scopes ) );
	}

	public function test_every_composer_helper_route_uses_the_shared_scope_list(): void {
		$root = dirname( __DIR__, 2 );
		foreach ( self::ROUTE_FILES as $file ) {
			$source = (string) file_get_contents( $root . '/' . $file );
			$calls  = preg_match_all( '/bearer_has_scope\(\s*\$request\s*,\s*([^)]*)\)/', $source, $m );
			$this->assertSame( 1, $calls, "$file must check the token scope exactly once." );
			$this->assertSame( 'self::COMPOSER_SCOPES', trim( $m[1][0] ), "$file must pass self::COMPOSER_SCOPES." );
		}
	}
}
