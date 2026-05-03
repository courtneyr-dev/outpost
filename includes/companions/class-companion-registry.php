<?php
/**
 * Companion registry (Phase F).
 *
 * Single source of truth for which companion adapters Outpost knows
 * about. Code that needs to ask "is xfn.relationships supported on
 * this site?" calls `Outpost_Companion_Registry::all_active_capabilities()`
 * and checks for the slug in the returned set.
 *
 * Per A1 #4 the adapter contract is `file() / label() / capabilities()`
 * + concrete `status() / is_active()`. The registry aggregates them.
 *
 * Adapters are constructed lazily and cached for the request — `get()`
 * returns the same instance on repeat calls so consumers can compare
 * by reference if they want.
 *
 * Filterable via `outpost_companion_adapters` so future plugins or
 * site-config code can register their own adapter classes without
 * forking core. The filter signature is
 * `(class-string<Outpost_Companion_Base>[]) => class-string<Outpost_Companion_Base>[]`.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Companion_Registry {

	/**
	 * Default adapter class list. Order matches the order the composer
	 * iterates them when it needs deterministic ordering (e.g. the
	 * onboarding "install these next" prompt).
	 *
	 * @var class-string<Outpost_Companion_Base>[]
	 */
	private const DEFAULT_ADAPTERS = array(
		'Outpost_Post_Kinds_Adapter',
		'Outpost_Post_Formats_Adapter',
		'Outpost_XFN_Adapter',
		'Outpost_Syndication_Links_Adapter',
		'Outpost_Yoast_Adapter',
		'Outpost_ActivityPub_Adapter',
		'Outpost_Accessibility_Checker_Adapter',
	);

	/**
	 * Per-request instance cache. Keyed by class name.
	 *
	 * @var array<string, Outpost_Companion_Base>
	 */
	private static array $instances = array();

	/**
	 * The resolved adapter class list, after filter application. Cached
	 * once per request; filter doesn't get re-applied on subsequent
	 * `all()` calls.
	 *
	 * @var class-string<Outpost_Companion_Base>[]|null
	 */
	private static ?array $resolved_classes = null;

	/**
	 * All registered adapter instances.
	 *
	 * @return Outpost_Companion_Base[]
	 */
	public static function all(): array {
		$classes = self::resolve_classes();
		$out     = array();
		foreach ( $classes as $adapter_class ) {
			$out[] = self::get( $adapter_class );
		}
		return $out;
	}

	/**
	 * Adapter instances whose underlying plugin is active.
	 *
	 * @return Outpost_Companion_Base[]
	 */
	public static function active(): array {
		return array_values(
			array_filter(
				self::all(),
				static fn( Outpost_Companion_Base $a ): bool => $a->is_active()
			)
		);
	}

	/**
	 * Aggregate capability slugs from every active adapter. The
	 * canonical answer to "what can this site do right now?".
	 *
	 * @return string[] De-duplicated, alphabetically sorted.
	 */
	public static function all_active_capabilities(): array {
		$caps = array();
		foreach ( self::active() as $adapter ) {
			foreach ( $adapter->capabilities() as $cap ) {
				$caps[ $cap ] = true;
			}
		}
		$keys = array_keys( $caps );
		sort( $keys );
		return $keys;
	}

	/**
	 * Look up a single adapter by class name (cached).
	 *
	 * @param class-string<Outpost_Companion_Base> $adapter_class Adapter class.
	 * @return Outpost_Companion_Base
	 */
	public static function get( string $adapter_class ): Outpost_Companion_Base {
		if ( isset( self::$instances[ $adapter_class ] ) ) {
			return self::$instances[ $adapter_class ];
		}
		$instance = new $adapter_class();
		if ( ! $instance instanceof Outpost_Companion_Base ) {
			throw new \RuntimeException(
				esc_html( $adapter_class . ' must extend Outpost_Companion_Base.' )
			);
		}
		self::$instances[ $adapter_class ] = $instance;
		return $instance;
	}

	/**
	 * Reset the per-request instance cache. Test hook only — production
	 * code never needs this.
	 */
	public static function reset_for_tests(): void {
		self::$instances        = array();
		self::$resolved_classes = null;
	}

	/**
	 * Apply the `outpost_companion_adapters` filter once per request and
	 * cache the result.
	 *
	 * @return class-string<Outpost_Companion_Base>[]
	 */
	private static function resolve_classes(): array {
		if ( null !== self::$resolved_classes ) {
			return self::$resolved_classes;
		}
		/**
		 * Filter the list of companion-adapter classes Outpost
		 * instantiates. Filter callers can append (or remove) classes
		 * to register additional companion plugins without forking
		 * core.
		 *
		 * @param class-string<Outpost_Companion_Base>[] $classes
		 */
		$classes = apply_filters( 'outpost_companion_adapters', self::DEFAULT_ADAPTERS );
		if ( ! is_array( $classes ) ) {
			$classes = self::DEFAULT_ADAPTERS;
		}
		// Validate each entry is a string class name pointing at a real
		// Outpost_Companion_Base subclass AND matches the naming
		// convention `Outpost_*_Adapter`. The naming convention is the
		// belt-and-suspenders defense: even if a hostile filter passes
		// `is_subclass_of()`, the class-name regex blocks adapter classes
		// from outside the Outpost namespace from being instantiated. The
		// `class_exists()` check already short-circuits autoloading to
		// avoid kicking off an arbitrary class's __construct via the SPL
		// autoloader chain.
		$valid = array();
		foreach ( $classes as $candidate_class ) {
			if ( ! is_string( $candidate_class ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/^Outpost_[A-Z][A-Za-z0-9_]*_Adapter$/', $candidate_class ) ) {
				continue;
			}
			if ( ! class_exists( $candidate_class, false )
				&& ! class_exists( $candidate_class, true )
			) {
				continue;
			}
			if ( ! is_subclass_of( $candidate_class, 'Outpost_Companion_Base' ) ) {
				continue;
			}
			$valid[] = $candidate_class;
		}
		self::$resolved_classes = $valid;
		return $valid;
	}
}
