<?php
/**
 * F-54: every outbound request that carries a stored provider credential
 * follows no redirects and reads at most 5 MiB.
 *
 * WordPress's Requests library resends the original headers, the
 * Authorization header included, to a redirect target, and the default is
 * five redirects with no response cap. Each request site below captures
 * the arguments it hands to wp_remote_get / wp_remote_post /
 * wp_remote_request and asserts `redirection => 0` and
 * `limit_response_size => 5242880`. A positive control asserts the
 * captured request really carries the credential, so a site that stopped
 * sending it can't pass by accident.
 *
 * Request sites (14): fetch-recent Oura, WHOOP and Polar API helpers; the
 * Notion source; the OAuth base class's code exchange, refresh and
 * revocation; the WHOOP revoke override; verify calls for Oura, WHOOP,
 * Ravelry, Ride With GPS and Polar; Polar's user registration.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Credentials_Store;
use Outpost_Encryption_Key_Resolver;
use Outpost_Fetch_Recent_Oura;
use Outpost_Fetch_Recent_Polar;
use Outpost_Fetch_Recent_Whoop;
use Outpost_OAuth_Provider_Base;
use Outpost_OAuth_Provider_Notion;
use Outpost_OAuth_Provider_Oura;
use Outpost_OAuth_Provider_Polar;
use Outpost_OAuth_Provider_Ravelry;
use Outpost_OAuth_Provider_Ridewithgps;
use Outpost_OAuth_Provider_Whoop;
use Outpost_Source_Notion;
use ReflectionClass;
use ReflectionMethod;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class ProviderTokenRequestArgsTest extends TestCase {

	private const FIVE_MIB = 5242880;

	private const TOKEN = 'stored-access-token-f54';

	private const REFRESH = 'stored-refresh-token-f54';

	private const CODE = 'auth-code-f54';

	private const USER = 7;

	/** @var list<array{fn:string,url:string,args:array<string,mixed>}> */
	private array $calls = array();

	public function setUp(): void {
		WP_Mock::setUp();
		Outpost_Encryption_Key_Resolver::reset_for_tests();
		$ref  = new ReflectionClass( \WP_Mock\Filter::class );
		$prop = $ref->getProperty( 'filtersWithAnyArgs' );
		$prop->setValue( null, array() );
		Outpost_Fetch_Recent_Oura::set_http_resolver_for_tests( null );
		Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests( null );
		Outpost_Fetch_Recent_Whoop::set_http_resolver_for_tests( null );
		$this->calls = array();
		$this->stub_meta_store();
		$this->capture_http();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		Outpost_Encryption_Key_Resolver::reset_for_tests();
	}

	// --- fetch-recent and source API helpers ------------------------------

	public function test_fetch_recent_oura_request(): void {
		( new ReflectionMethod( Outpost_Fetch_Recent_Oura::class, 'api_get' ) )->invoke( null, '/usercollection/sleep', self::TOKEN );
		$this->assert_hardened( 'fetch-recent Oura', self::TOKEN, 1 );
	}

	public function test_fetch_recent_whoop_request(): void {
		( new ReflectionMethod( Outpost_Fetch_Recent_Whoop::class, 'api_get' ) )->invoke( null, '/developer/v2/cycle', self::TOKEN );
		$this->assert_hardened( 'fetch-recent WHOOP', self::TOKEN, 1 );
	}

	public function test_fetch_recent_polar_request(): void {
		( new ReflectionMethod( Outpost_Fetch_Recent_Polar::class, 'api_request' ) )->invoke(
			null,
			'POST',
			'https://www.polaraccesslink.com/v3/users/7/exercise-transactions',
			self::TOKEN,
			null
		);
		$this->assert_hardened( 'fetch-recent Polar', self::TOKEN, 1 );
	}

	public function test_notion_source_request(): void {
		( new ReflectionMethod( Outpost_Source_Notion::class, 'api_get' ) )->invoke( null, '/pages/abc', self::TOKEN );
		$this->assert_hardened( 'Notion source', self::TOKEN, 1 );
	}

	// --- OAuth base: exchange, refresh, revoke ----------------------------

	/**
	 * @return array<string,array{0:class-string<Outpost_OAuth_Provider_Base>}>
	 */
	public function providers(): array {
		return array(
			'notion'      => array( Outpost_OAuth_Provider_Notion::class ),
			'oura'        => array( Outpost_OAuth_Provider_Oura::class ),
			'polar'       => array( Outpost_OAuth_Provider_Polar::class ),
			'ravelry'     => array( Outpost_OAuth_Provider_Ravelry::class ),
			'ridewithgps' => array( Outpost_OAuth_Provider_Ridewithgps::class ),
			'whoop'       => array( Outpost_OAuth_Provider_Whoop::class ),
		);
	}

	/**
	 * @dataProvider providers
	 *
	 * @param class-string<Outpost_OAuth_Provider_Base> $class Provider class.
	 */
	public function test_code_exchange_request( string $class ): void {
		( new $class() )->exchange_code( self::CODE );
		$this->assert_hardened( "{$class} code exchange", self::CODE, 1 );
	}

	/**
	 * @dataProvider providers
	 *
	 * @param class-string<Outpost_OAuth_Provider_Base> $class Provider class.
	 */
	public function test_refresh_request( string $class ): void {
		$provider = new $class();
		$this->store_creds( $provider->id() );
		$provider->refresh_access_token( self::USER );
		// Notion's token_request_body() always builds the authorization_code
		// body, so its refresh carries the client secret (Basic header) but
		// not the refresh token. Notion tokens don't expire, so it's unused.
		$marker = $provider instanceof Outpost_OAuth_Provider_Notion ? '"Authorization":"Basic ' : self::REFRESH;
		$this->assert_hardened( "{$class} refresh", $marker, 1 );
	}

	/**
	 * @dataProvider providers
	 *
	 * @param class-string<Outpost_OAuth_Provider_Base> $class Provider class.
	 */
	public function test_revoke_request( string $class ): void {
		$provider = new $class();
		$this->store_creds( $provider->id() );
		$provider->disconnect( self::USER );
		// WHOOP overrides disconnect() with its own DELETE; the others
		// revoke only when they declare a revocation endpoint.
		$expected = ( null !== $provider->revocation_endpoint() || $provider instanceof Outpost_OAuth_Provider_Whoop ) ? 1 : 0;
		$this->assert_hardened( "{$class} revoke", self::TOKEN, $expected );
	}

	// --- provider verify and registration ---------------------------------

	/**
	 * @return array<string,array{0:class-string<Outpost_OAuth_Provider_Base>}>
	 */
	public function verifying_providers(): array {
		return array(
			'oura'        => array( Outpost_OAuth_Provider_Oura::class ),
			'polar'       => array( Outpost_OAuth_Provider_Polar::class ),
			'ravelry'     => array( Outpost_OAuth_Provider_Ravelry::class ),
			'ridewithgps' => array( Outpost_OAuth_Provider_Ridewithgps::class ),
			'whoop'       => array( Outpost_OAuth_Provider_Whoop::class ),
		);
	}

	/**
	 * @dataProvider verifying_providers
	 *
	 * @param class-string<Outpost_OAuth_Provider_Base> $class Provider class.
	 */
	public function test_verify_request( string $class ): void {
		$provider = new $class();
		$this->store_creds( $provider->id() );
		$provider->verify_connection( self::USER );
		$this->assert_hardened( "{$class} verify", self::TOKEN, 1 );
	}

	public function test_polar_registration_request(): void {
		( new Outpost_OAuth_Provider_Polar() )->after_token_exchange( self::USER, array( 'access_token' => self::TOKEN ) );
		$this->assert_hardened( 'Polar registration', self::TOKEN, 1 );
	}

	// --- helpers ------------------------------------------------------------

	/**
	 * Each captured request carries the credential and the two limits.
	 */
	private function assert_hardened( string $label, string $credential, int $expected_calls ): void {
		$this->assertCount( $expected_calls, $this->calls, "{$label}: request count" );
		foreach ( $this->calls as $call ) {
			$this->assertStringContainsString(
				$credential,
				(string) json_encode( $call['args'] ),
				"{$label}: positive control, the request carries the stored credential"
			);
			$this->assertSame( 0, $call['args']['redirection'] ?? null, "{$label}: {$call['fn']} follows no redirect" );
			$this->assertSame( self::FIVE_MIB, $call['args']['limit_response_size'] ?? null, "{$label}: {$call['fn']} reads at most 5 MiB" );
		}
	}

	/**
	 * Record every WordPress HTTP call and answer 200 with a token body.
	 */
	private function capture_http(): void {
		$body = '{"access_token":"fresh-token","refresh_token":"fresh-refresh","expires_in":3600}';
		foreach ( array( 'wp_remote_get', 'wp_remote_post', 'wp_remote_request' ) as $fn ) {
			WP_Mock::userFunction( $fn )->andReturnUsing(
				function ( $url, $args = array() ) use ( $fn, $body ) {
					$this->calls[] = array(
						'fn'   => $fn,
						'url'  => (string) $url,
						'args' => is_array( $args ) ? $args : array(),
					);
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => $body,
					);
				}
			);
		}
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing(
			static function ( $response ) {
				return $response['response']['code'];
			}
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing(
			static function ( $response ) {
				return $response['body'];
			}
		);
	}

	private function store_creds( string $provider_id ): void {
		Outpost_Credentials_Store::set(
			$provider_id,
			array(
				'access_token'  => self::TOKEN,
				'refresh_token' => self::REFRESH,
			),
			self::USER
		);
	}

	/**
	 * In-memory option and user-meta store so the encryption key resolver
	 * and the credentials store round-trip through real Sodium.
	 */
	private function stub_meta_store(): void {
		$user_meta = array();
		$option    = array( 'value' => null );
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static fn( $s ) => $s );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing( static fn( $d ) => json_encode( $d ) );
		WP_Mock::userFunction( 'get_option' )->andReturnUsing(
			static function ( $key, $default = null ) use ( &$option ) {
				if ( 'outpost_encryption_key' === $key ) {
					return $option['value'] ?? false;
				}
				return $default;
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
			static function ( $uid, $key, $value ) use ( &$user_meta ) {
				$user_meta[ $uid . '|' . $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			static function ( $uid, $key ) use ( &$user_meta ) {
				return $user_meta[ $uid . '|' . $key ] ?? '';
			}
		);
		WP_Mock::userFunction( 'delete_user_meta' )->andReturn( true );
		WP_Mock::userFunction( 'get_current_user_id' )->andReturn( self::USER );
		WP_Mock::userFunction( 'rest_url' )->andReturnUsing( static fn( $path = '' ) => 'https://example.test/wp-json/' . ltrim( (string) $path, '/' ) );
	}
}
