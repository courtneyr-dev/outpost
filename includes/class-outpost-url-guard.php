<?php
/**
 * Outpost_Url_Guard — SSRF destination validation for user-supplied fetch URLs.
 *
 * `wp_safe_remote_get()` (via `wp_http_validate_url`) blocks loopback and the
 * RFC1918 private ranges, but leaves gaps a preview/geocode fetch can be
 * steered through:
 *
 *   - IPv4 link-local 169.254.0.0/16 — the cloud-metadata range
 *     (169.254.169.254 on AWS/GCP/Azure/DO).
 *   - CGNAT 100.64.0.0/10 — carrier and some internal fabrics.
 *   - IPv6 entirely — `wp_http_validate_url`'s check is IPv4-only, and
 *     `gethostbyname()` returns only A records, so an AAAA-only host slips
 *     through as "couldn't resolve, allow".
 *   - IPv4-mapped-IPv6 (`::ffff:169.254.169.254`) forms of any of the above.
 *
 * This guard rejects all of those, for both a literal-IP host and a hostname
 * resolved to every A/AAAA answer (so DNS rebinding to a mix that includes an
 * internal address fails closed). The classifier is pure; the resolver is a
 * filterable seam (`outpost_resolve_host_ips`) so tests inject answers without
 * real DNS.
 *
 * @package Outpost
 * @since   1.0.4
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Url_Guard {

	/**
	 * Whether an IP address string is in a range this plugin must never fetch.
	 *
	 * Fails closed: an unparseable value is treated as blocked.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return bool True when the address must not be fetched.
	 */
	public static function is_blocked_ip( string $ip ): bool {
		$ip = trim( $ip );
		if ( '' === $ip ) {
			return true;
		}

		// Normalize an IPv4-mapped / -compatible IPv6 address to its IPv4 form
		// so `::ffff:169.254.169.254` (and the hex `::ffff:a9fe:a9fe`) are
		// classified as the IPv4 address they carry.
		$mapped = self::mapped_ipv4( $ip );
		if ( null !== $mapped ) {
			$ip = $mapped;
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return self::is_blocked_ipv4( $ip );
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return self::is_blocked_ipv6( $ip );
		}

		// Not a valid IP at all — fail closed.
		return true;
	}

	/**
	 * Whether a host must not be fetched — a literal internal IP, or a name
	 * that resolves to any blocked address.
	 *
	 * @param string $host Hostname or bracketed/plain IP literal.
	 * @return bool
	 */
	public static function host_is_blocked( string $host ): bool {
		$host = trim( $host );
		if ( '' === $host ) {
			return true;
		}
		// Strip IPv6 brackets: [::1] → ::1.
		if ( isset( $host[0] ) && '[' === $host[0] && ']' === substr( $host, -1 ) ) {
			$host = substr( $host, 1, -1 );
		}

		// Literal IP host: classify directly, no DNS.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_blocked_ip( $host );
		}

		$ips = self::resolve_host_ips( $host );
		if ( empty( $ips ) ) {
			// Could not prove the host is safe — fail closed.
			return true;
		}
		foreach ( $ips as $ip ) {
			if ( self::is_blocked_ip( (string) $ip ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve a hostname to every A and AAAA address, filterable for tests.
	 *
	 * @param string $host Hostname.
	 * @return string[] Resolved IP strings (may be empty).
	 */
	private static function resolve_host_ips( string $host ): array {
		$ips = array();

		// IPv4 A records. Silenced: gethostbynamel() emits an E_WARNING on an
		// unresolvable host, which is a normal outcome here (we fail closed on
		// an empty result), not an error to surface.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$a = @gethostbynamel( $host );
		if ( is_array( $a ) ) {
			$ips = array_merge( $ips, $a );
		}

		// IPv6 AAAA records.
		if ( function_exists( 'dns_get_record' ) ) {
			// Silenced for the same reason as gethostbynamel() above.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$aaaa = @dns_get_record( $host, DNS_AAAA );
			if ( is_array( $aaaa ) ) {
				foreach ( $aaaa as $record ) {
					if ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}

		$ips = array_values( array_unique( $ips ) );

		/**
		 * Filters the resolved IP addresses for a host before SSRF classification.
		 *
		 * Tests inject deterministic answers through this seam; production returns
		 * the real A/AAAA results. Return the complete set — the guard blocks the
		 * fetch if ANY returned address is internal.
		 *
		 * @since 1.0.4
		 *
		 * @param string[] $ips  Resolved IP strings.
		 * @param string   $host Hostname being resolved.
		 */
		$filtered = apply_filters( 'outpost_resolve_host_ips', $ips, $host );
		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : array();
	}

	/**
	 * Return the embedded IPv4 address of an IPv4-mapped/-compatible IPv6
	 * address, or null when `$ip` is not such an address.
	 */
	private static function mapped_ipv4( string $ip ): ?string {
		$lower = strtolower( $ip );
		// Dotted-quad form: ::ffff:169.254.169.254 or ::169.254.169.254.
		if ( preg_match( '/^::(ffff:)?(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/', $lower, $m ) ) {
			return $m[2];
		}
		// Hex form: ::ffff:a9fe:a9fe.
		if ( preg_match( '/^::ffff:([0-9a-f]{1,4}):([0-9a-f]{1,4})$/', $lower, $m ) ) {
			$hi = hexdec( $m[1] );
			$lo = hexdec( $m[2] );
			return sprintf( '%d.%d.%d.%d', ( $hi >> 8 ) & 0xFF, $hi & 0xFF, ( $lo >> 8 ) & 0xFF, $lo & 0xFF );
		}
		return null;
	}

	private static function is_blocked_ipv4( string $ip ): bool {
		$parts = array_map( 'intval', explode( '.', $ip ) );
		if ( 4 !== count( $parts ) ) {
			return true;
		}
		list( $a, $b ) = $parts;

		// Core-covered ranges (kept explicit so this stands alone).
		if ( 0 === $a || 127 === $a || 10 === $a ) {
			return true;
		}
		if ( 172 === $a && $b >= 16 && $b <= 31 ) {
			return true;
		}
		if ( 192 === $a && 168 === $b ) {
			return true;
		}
		// Gaps this guard adds.
		if ( 169 === $a && 254 === $b ) {          // 169.254.0.0/16 link-local.
			return true;
		}
		if ( 100 === $a && $b >= 64 && $b <= 127 ) { // 100.64.0.0/10 CGNAT.
			return true;
		}
		// Broader hygiene: multicast, reserved, and the 255.255.255.255
		// broadcast address all fall in 224.0.0.0/3 and above.
		if ( $a >= 224 ) {
			return true;
		}
		return false;
	}

	private static function is_blocked_ipv6( string $ip ): bool {
		$packed = inet_pton( $ip );
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return true;
		}
		// ::1 loopback and :: unspecified.
		if ( "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\1" === $packed
			|| "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0" === $packed ) {
			return true;
		}
		$b0 = ord( $packed[0] );
		$b1 = ord( $packed[1] );
		// fe80::/10 link-local.
		if ( 0xFE === $b0 && 0x80 === ( $b1 & 0xC0 ) ) {
			return true;
		}
		// fc00::/7 unique-local (fc.. and fd..).
		if ( 0xFC === ( $b0 & 0xFE ) ) {
			return true;
		}
		return false;
	}
}
