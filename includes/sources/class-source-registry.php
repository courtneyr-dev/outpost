<?php
/**
 * Outpost_Source_Registry
 *
 * Inbound-source registry. Mirrors `Outpost_Companion_Registry` from
 * F1 with the inbound direction.
 *
 * Concrete source adapters register themselves at boot
 * (typically via `outpost_sources_init` action — see Plugin.php
 * bootstrap). `Outpost_Source_Unknown` registers LAST so it's the
 * fallback in `find_for_url` lookups: registration order is the
 * priority ordering, and the unknown-fallback's `*` pattern would
 * otherwise eat every URL.
 *
 * Lookup semantics:
 *
 *   - `register()` validates each pattern in the source's
 *     capabilities()['host_patterns'] via Outpost_Source_Base::validate_pattern.
 *     Malformed patterns raise InvalidArgumentException at register
 *     time — the matcher never silently fails to match later.
 *
 *   - `all()` returns sources in registration order.
 *
 *   - `find_for_url()` walks `all()` and returns the first source
 *     whose `matches_url()` returns true. Source_Unknown's `*`
 *     pattern guarantees a non-null result as long as Source_Unknown
 *     is registered.
 *
 *   - `get_by_id()` returns a source by its `capabilities()['id']`
 *     for the preview endpoint's optional `source_id` parameter.
 *
 * The registry exposes per-source filter overrides through each
 * adapter's own `capabilities()` method (which applies
 * `outpost_source_capabilities`); the registry itself doesn't
 * filter — that's per F2's pattern with companions.
 *
 * Test seam: `reset_for_tests()` clears the registry so unit tests
 * can register their own fake sources without leaking state across
 * tests.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Registry {

	/**
	 * Registered sources, in registration order. `Outpost_Source_Unknown`
	 * is appended last during boot so it's the fallback.
	 *
	 * @var Outpost_Source_Base[]
	 */
	private static array $sources = array();

	/**
	 * Whether the boot-time defaults have been registered yet. Used
	 * to bootstrap on first access in cases where the boot action
	 * didn't fire (e.g. early plugin boot, certain test contexts).
	 *
	 * @var bool
	 */
	private static bool $bootstrapped = false;

	/**
	 * Register a source adapter.
	 *
	 * Validates each pattern in the source's capabilities() against
	 * `Outpost_Source_Base::validate_pattern`. A malformed pattern
	 * raises `\InvalidArgumentException` immediately so the failure
	 * is loud at registration time, not silent at match time.
	 *
	 * Sources MUST be registered with distinct ids; a duplicate id
	 * also raises `\InvalidArgumentException`.
	 *
	 * @param Outpost_Source_Base $source Source adapter.
	 *
	 * @throws \InvalidArgumentException When a pattern is malformed or the id is already registered.
	 */
	public static function register( Outpost_Source_Base $source ): void {
		$caps = $source->capabilities();
		$id   = isset( $caps['id'] ) && is_string( $caps['id'] ) ? $caps['id'] : '';
		if ( '' === $id ) {
			throw new \InvalidArgumentException(
				esc_html( 'Outpost_Source_Registry: source must declare a non-empty id in capabilities().' )
			);
		}
		foreach ( self::$sources as $existing ) {
			$existing_caps = $existing->capabilities();
			if ( ( $existing_caps['id'] ?? null ) === $id ) {
				throw new \InvalidArgumentException(
					esc_html( 'Outpost_Source_Registry: source id "' . $id . '" already registered.' )
				);
			}
		}
		$patterns = isset( $caps['host_patterns'] ) && is_array( $caps['host_patterns'] )
			? $caps['host_patterns']
			: array();
		foreach ( $patterns as $pattern ) {
			if ( ! is_string( $pattern ) ) {
				throw new \InvalidArgumentException(
					esc_html( 'Outpost_Source_Registry: host_patterns entries must be strings.' )
				);
			}
			Outpost_Source_Base::validate_pattern( $pattern );
		}
		self::$sources[] = $source;
	}

	/**
	 * All registered sources, in registration order.
	 *
	 * @return Outpost_Source_Base[]
	 */
	public static function all(): array {
		self::ensure_bootstrapped();
		return self::$sources;
	}

	/**
	 * First registered source whose `matches_url()` returns true.
	 *
	 * `Outpost_Source_Unknown`'s `*` pattern matches any URL, so
	 * this returns null only when Source_Unknown isn't registered
	 * (e.g. test contexts that explicitly omit it). Production
	 * boot always registers it.
	 *
	 * @param string $url URL the user shared.
	 * @return Outpost_Source_Base|null
	 */
	public static function find_for_url( string $url ): ?Outpost_Source_Base {
		self::ensure_bootstrapped();
		foreach ( self::$sources as $source ) {
			if ( $source->matches_url( $url ) ) {
				return $source;
			}
		}
		return null;
	}

	/**
	 * Lookup by stable id (capabilities()['id']). Returns null when
	 * no source matches.
	 *
	 * @param string $id Stable source id.
	 * @return Outpost_Source_Base|null
	 */
	public static function get_by_id( string $id ): ?Outpost_Source_Base {
		self::ensure_bootstrapped();
		foreach ( self::$sources as $source ) {
			$caps = $source->capabilities();
			if ( ( $caps['id'] ?? null ) === $id ) {
				return $source;
			}
		}
		return null;
	}

	/**
	 * Boot-time bootstrap. Registers `Outpost_Source_Unknown` last so
	 * it's the universal fallback. Concrete sources (F7+ Spotify,
	 * YouTube, etc.) register through their own boot hooks BEFORE
	 * this fallback ever runs — typically from an
	 * `outpost_sources_init` action callback. To preserve "Unknown
	 * registers last" without forcing every concrete source to know
	 * about this contract, we register Unknown lazily on first
	 * lookup. The `outpost_sources_init` action fires synchronously
	 * before the first `all()` / `find_for_url()` call, giving every
	 * concrete source a chance to register first.
	 */
	private static function ensure_bootstrapped(): void {
		if ( self::$bootstrapped ) {
			return;
		}
		self::$bootstrapped = true;
		/**
		 * Fires once at the first registry access so concrete source
		 * adapters can register themselves before
		 * `Outpost_Source_Unknown` lands as the trailing fallback.
		 *
		 * Subscribers should call `Outpost_Source_Registry::register()`
		 * for each source they own. Order matters: registration is
		 * priority. Don't register `Outpost_Source_Unknown` here —
		 * the registry adds it itself after this action settles.
		 */
		do_action( 'outpost_sources_init' );

		// Register the universal fallback if no callback already did.
		$has_unknown = false;
		foreach ( self::$sources as $existing ) {
			$caps = $existing->capabilities();
			if ( Outpost_Source_Unknown::ID === ( $caps['id'] ?? '' ) ) {
				$has_unknown = true;
				break;
			}
		}
		if ( ! $has_unknown ) {
			self::register( new Outpost_Source_Unknown() );
		}
	}

	/**
	 * Look up the right extractor instance for a given extractor
	 * type id. Returns null on unknown ids; callers handle the null
	 * (typically by surfacing a 501 to the client).
	 *
	 * @param string $extractor_id One of the F5-recognized extractor type ids.
	 * @return Outpost_Source_Extractor_Base|null
	 */
	public static function get_extractor( string $extractor_id ): ?Outpost_Source_Extractor_Base {
		switch ( $extractor_id ) {
			case 'oembed':
				return new Outpost_Source_Extractor_Oembed();
			case 'og_tags':
				return new Outpost_Source_Extractor_Og_Tags();
			case 'mf2':
				return new Outpost_Source_Extractor_Mf2();
			case 'rss':
				return new Outpost_Source_Extractor_Rss();
			case 'api_json':
				return new Outpost_Source_Extractor_Api_Json();
			case 'api_xml':
				return new Outpost_Source_Extractor_Api_Xml();
			case 'composite':
				return new Outpost_Source_Extractor_Composite();
		}
		return null;
	}

	/**
	 * Test seam. Production code never calls this. Resets the
	 * registry's internal state so unit tests can register their
	 * own fake sources without leaking across tests.
	 */
	public static function reset_for_tests(): void {
		self::$sources      = array();
		self::$bootstrapped = false;
	}

	/**
	 * Test seam. Sets the bootstrapped flag without firing
	 * `outpost_sources_init`. Used by tests that manually register
	 * sources via `do_action( 'outpost_sources_init' )` and need to
	 * mark the registry as already-bootstrapped so subsequent
	 * implicit `ensure_bootstrapped()` calls become no-ops.
	 *
	 * Without this seam, `reset_for_tests()` clears `$bootstrapped`
	 * to false, the manual `do_action()` populates `$sources` but
	 * NEVER touches `$bootstrapped`, and the next dispatch path's
	 * implicit `ensure_bootstrapped()` re-fires the action against
	 * an already-populated `$sources` — throwing
	 * `InvalidArgumentException: source id "..." already registered`.
	 *
	 * Production code never calls this. See
	 * `docs/dev/integration-test-gotchas.md` gotcha #12.
	 */
	public static function mark_bootstrapped_for_tests(): void {
		self::$bootstrapped = true;
	}
}
