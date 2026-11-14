<?php
/**
 * Connected-account data is read only for the first party (T10-S3-06, F-07).
 *
 * Fetch-recent returns data from a person's connected accounts (Oura, WHOOP,
 * Polar, future providers). Its only consumer is the wp-admin block-editor
 * sidebar, which calls it through apiFetch with the logged-in cookie and a
 * REST nonce. So both fetch-recent routes accept exactly that: a cookie
 * session with a valid nonce, `edit_posts`, and the provider's capability.
 * Every bearer token (another client's, and the Outpost PWA's own) and every
 * Application Password is refused, before any provider lookup, credential
 * read, provider callback, or outbound request.
 *
 * Preview stays available to every caller it served before. Its Notion
 * enrichment reads the person's stored Notion credential and calls the
 * Notion API, so it runs only for the Outpost PWA: an IndieAuth bearer whose
 * verified `client_id` is exactly this site's Outpost client identifier.
 * Anyone else gets the generic preview, with no credential read, no Notion
 * API request, and a response that doesn't reveal whether Notion is
 * connected.
 *
 * The fetch-recent provider here is a test-only fixture registered through
 * the providers filter; the shipped Test provider is not used. Credentials
 * are synthetic and every outbound request is answered in-process.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Credentials_Store;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

final class ConnectedAccountReadsTest extends TestCase {

	private const FIXTURE    = 't10fixture';
	private const NOTION_URL = 'https://www.notion.so/T10-Fixture-0123456789abcdef0123456789abcdef';

	private int $editor_id = 0;

	private int $subscriber_id = 0;

	/** Provider callback invocations. */
	private int $callbacks = 0;

	/** @var string[] Credential meta keys read, in order. */
	private array $credential_reads = array();

	/** @var array<int, array{url: string, authorization: string}> */
	private array $outbound = array();

	/** @var array<string, mixed> */
	private array $saved_server = array();

	/** @var mixed */
	private $saved_auth_cookie = null;

	/** @var mixed */
	private $saved_app_password = null;

	/** @var array<string, mixed>|null IndieAuth's verified token response for this test. */
	private ?array $indieauth_response = null;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Fetch_Recent_REST' ) || ! class_exists( 'Outpost_Preview_Endpoint' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap. Run via `npm run test:integration` inside wp-env tests-cli.' );
		}
		$this->saved_server       = $_SERVER;
		$this->saved_auth_cookie  = $GLOBALS['wp_rest_auth_cookie'] ?? null;
		$this->saved_app_password = $GLOBALS['wp_rest_application_password_uuid'] ?? null;

		$this->editor_id     = $this->make_user( 'editor' );
		$this->subscriber_id = $this->make_user( 'subscriber' );
		Outpost_Credentials_Store::set( self::FIXTURE, array( 'access_token' => 'synthetic-fixture-token' ), $this->editor_id );
		Outpost_Credentials_Store::set( 'notion', array( 'access_token' => 'synthetic-notion-token' ), $this->editor_id );

		$this->callbacks        = 0;
		$this->credential_reads = array();
		$this->outbound         = array();

		add_filter( 'outpost_fetch_recent_providers', array( $this, 'register_fixture' ) );
		add_filter( 'get_user_metadata', array( $this, 'count_credential_read' ), 0, 3 );
		add_filter( 'pre_http_request', array( $this, 'record_and_stub' ), 0, 3 );
		add_filter( 'indieauth_response', array( $this, 'indieauth_response' ) );
		add_filter( 'indieauth_scopes', array( $this, 'indieauth_scopes' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'outpost_fetch_recent_providers', array( $this, 'register_fixture' ) );
		remove_filter( 'get_user_metadata', array( $this, 'count_credential_read' ), 0 );
		remove_filter( 'pre_http_request', array( $this, 'record_and_stub' ), 0 );
		remove_filter( 'indieauth_response', array( $this, 'indieauth_response' ) );
		remove_filter( 'indieauth_scopes', array( $this, 'indieauth_scopes' ) );
		$_SERVER                                      = $this->saved_server;
		$GLOBALS['wp_rest_auth_cookie']               = $this->saved_auth_cookie;
		$GLOBALS['wp_rest_application_password_uuid'] = $this->saved_app_password;
		$this->indieauth_response                     = null;
		wp_set_current_user( 0 );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( array( $this->editor_id, $this->subscriber_id ) as $id ) {
			if ( $id > 0 ) {
				Outpost_Credentials_Store::delete( self::FIXTURE, $id );
				Outpost_Credentials_Store::delete( 'notion', $id );
				wp_delete_user( $id );
			}
		}
		parent::tearDown();
	}

	private function make_user( string $role ): int {
		return (int) wp_insert_user(
			array(
				'user_login' => 't10_' . $role . '_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 't10_' . $role . '_' . uniqid() . '@example.test',
				'role'       => $role,
			)
		);
	}

	// ----- Hooks --------------------------------------------------------

	/**
	 * @param array<string, mixed> $providers Registered providers.
	 * @return array<string, mixed>
	 */
	public function register_fixture( $providers ): array {
		$providers                   = is_array( $providers ) ? $providers : array();
		$providers[ self::FIXTURE ] = array(
			'label'          => 'T10 fixture',
			'capability'     => 'edit_posts',
			'oauth_provider' => self::FIXTURE,
			'callback'       => function ( int $count ): array {
				unset( $count );
				++$this->callbacks;
				wp_remote_get( 'https://fixture-provider.example.test/v1/recent' );
				return array(
					array(
						'id'    => 'synthetic-1',
						'title' => 'Synthetic workout',
					),
				);
			},
		);
		return $providers;
	}

	/**
	 * Count reads of Outpost credential meta. Returns $value unchanged.
	 *
	 * @param mixed  $value    Short-circuit value.
	 * @param int    $user_id  User id.
	 * @param string $meta_key Meta key.
	 * @return mixed
	 */
	public function count_credential_read( $value, $user_id, $meta_key ) {
		unset( $user_id );
		if ( is_string( $meta_key ) && 0 === strpos( $meta_key, 'outpost_creds_' ) ) {
			$this->credential_reads[] = $meta_key;
		}
		return $value;
	}

	/**
	 * Record every outbound request and answer it in-process.
	 *
	 * @param false|array|\WP_Error $pre  Short-circuit value.
	 * @param array                 $args Request args.
	 * @param string                $url  Request URL.
	 * @return array
	 */
	public function record_and_stub( $pre, $args, $url ): array {
		unset( $pre );
		$headers          = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		$this->outbound[] = array(
			'url'           => (string) $url,
			'authorization' => (string) ( $headers['Authorization'] ?? '' ),
		);
		$is_api = 'api.notion.com' === wp_parse_url( (string) $url, PHP_URL_HOST );
		return array(
			'headers'  => array( 'content-type' => $is_api ? 'application/json' : 'text/html; charset=utf-8' ),
			'body'     => $is_api
				? (string) wp_json_encode(
					array(
						'object'     => 'page',
						'id'         => '0123456789abcdef0123456789abcdef',
						'properties' => array(
							'title' => array(
								'type'  => 'title',
								'title' => array( array( 'plain_text' => 'Synthetic connected page' ) ),
							),
						),
						'results'    => array(),
					)
				)
				: '<html><head><title>Public stub</title><meta property="og:title" content="Public stub"></head><body>stub</body></html>',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** @return array<string, mixed>|null */
	public function indieauth_response() {
		return $this->indieauth_response;
	}

	/** @return string[]|null */
	public function indieauth_scopes() {
		if ( null === $this->indieauth_response ) {
			return null;
		}
		return explode( ' ', (string) ( $this->indieauth_response['scope'] ?? '' ) );
	}

	// ----- Arrivals -----------------------------------------------------

	/** Stands for "a nonce minted for the arriving user", created after the user switch. */
	private const VALID_NONCE = '__valid_for_this_user__';

	/** The wp-admin sidebar: logged-in cookie, nonce in X-WP-Nonce (or none). */
	private function arrive_with_cookie( int $user_id, ?string $nonce ): void {
		wp_set_current_user( $user_id );
		$GLOBALS['wp_rest_auth_cookie'] = true;
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_WP_NONCE'] );
		if ( null !== $nonce ) {
			$_SERVER['HTTP_X_WP_NONCE'] = self::VALID_NONCE === $nonce ? wp_create_nonce( 'wp_rest' ) : $nonce;
		}
	}

	/**
	 * A request IndieAuth authenticated from a bearer token: IndieAuth resolved
	 * the user and exposes the verified token response.
	 *
	 * @param string|null $client_id Verified client_id, or null for none.
	 * @param string      $scope     Space-separated scopes.
	 */
	private function arrive_with_bearer( ?string $client_id, string $scope ): void {
		wp_set_current_user( $this->editor_id );
		$GLOBALS['wp_rest_auth_cookie'] = false;
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer synthetic-bearer-token';
		$response                      = array(
			'scope' => $scope,
			'me'    => home_url( '/' ),
		);
		if ( null !== $client_id ) {
			$response['client_id'] = $client_id;
		}
		$this->indieauth_response = $response;
	}

	/** Core authenticated the request with an Application Password (Basic auth). */
	private function arrive_with_application_password(): void {
		wp_set_current_user( $this->editor_id );
		$GLOBALS['wp_rest_auth_cookie']               = false;
		$GLOBALS['wp_rest_application_password_uuid'] = '00000000-0000-4000-8000-000000000000';
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'synthetic:not-a-real-password' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * This site's Outpost PWA client identifier, derived here from home_url()
	 * the way the PWA derives it (its origin plus `/post/`), not from the
	 * code under test.
	 */
	private static function outpost_client_id(): string {
		$home = wp_parse_url( home_url() );
		$port = isset( $home['port'] ) ? ':' . $home['port'] : '';
		return $home['scheme'] . '://' . $home['host'] . $port . '/post/';
	}

	// ----- Requests -----------------------------------------------------

	private function fetch_recent_list(): \WP_REST_Response {
		return rest_ensure_response( rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/outpost/v1/fetch-recent-providers' ) ) );
	}

	private function fetch_recent_items(): \WP_REST_Response {
		return rest_ensure_response( rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/outpost/v1/fetch-recent/' . self::FIXTURE ) ) );
	}

	private function preview_notion(): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/outpost/v1/preview' );
		$request->set_param( 'url', self::NOTION_URL );
		return rest_ensure_response( rest_get_server()->dispatch( $request ) );
	}

	/** @return string[] Hosts contacted. */
	private function hosts(): array {
		return array_values( array_unique( array_map( static fn( array $o ): string => (string) wp_parse_url( $o['url'], PHP_URL_HOST ), $this->outbound ) ) );
	}

	private function assert_no_side_effects( string $why ): void {
		$this->assertSame( 0, $this->callbacks, "$why: no provider callback may run." );
		$this->assertSame( array(), $this->credential_reads, "$why: no stored credential may be read." );
		$this->assertSame( array(), $this->outbound, "$why: no outbound request may be sent." );
	}

	private function assert_refused( \WP_REST_Response $response, string $why ): void {
		$this->assertGreaterThanOrEqual( 401, $response->get_status(), "$why: must be refused." );
		$this->assertStringNotContainsString( 'Synthetic workout', (string) wp_json_encode( $response->get_data() ), "$why: no provider data." );
		$this->assertStringNotContainsString( 'T10 fixture', (string) wp_json_encode( $response->get_data() ), "$why: no provider listing." );
	}

	// ----- Fetch-recent -------------------------------------------------

	public function test_editor_cookie_with_valid_nonce_lists_and_fetches(): void {
		$this->arrive_with_cookie( $this->editor_id, self::VALID_NONCE );

		$list = $this->fetch_recent_list();
		$this->assertSame( 200, $list->get_status() );
		$this->assertContains( self::FIXTURE, array_column( (array) $list->get_data()['providers'], 'id' ) );

		$items = $this->fetch_recent_items();
		$this->assertSame( 200, $items->get_status() );
		$this->assertSame( 'Synthetic workout', $items->get_data()['items'][0]['title'] ?? null );
		$this->assertSame( 1, $this->callbacks );
		$this->assertContains( 'fixture-provider.example.test', $this->hosts() );
	}

	/**
	 * @return array<string, array{0: ?string}>
	 */
	public static function bad_nonces(): array {
		return array(
			'missing nonce' => array( null ),
			'invalid nonce' => array( 'forged0000' ),
		);
	}

	/**
	 * @dataProvider bad_nonces
	 *
	 * @param ?string $nonce Nonce to send.
	 */
	public function test_cookie_without_a_valid_nonce_is_refused_before_any_work( ?string $nonce ): void {
		$this->arrive_with_cookie( $this->editor_id, $nonce );

		$this->assert_refused( $this->fetch_recent_list(), 'list' );
		$this->assert_refused( $this->fetch_recent_items(), 'fetch' );
		$this->assert_no_side_effects( 'cookie without a valid nonce' );
	}

	public function test_subscriber_cookie_with_valid_nonce_is_refused(): void {
		$this->arrive_with_cookie( $this->subscriber_id, self::VALID_NONCE );

		$this->assert_refused( $this->fetch_recent_list(), 'list' );
		$this->assert_refused( $this->fetch_recent_items(), 'fetch' );
		$this->assert_no_side_effects( 'subscriber' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function token_scopes(): array {
		return array(
			'create' => array( 'create' ),
			'draft'  => array( 'draft' ),
			'update' => array( 'update' ),
			'read'   => array( 'read' ),
		);
	}

	/**
	 * @dataProvider token_scopes
	 *
	 * @param string $scope Token scope.
	 */
	public function test_another_clients_bearer_token_is_refused( string $scope ): void {
		$this->arrive_with_bearer( 'https://other-client.example.test/', $scope );

		$this->assert_refused( $this->fetch_recent_list(), "list ($scope)" );
		$this->assert_refused( $this->fetch_recent_items(), "fetch ($scope)" );
		$this->assert_no_side_effects( "other-client bearer ($scope)" );
	}

	public function test_the_outpost_pwa_bearer_token_is_refused_too(): void {
		$this->arrive_with_bearer( self::outpost_client_id(), 'create update media read' );

		$this->assert_refused( $this->fetch_recent_list(), 'list' );
		$this->assert_refused( $this->fetch_recent_items(), 'fetch' );
		$this->assert_no_side_effects( 'Outpost PWA bearer' );
	}

	public function test_a_bearer_token_with_a_cookie_session_is_refused(): void {
		$this->arrive_with_cookie( $this->editor_id, self::VALID_NONCE );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer synthetic-bearer-token';

		$this->assert_refused( $this->fetch_recent_items(), 'fetch' );
		$this->assert_no_side_effects( 'cookie plus bearer' );
	}

	public function test_an_application_password_is_refused(): void {
		$this->arrive_with_application_password();

		$this->assert_refused( $this->fetch_recent_list(), 'list' );
		$this->assert_refused( $this->fetch_recent_items(), 'fetch' );
		$this->assert_no_side_effects( 'Application Password' );
	}

	// ----- Preview: Notion enrichment -----------------------------------

	public function test_the_outpost_pwa_receives_connected_notion_content(): void {
		$this->arrive_with_bearer( self::outpost_client_id(), 'create update media' );

		$response = $this->preview_notion();

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Synthetic connected page', (string) wp_json_encode( $response->get_data() ) );
		$this->assertContains( 'api.notion.com', $this->hosts() );
		$this->assertContains( 'outpost_creds_notion', $this->credential_reads );
	}

	public function test_another_client_gets_generic_preview_without_touching_notion_credentials(): void {
		$this->arrive_with_bearer( 'https://other-client.example.test/', 'create' );

		$this->assert_generic_only( $this->preview_notion(), 'other client' );
	}

	public function test_ordinary_preview_is_unchanged_for_another_client(): void {
		$this->arrive_with_bearer( 'https://other-client.example.test/', 'read' );
		$request = new WP_REST_Request( 'POST', '/outpost/v1/preview' );
		$request->set_param( 'url', 'https://example.org/page-0123456789abcdef' );

		$response = rest_ensure_response( rest_get_server()->dispatch( $request ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Public stub', (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( array(), $this->credential_reads );
	}

	/**
	 * @return array<string, array{0: ?string}>
	 */
	public static function foreign_client_ids(): array {
		return array(
			'missing'          => array( null ),
			'malformed'        => array( 'not a url' ),
			'empty'            => array( '' ),
			'relative'         => array( '/post/' ),
			'path mismatch'    => array( '{origin}/other/' ),
			'no path'          => array( '{origin}' ),
			'nested path'      => array( '{origin}/post/extra/' ),
			'scheme mismatch'  => array( '{other-scheme}' ),
			'port mismatch'    => array( '{other-port}' ),
			'lookalike suffix' => array( '{scheme}://{host}.evil.test{port}/post/' ),
			'lookalike prefix' => array( '{scheme}://evil-{host}{port}/post/' ),
			'userinfo trick'   => array( '{scheme}://{host}{port}@evil.test/post/' ),
			'query added'      => array( '{origin}/post/?x=1' ),
			'dot segments'     => array( '{origin}/other/../post/' ),
		);
	}

	/**
	 * @dataProvider foreign_client_ids
	 *
	 * @param ?string $pattern Client id, with placeholders filled from home_url().
	 */
	public function test_a_client_id_that_is_not_exactly_outposts_gets_no_connected_data( ?string $pattern ): void {
		$this->arrive_with_bearer( null === $pattern ? null : self::fill( $pattern ), 'create' );

		$this->assert_generic_only( $this->preview_notion(), 'client id ' . var_export( $pattern, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
	}

	public function test_case_and_default_port_differences_still_identify_outpost(): void {
		$home  = wp_parse_url( home_url() );
		$port  = isset( $home['port'] ) ? ':' . $home['port'] : ( 'https' === $home['scheme'] ? ':443' : ':80' );
		$upper = strtoupper( $home['scheme'] ) . '://' . strtoupper( $home['host'] ) . $port . '/post/';
		$this->arrive_with_bearer( $upper, 'create' );

		$this->assertContains( 'api.notion.com', ( function (): array {
			$this->preview_notion();
			return $this->hosts();
		} )() );
	}

	public function test_a_cookie_session_gets_generic_preview(): void {
		$this->arrive_with_cookie( $this->editor_id, self::VALID_NONCE );

		$this->assert_generic_only( $this->preview_notion(), 'cookie session (no first-party UI uses Notion enrichment)' );
	}

	public function test_an_application_password_gets_generic_preview(): void {
		$this->arrive_with_application_password();

		$this->assert_generic_only( $this->preview_notion(), 'Application Password' );
	}

	public function test_the_generic_answer_does_not_reveal_a_notion_connection(): void {
		$this->arrive_with_bearer( 'https://other-client.example.test/', 'create' );
		$connected = $this->preview_notion()->get_data();

		Outpost_Credentials_Store::delete( 'notion', $this->editor_id );
		$this->outbound = array();
		$disconnected   = $this->preview_notion()->get_data();

		$this->assertSame( wp_json_encode( $disconnected ), wp_json_encode( $connected ) );
	}

	// ----- Canonical client identity ------------------------------------

	/**
	 * @return array<string, array{0: string, 1: ?string}>
	 */
	public static function canonical_forms(): array {
		return array(
			'already canonical'  => array( 'https://example.test/post/', 'https://example.test/post/' ),
			'host and scheme case' => array( 'HTTPS://Example.TEST/post/', 'https://example.test/post/' ),
			'default https port' => array( 'https://example.test:443/post/', 'https://example.test/post/' ),
			'default http port'  => array( 'http://example.test:80/post/', 'http://example.test/post/' ),
			'other port kept'    => array( 'https://example.test:8443/post/', 'https://example.test:8443/post/' ),
			'empty path'         => array( 'https://example.test', 'https://example.test/' ),
			'path case kept'     => array( 'https://example.test/Post/', 'https://example.test/Post/' ),
			'no trailing slash'  => array( 'https://example.test/post', 'https://example.test/post' ),
			'relative'           => array( '/post/', null ),
			'not a url'          => array( 'not a url', null ),
			'other scheme'       => array( 'ftp://example.test/post/', null ),
			'userinfo'           => array( 'https://user@example.test/post/', null ),
			'query'              => array( 'https://example.test/post/?a=1', null ),
			'fragment'           => array( 'https://example.test/post/#x', null ),
			'dot segment'        => array( 'https://example.test/a/../post/', null ),
			'encoded dot segment' => array( 'https://example.test/a/%2e%2e/post/', null ),
		);
	}

	/**
	 * @dataProvider canonical_forms
	 *
	 * @param string  $input    Client id as presented.
	 * @param ?string $expected Canonical form, or null when it isn't an acceptable client id.
	 */
	public function test_client_ids_are_compared_in_one_canonical_form( string $input, ?string $expected ): void {
		$this->assertSame( $expected, \Outpost_Client_Identity::canonical( $input ) );
	}

	public function test_the_outpost_client_id_is_this_sites_origin_plus_the_composer_path(): void {
		$this->assertSame( self::outpost_client_id(), \Outpost_Client_Identity::outpost_client_id() );
		$this->assertTrue( \Outpost_Client_Identity::is_outpost_client( self::outpost_client_id() ) );
		$this->assertFalse( \Outpost_Client_Identity::is_outpost_client( null ) );
	}

	private function assert_generic_only( \WP_REST_Response $response, string $why ): void {
		$body = (string) wp_json_encode( $response->get_data() );
		// Still authorized: the generic path answers (for a Notion URL it is the
		// same answer a person who never connected Notion gets).
		$this->assertNotContains( $response->get_status(), array( 401, 403 ), "$why: preview stays authorized. Body: $body" );
		$this->assertStringNotContainsString( 'Synthetic connected page', $body, "$why: no connected Notion content." );
		$this->assertStringNotContainsString( 'notion-page-id', $body, "$why: no connected Notion fields." );
		$this->assertNotContains( 'api.notion.com', $this->hosts(), "$why: no Notion API request." );
		$this->assertNotContains( 'outpost_creds_notion', $this->credential_reads, "$why: the Notion credential must not be read." );
		foreach ( $this->outbound as $request ) {
			$this->assertSame( '', $request['authorization'], "$why: no outbound request may carry credentials ({$request['url']})." );
		}
	}

	private static function fill( string $pattern ): string {
		$home         = wp_parse_url( home_url() );
		$scheme       = (string) $home['scheme'];
		$host         = (string) $home['host'];
		$port         = isset( $home['port'] ) ? ':' . $home['port'] : '';
		$other_scheme = ( 'https' === $scheme ? 'http' : 'https' ) . '://' . $host . $port . '/post/';
		$other_port   = $scheme . '://' . $host . ':' . ( isset( $home['port'] ) && 9999 !== (int) $home['port'] ? '9999' : '9998' ) . '/post/';
		return strtr(
			$pattern,
			array(
				'{origin}'       => $scheme . '://' . $host . $port,
				'{other-scheme}' => $other_scheme,
				'{other-port}'   => $other_port,
				'{scheme}'       => $scheme,
				'{host}'         => $host,
				'{port}'         => $port,
			)
		);
	}
}
