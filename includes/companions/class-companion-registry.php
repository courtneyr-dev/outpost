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
		'Outpost_Manual_Share_Adapter',
		'Outpost_Bridgy_Publish_Adapter',
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
	 * Aggregate composer feature slugs from every active adapter. The
	 * canonical answer to "what composer-level features does this site
	 * support right now?".
	 *
	 * Renamed from `all_active_capabilities()` in F2. The old name is
	 * gone — F2 reclaims `capabilities()` for the richer chip shape (see
	 * {@see Outpost_Companion_Base::capabilities()}). Slug aggregation
	 * is now spelt `all_active_feature_slugs()` in lockstep.
	 *
	 * @return string[] De-duplicated, alphabetically sorted.
	 */
	public static function all_active_feature_slugs(): array {
		$slugs = array();
		foreach ( self::active() as $adapter ) {
			foreach ( $adapter->feature_slugs() as $slug ) {
				$slugs[ $slug ] = true;
			}
		}
		$keys = array_keys( $slugs );
		sort( $keys );
		return $keys;
	}

	/**
	 * Syndicate-to chips registered companions contribute, optionally
	 * filtered by composer mode.
	 *
	 * Iterates active companions, calls each `capabilities()`, and
	 * returns those where `detected === true` and the requested mode
	 * appears in `accepts_modes` (when a mode is provided). The shape
	 * returned matches the companion's `capabilities()` shape — no
	 * projection here. Callers that need the Micropub plugin's
	 * `[uid, name]` shape (the {@see Outpost_Micropub_Bridges::merge_syndicate_chips}
	 * filter callback) project at the call site.
	 *
	 * Mode validation is fail-OPEN: an unrecognized mode returns every
	 * detected chip rather than zero. The Outpost composer always sends
	 * a known mode; defensive callers (third-party Micropub clients
	 * passing unknown modes through future filter extensions) get the
	 * full set so a typo doesn't silently hide all destinations.
	 *
	 * @param string|null $mode Optional composer mode to filter by
	 *                          ('note', 'photo', 'reply', etc.). Pass
	 *                          null or omit to get every detected chip.
	 *                          Unknown values fail-open (return all).
	 * @return array<int, array<string, mixed>> List of chip shapes per the `capabilities()` contract.
	 */
	public static function chips_for_mode( ?string $mode = null ): array {
		$chips = array();
		foreach ( self::active() as $adapter ) {
			foreach ( self::collect_chips_from_adapter( $adapter ) as $chip ) {
				if ( ! is_array( $chip ) ) {
					continue;
				}
				if ( true !== ( $chip['detected'] ?? false ) ) {
					continue;
				}
				if ( null === $mode || ! self::is_known_mode( $mode ) ) {
					$chips[] = $chip;
					continue;
				}
				$accepted = isset( $chip['accepts_modes'] ) && is_array( $chip['accepts_modes'] )
					? $chip['accepts_modes']
					: array();
				if ( in_array( $mode, $accepted, true ) ) {
					$chips[] = $chip;
				}
			}
		}
		return $chips;
	}

	/**
	 * Collect every chip an adapter contributes — both the single
	 * `capabilities()` chip (F2 contract) and the optional
	 * `platform_chips()` array (F9 umbrella-companion contract).
	 *
	 * F1+F2 syndication-target adapters implement only `capabilities()`.
	 * F9's `Outpost_Manual_Share_Adapter` implements `platform_chips()`
	 * to surface multiple chips (one per platform: Instagram, Facebook,
	 * X, etc.) under a single companion umbrella. Future umbrella
	 * adapters (F14 Bridgy Publish for the multi-network bridge) follow
	 * the same pattern.
	 *
	 * Adapters that implement neither contribute zero chips and are
	 * filtered out at the per-mode step. Calling `platform_chips()` is
	 * gated behind `method_exists()` so it stays optional — the abstract
	 * base does not declare it.
	 *
	 * @param Outpost_Companion_Base $adapter Active adapter.
	 * @return array<int, array<string,mixed>> Chip-shape arrays.
	 */
	private static function collect_chips_from_adapter( Outpost_Companion_Base $adapter ): array {
		$chips = array();
		$caps  = $adapter->capabilities();
		if ( is_array( $caps ) ) {
			$chips[] = $caps;
		}
		if ( method_exists( $adapter, 'platform_chips' ) ) {
			$platform = $adapter->platform_chips();
			if ( is_array( $platform ) ) {
				foreach ( $platform as $chip ) {
					$chips[] = $chip;
				}
			}
		}
		return $chips;
	}

	/**
	 * Composer modes Outpost ships. Used by the `chips_for_mode()`
	 * fail-open check — values outside this set are treated as unknown
	 * and bypass per-mode filtering. Filterable so future modes
	 * (Phase C extensions, Phase F adapters that need a new mode like
	 * `mood` or `acquisition`) extend the recognized set without core
	 * edits.
	 *
	 * @return string[]
	 */
	public static function known_modes(): array {
		$modes = array(
			'note',
			'photo',
			'gallery',
			'article',
			'listen',
			'watch',
			'read',
			'play',
			'checkin',
			'reply',
			'like',
			'repost',
			'bookmark',
		);
		/**
		 * Filter the list of composer modes Outpost recognizes for
		 * per-mode chip filtering. Adding a mode here makes
		 * `chips_for_mode()` honor that mode's `accepts_modes`
		 * intersection; values outside this set fail-open.
		 *
		 * @param string[] $modes Default mode list.
		 */
		$filtered = apply_filters( 'outpost_known_composer_modes', $modes );
		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $modes;
	}

	/**
	 * Whether a string is a recognized composer mode.
	 *
	 * @param string $mode Mode slug to check.
	 * @return bool True if recognized; false otherwise.
	 */
	private static function is_known_mode( string $mode ): bool {
		return in_array( $mode, self::known_modes(), true );
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
