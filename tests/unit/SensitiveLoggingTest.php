<?php
/**
 * Plugin logging is debug-only and never carries secrets (T10-S3-10, F-12).
 *
 * Every test runs in its own process so it can define WP_DEBUG its own way
 * (undefined, false, a truthy non-boolean, true). PHP's error_log() is
 * pointed at a temporary file, so the assertions read exactly what the
 * plugin wrote. The OAuth token exchange is driven with synthetic secrets
 * in every place one could leak from: the response body, the transport
 * error message, nested error data (headers), the client secret, and the
 * authorization code.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use WP_Mock;
use WP_Mock\Tools\TestCase;

final class SensitiveLoggingTest extends TestCase {

	private const SECRETS = array(
		'SYNTHETIC-BODY-SECRET',
		'SYNTHETIC-MESSAGE-SECRET',
		'SYNTHETIC-HEADER-SECRET',
		'SYNTHETIC-CLIENT-SECRET',
		'SYNTHETIC-AUTH-CODE',
		'Bearer',
		'Authorization',
	);

	private string $log = '';

	public function setUp(): void {
		parent::setUp();
		WP_Mock::setUp();
		$this->log = (string) tempnam( sys_get_temp_dir(), 'outpost-log-' );
		ini_set( 'log_errors', '1' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		ini_set( 'error_log', $this->log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		WP_Mock::userFunction( 'rest_url' )->andReturn( 'https://site.example/wp-json/outpost/v1/oauth/fixture/callback' );
		WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing(
			static fn( $r ) => is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0
		);
		WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturnUsing(
			static fn( $r ) => is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''
		);
		WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( static fn( $v ) => $v instanceof \WP_Error );
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
		if ( is_file( $this->log ) ) {
			unlink( $this->log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		parent::tearDown();
	}

	private function logged(): string {
		clearstatcache();
		return is_file( $this->log ) ? (string) file_get_contents( $this->log ) : '';
	}

	private function provider(): \Outpost_OAuth_Provider_Base {
		return new class() extends \Outpost_OAuth_Provider_Base {
			public function id(): string {
				return 'fixture';
			}
			public function label(): string {
				return 'Fixture';
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
				return 'fixture-client';
			}
			public function client_secret(): string {
				return 'SYNTHETIC-CLIENT-SECRET';
			}
		};
	}

	private function http_failure(): void {
		WP_Mock::userFunction( 'wp_remote_post' )->andReturn(
			array(
				'response' => array( 'code' => 401 ),
				'headers'  => array( 'www-authenticate' => 'Bearer SYNTHETIC-HEADER-SECRET' ),
				'body'     => '{"error":"invalid_client","detail":"SYNTHETIC-BODY-SECRET"}',
			)
		);
	}

	private function transport_failure(): void {
		WP_Mock::userFunction( 'wp_remote_post' )->andReturn(
			new \WP_Error(
				'http_request_failed',
				'cURL error 7 SYNTHETIC-MESSAGE-SECRET',
				array(
					'headers' => array( 'Authorization' => 'Bearer SYNTHETIC-HEADER-SECRET' ),
					'body'    => 'SYNTHETIC-BODY-SECRET',
				)
			)
		);
	}

	private function assert_no_secrets( string $log ): void {
		foreach ( self::SECRETS as $secret ) {
			$this->assertStringNotContainsString( $secret, $log, "The log must never contain $secret." );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_nothing_is_logged_when_wp_debug_is_undefined(): void {
		$this->http_failure();
		$this->provider()->exchange_code( 'SYNTHETIC-AUTH-CODE' );
		$this->transport_failure();
		$this->provider()->exchange_code( 'SYNTHETIC-AUTH-CODE' );
		\Outpost_Debug_Log::write( 'a diagnostic' );

		$this->assertSame( '', $this->logged() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_nothing_is_logged_when_wp_debug_is_false(): void {
		define( 'WP_DEBUG', false );
		$this->http_failure();
		$this->provider()->exchange_code( 'SYNTHETIC-AUTH-CODE' );
		\Outpost_Debug_Log::write( 'a diagnostic' );

		$this->assertSame( '', $this->logged() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_nothing_is_logged_when_wp_debug_is_truthy_but_not_true(): void {
		define( 'WP_DEBUG', 1 );
		\Outpost_Debug_Log::write( 'a diagnostic' );

		$this->assertSame( '', $this->logged() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_oauth_http_failure_logs_provider_operation_and_status_only(): void {
		define( 'WP_DEBUG', true );
		$this->http_failure();

		$this->provider()->exchange_code( 'SYNTHETIC-AUTH-CODE' );

		$log = $this->logged();
		$this->assertStringContainsString( 'Outpost: OAuth fixture token exchange failed: HTTP 401', $log );
		$this->assert_no_secrets( $log );
		$this->assertStringNotContainsString( 'invalid_client', $log, 'No part of the response body.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_oauth_transport_failure_logs_the_error_code_only(): void {
		define( 'WP_DEBUG', true );
		$this->transport_failure();

		$this->provider()->exchange_code( 'SYNTHETIC-AUTH-CODE' );

		$log = $this->logged();
		$this->assertStringContainsString( 'Outpost: OAuth fixture token exchange failed: transport error http_request_failed', $log );
		$this->assert_no_secrets( $log );
		$this->assertStringNotContainsString( 'cURL error', $log, 'No part of the error message.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_approved_diagnostic_appears_on_one_bounded_line(): void {
		define( 'WP_DEBUG', true );
		\Outpost_Debug_Log::write( "line one\nline two " . str_repeat( 'x', 2000 ) );

		$log   = $this->logged();
		$lines = array_values( array_filter( explode( "\n", trim( $log ) ) ) );
		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( 'Outpost: line one line two', $log );
		$this->assertLessThan( 700, strlen( $lines[0] ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_error_codes_are_reduced_to_a_safe_token(): void {
		define( 'WP_DEBUG', true );
		$this->assertSame( 'http_request_failed', \Outpost_Debug_Log::error_code( new \WP_Error( 'http_request_failed', 'SYNTHETIC-MESSAGE-SECRET' ) ) );
		$this->assertSame( 'badcodescript', \Outpost_Debug_Log::error_code( new \WP_Error( 'bad code<script>', 'x' ) ) );
		$this->assertSame( 'unknown', \Outpost_Debug_Log::error_code( 'not an error' ) );
	}
}
