<?php
/**
 * Outpost_OAuth_Controller::handle_callback() unit tests.
 *
 * The OAuth provider redirects the browser back to the callback route, so
 * the request carries no REST nonce and runs as nobody. The single-use state
 * value names the user who started the flow. Credentials are stored for that
 * user by id; the callback never switches the request's current user.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Encryption_Key_Resolver;
use Outpost_OAuth_Controller;
use Outpost_OAuth_Provider_Base;
use ReflectionClass;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class OAuthControllerCallbackTest extends TestCase {

	/** @var array<string, mixed> */
	private array $user_meta = array();

	/** @var array<string, mixed>|false */
	private $state_transient = false;

	/** @var object Fake provider recording what the controller asked of it. */
	private $provider;

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Encryption_Key_Resolver::reset_for_tests();
		$ref  = new ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );

		$this->user_meta       = array();
		$this->state_transient = false;
		$this->provider        = new class() extends Outpost_OAuth_Provider_Base {
			/** @var array<int, string> */
			public array $exchanged = array();
			/** @var array<int, int> */
			public array $registered = array();

			public function id(): string {
				return 'fake';
			}
			public function label(): string {
				return 'Fake';
			}
			public function authorize_url(): string {
				return 'https://provider.example/authorize';
			}
			public function token_url(): string {
				return 'https://provider.example/token';
			}
			public function revocation_endpoint(): ?string {
				return null;
			}
			public function client_id(): string {
				return 'client';
			}
			public function client_secret(): string {
				return 'secret';
			}
			public function exchange_code( string $code ) {
				$this->exchanged[] = $code;
				return array( 'access_token' => 'provider-access-token' ); // outpost-lint:fixture-credential
			}
			public function after_token_exchange( int $user_id, array $creds ): void {
				$this->registered[] = $user_id;
			}
		};
		Outpost_OAuth_Controller::reset_providers_for_tests();
		Outpost_OAuth_Controller::add_provider( $this->provider );

		$option = array();
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static function ( $key, $fallback = null ) use ( &$option ) {
				return 'outpost_encryption_key' === $key ? ( $option['value'] ?? false ) : $fallback;
			}
		);
		WP_Mock::userFunction( 'update_option' )->andReturnUsing(
			static function ( $key, $value ) use ( &$option ) {
				if ( 'outpost_encryption_key' === $key ) {
					$option['value'] = (string) $value;
				}
				return true;
			}
		);
		WP_Mock::userFunction( 'update_user_meta' )->andReturnUsing(
			function ( $uid, $key, $value ) {
				$this->user_meta[ $uid . '|' . $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'get_transient' )->andReturnUsing( fn () => $this->state_transient );
		WP_Mock::userFunction( 'delete_transient' )->andReturn( true );
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static fn ( $key ) => strtolower( (string) $key ) );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( static fn ( $data ) => json_encode( $data ) );
		WP_Mock::userFunction( 'admin_url' )->andReturnUsing( static fn ( $path = '' ) => 'https://site.test/wp-admin/' . $path );
		WP_Mock::userFunction( 'add_query_arg' )->andReturnUsing(
			static fn ( array $args, string $url ) => $url . '?' . http_build_query( $args )
		);
		// The callback request is anonymous, and must stay that way.
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		WP_Mock::userFunction( 'wp_set_current_user' )->never();
	}

	public function tearDown(): void {
		Outpost_OAuth_Controller::reset_providers_for_tests();
		Outpost_Encryption_Key_Resolver::reset_for_tests();
		WP_Mock::tearDown();
	}

	private function callback_request( string $code, string $state ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', '/outpost/v1/oauth/fake/callback' );
		$request->set_param( 'provider', 'fake' );
		$request->set_param( 'code', $code );
		$request->set_param( 'state', $state );
		return $request;
	}

	public function test_valid_state_stores_credentials_for_the_state_user_without_switching_users(): void {
		$this->state_transient = array(
			'provider' => 'fake',
			'user_id'  => 42,
		);

		$response = Outpost_OAuth_Controller::handle_callback( $this->callback_request( 'auth-code', 'state-value' ) );

		$this->assertSame( array( 'auth-code' ), $this->provider->exchanged );
		$this->assertSame( array( '42|outpost_creds_fake' ), array_keys( $this->user_meta ), 'Credentials belong to the user named by the state.' );
		$this->assertSame( array( 42 ), $this->provider->registered, 'The post-exchange hook receives the state user by id.' );
		$this->assertSame( 302, $response->get_status() );
		$this->assertStringContainsString( 'outpost_oauth_status=connected', $response->get_headers()['Location'] ?? '' );
	}

	public function test_invalid_state_exchanges_nothing_and_stores_nothing(): void {
		$this->state_transient = false;

		$response = Outpost_OAuth_Controller::handle_callback( $this->callback_request( 'auth-code', 'forged-state' ) );

		$this->assertSame( array(), $this->provider->exchanged, 'No code exchange on an unknown state.' );
		$this->assertSame( array(), $this->user_meta, 'No credentials written on an unknown state.' );
		$this->assertSame( array(), $this->provider->registered );
		$this->assertStringContainsString( 'outpost_oauth_status=state_invalid', $response->get_headers()['Location'] ?? '' );
	}

	public function test_state_issued_for_another_provider_is_rejected(): void {
		$this->state_transient = array(
			'provider' => 'other',
			'user_id'  => 42,
		);

		$response = Outpost_OAuth_Controller::handle_callback( $this->callback_request( 'auth-code', 'state-value' ) );

		$this->assertSame( array(), $this->provider->exchanged );
		$this->assertSame( array(), $this->user_meta );
		$this->assertStringContainsString( 'outpost_oauth_status=state_invalid', $response->get_headers()['Location'] ?? '' );
	}
}
