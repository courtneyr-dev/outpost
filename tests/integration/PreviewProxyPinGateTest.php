<?php
/**
 * A preview hop is refused before any request when a WordPress proxy would
 * route it (T10-S4-29, regression coverage for T10-S4-17).
 *
 * The SSRF guard vets an address and safe_fetch() pins curl to it. Through a
 * WordPress proxy the pin has no effect, so pin_applies() refuses the hop
 * before wp_safe_remote_get() runs. Proxy constants can't be undone inside
 * the shared test process, so this test runs safe_fetch() in a fresh PHP
 * process (tests/_helpers/preview-proxy-gate-probe.php) that defines them
 * before WordPress loads. The probe counts calls into WP_Http::request
 * (`http_request_args`) and transport attempts (`pre_http_request`).
 *
 * The no-proxy control in the same probe shows the harness sees a request
 * when one is allowed; the real-transport path stays covered by
 * PreviewFetchPolicyTest. The fsockopen branch of pin_applies() is not
 * covered here or anywhere: curl is installed in this environment, so
 * Requests never selects fsockopen.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class PreviewProxyPinGateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'ABSPATH' ) || ! is_file( ABSPATH . 'wp-load.php' ) || ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'Needs a WordPress install and proc_open.' );
		}
	}

	/** @return array<string, mixed> */
	private function probe( string $mode ): array {
		$root    = dirname( __DIR__, 2 );
		$command = array( PHP_BINARY, $root . '/tests/_helpers/preview-proxy-gate-probe.php', ABSPATH, $root . '/outpost.php', $mode );
		$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$out  = (string) stream_get_contents( $pipes[1] );
		$err  = (string) stream_get_contents( $pipes[2] );
		$code = proc_close( $process );
		$this->assertSame( 0, $code, "Probe exited non-zero: $err" );
		$json = json_decode( (string) substr( $out, (int) strpos( $out, '{' ) ), true );
		$this->assertIsArray( $json, "Probe output: $out $err" );
		return $json;
	}

	public function test_a_proxied_hop_is_refused_before_any_request(): void {
		$result = $this->probe( 'proxy' );

		$this->assertTrue( $result['proxy_enabled'], 'Control: the proxy constants took effect.' );
		$this->assertSame( 0, $result['http_request_args'], 'wp_safe_remote_get() is never reached.' );
		$this->assertSame( 0, $result['transport_attempts'], 'Nothing reaches the transport.' );
		$this->assertSame( 'error:fetch_failed', $result['result'], 'The generic fetch error.' );
	}

	public function test_without_a_proxy_the_same_probe_fetches(): void {
		$result = $this->probe( 'control' );

		$this->assertFalse( $result['proxy_enabled'] );
		$this->assertSame( 1, $result['transport_attempts'], 'Control: the harness sees an allowed request.' );
		$this->assertSame( 'response:200', $result['result'] );
	}
}
