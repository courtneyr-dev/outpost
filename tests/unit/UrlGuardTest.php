<?php
/**
 * Unit tests for Outpost_Url_Guard::is_blocked_ip() — the pure IP classifier
 * that closes the preview/geocode SSRF ceiling.
 *
 * `wp_safe_remote_get()` (via `wp_http_validate_url`) blocks loopback and the
 * RFC1918 private ranges but NOT IPv4 link-local (169.254.0.0/16, the cloud
 * metadata range), CGNAT (100.64.0.0/10), IPv6 loopback/link-local/ULA, or
 * IPv4-mapped-IPv6 forms of any of those. This classifier rejects them all so
 * a user-supplied preview URL (or a redirect it follows) cannot reach internal
 * infrastructure. It is pure and does no I/O — DNS resolution is a separate,
 * integration-tested seam.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Url_Guard;

final class UrlGuardTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		\WP_Mock::setUp();
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
	}

	/**
	 * @dataProvider blocked_ips
	 */
	public function test_blocks_internal_and_link_local_addresses( string $ip ): void {
		$this->assertTrue( Outpost_Url_Guard::is_blocked_ip( $ip ), "$ip must be blocked" );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function blocked_ips(): array {
		return array(
			'loopback v4'             => array( '127.0.0.1' ),
			'loopback v4 range'       => array( '127.9.9.9' ),
			'private 10'              => array( '10.0.0.5' ),
			'private 172.16'          => array( '172.16.0.1' ),
			'private 172.31'          => array( '172.31.255.255' ),
			'private 192.168'         => array( '192.168.1.1' ),
			'link-local metadata'     => array( '169.254.169.254' ),
			'link-local range'        => array( '169.254.0.1' ),
			'cgnat low'               => array( '100.64.0.1' ),
			'cgnat high'              => array( '100.127.255.255' ),
			'zero network'            => array( '0.0.0.0' ),
			'this-host'               => array( '0.0.0.1' ),
			'ipv6 loopback'           => array( '::1' ),
			'ipv6 unspecified'        => array( '::' ),
			'ipv6 link-local'         => array( 'fe80::1' ),
			'ipv6 unique-local fc'    => array( 'fc00::1' ),
			'ipv6 unique-local fd'    => array( 'fd12:3456::1' ),
			'ipv4-mapped metadata'    => array( '::ffff:169.254.169.254' ),
			'ipv4-mapped loopback'    => array( '::ffff:127.0.0.1' ),
			'ipv4-mapped private'     => array( '::ffff:10.0.0.1' ),
			'ipv4-mapped hex'         => array( '::ffff:a9fe:a9fe' ),
			// Expanded spellings. inet_pton accepts these as the same
			// addresses as the compressed forms above; a text-pattern
			// classifier matched only the compressed ones and let these
			// through to the fetcher.
			'expanded mapped loopback' => array( '0:0:0:0:0:ffff:127.0.0.1' ),
			'expanded mapped metadata' => array( '0:0:0:0:0:ffff:169.254.169.254' ),
			'expanded mapped private'  => array( '0:0:0:0:0:ffff:10.0.0.5' ),
			'zero-padded mapped'       => array( '0000:0000:0000:0000:0000:ffff:192.168.1.1' ),
			'expanded mapped cgnat'    => array( '0:0:0:0:0:ffff:100.64.0.1' ),
			'ipv4-compatible loopback' => array( '::127.0.0.1' ),
			'nat64 loopback'           => array( '64:ff9b::127.0.0.1' ),
		);
	}

	/**
	 * @dataProvider allowed_ips
	 */
	public function test_allows_public_addresses( string $ip ): void {
		$this->assertFalse( Outpost_Url_Guard::is_blocked_ip( $ip ), "$ip must be allowed" );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function allowed_ips(): array {
		return array(
			'public v4 a'      => array( '93.184.216.34' ),
			'public v4 b'      => array( '8.8.8.8' ),
			'just-below cgnat' => array( '100.63.255.255' ),
			'just-above cgnat' => array( '100.128.0.0' ),
			'just-below ll'    => array( '169.253.255.255' ),
			'just-above ll'    => array( '169.255.0.0' ),
			'172.15 public'    => array( '172.15.0.1' ),
			'172.32 public'    => array( '172.32.0.1' ),
			'public v6'        => array( '2606:2800:220:1:248:1893:25c8:1946' ),
			'ipv4-mapped public' => array( '::ffff:93.184.216.34' ),
		);
	}

	public function test_garbage_is_blocked_fail_closed(): void {
		$this->assertTrue( Outpost_Url_Guard::is_blocked_ip( 'not-an-ip' ) );
		$this->assertTrue( Outpost_Url_Guard::is_blocked_ip( '' ) );
		$this->assertTrue( Outpost_Url_Guard::is_blocked_ip( '999.999.999.999' ) );
	}

	public function test_host_is_blocked_when_literal_ip_is_internal(): void {
		// A literal-IP host needs no DNS; classify it directly.
		$this->assertTrue( Outpost_Url_Guard::host_is_blocked( '169.254.169.254' ) );
		$this->assertTrue( Outpost_Url_Guard::host_is_blocked( '[::1]' ) );
		$this->assertTrue( Outpost_Url_Guard::host_is_blocked( '127.0.0.1' ) );
	}

	public function test_host_resolution_is_filterable_and_blocks_any_blocked_answer(): void {
		// The resolver seam: a hostname resolving to a mix where ANY address is
		// blocked must fail closed (DNS-rebinding defense).
		\WP_Mock::onFilter( 'outpost_resolve_host_ips' )
			->with( array(), 'rebind.example' )
			->reply( array( '93.184.216.34', '169.254.169.254' ) );

		$this->assertTrue( Outpost_Url_Guard::host_is_blocked( 'rebind.example' ) );
	}

	public function test_host_allowed_when_every_resolved_ip_is_public(): void {
		\WP_Mock::onFilter( 'outpost_resolve_host_ips' )
			->with( array(), 'good.example' )
			->reply( array( '93.184.216.34', '8.8.8.8' ) );

		$this->assertFalse( Outpost_Url_Guard::host_is_blocked( 'good.example' ) );
	}

	public function test_host_blocked_when_resolution_returns_nothing(): void {
		// No addresses → cannot prove the host is safe → fail closed.
		\WP_Mock::onFilter( 'outpost_resolve_host_ips' )
			->with( array(), 'nxdomain.example' )
			->reply( array() );

		$this->assertTrue( Outpost_Url_Guard::host_is_blocked( 'nxdomain.example' ) );
	}

	// =====================================================================
	// H9: resolve_safe_ip() — the address a caller must pin its connection
	// to, so a second DNS lookup at connect time cannot answer differently
	// (DNS rebinding) from the one this guard vetted.
	// =====================================================================

	public function test_resolve_safe_ip_returns_null_for_a_blocked_literal_ip(): void {
		$this->assertNull( Outpost_Url_Guard::resolve_safe_ip( '169.254.169.254' ) );
	}

	public function test_resolve_safe_ip_returns_the_literal_ip_when_public(): void {
		$this->assertSame( '93.184.216.34', Outpost_Url_Guard::resolve_safe_ip( '93.184.216.34' ) );
	}

	public function test_resolve_safe_ip_returns_the_first_resolved_address_when_all_are_public(): void {
		\WP_Mock::onFilter( 'outpost_resolve_host_ips' )
			->with( array(), 'good.example' )
			->reply( array( '93.184.216.34', '8.8.8.8' ) );

		$this->assertSame( '93.184.216.34', Outpost_Url_Guard::resolve_safe_ip( 'good.example' ) );
	}

	public function test_resolve_safe_ip_returns_null_when_any_resolved_address_is_blocked(): void {
		\WP_Mock::onFilter( 'outpost_resolve_host_ips' )
			->with( array(), 'rebind.example' )
			->reply( array( '93.184.216.34', '169.254.169.254' ) );

		$this->assertNull( Outpost_Url_Guard::resolve_safe_ip( 'rebind.example' ) );
	}

	public function test_resolve_safe_ip_returns_null_when_resolution_returns_nothing(): void {
		\WP_Mock::onFilter( 'outpost_resolve_host_ips' )
			->with( array(), 'nxdomain.example' )
			->reply( array() );

		$this->assertNull( Outpost_Url_Guard::resolve_safe_ip( 'nxdomain.example' ) );
	}

	/**
	 * Every spelling `inet_pton` accepts for one address must get the same
	 * verdict. The guard previously classified from the text form, so
	 * `::ffff:127.0.0.1` was blocked while `0:0:0:0:0:ffff:127.0.0.1` — the
	 * identical address — was fetched.
	 */
	public function test_notation_does_not_change_the_verdict(): void {
		$spellings = array(
			'::ffff:127.0.0.1',
			'0:0:0:0:0:ffff:127.0.0.1',
			'0000:0000:0000:0000:0000:ffff:127.0.0.1',
			'::ffff:7f00:1',
			'0:0:0:0:0:ffff:7f00:1',
		);
		$packed = array_map( 'inet_pton', $spellings );
		$this->assertCount( 1, array_unique( $packed ), 'Fixture error: these must be one address.' );

		foreach ( $spellings as $ip ) {
			$this->assertTrue(
				Outpost_Url_Guard::is_blocked_ip( $ip ),
				sprintf( 'Spelling %s of 127.0.0.1 must be blocked.', $ip )
			);
		}
	}
}
