<?php
/**
 * Outpost_Perfmatters_Defang
 *
 * Disables Perfmatters HTML output optimizations on Outpost's PWA
 * routes (/post/*). Perfmatters' Delay JavaScript and Lazy Load CSS
 * features postpone JS execution and CSS application until first user
 * interaction — both break the Outpost composer's first-paint mounting
 * (the bundle never runs, the styles never apply, the composer renders
 * unstyled and inert).
 *
 * Strategy: hook on `template_redirect` priority 0 — one tick before
 * Outpost_Route_Handler::dispatch (priority 1). When the resolved
 * route is one of Outpost's PWA routes, register __return_true on
 * every Perfmatters disable filter Perfmatters checks before applying
 * an optimization. This is a no-op when Perfmatters isn't installed.
 *
 * Site owners can extend the disable-filter list via the
 * `outpost_perfmatters_defang_filters` filter — useful when a future
 * Perfmatters version adds new optimizations Outpost should also
 * defang.
 *
 * @package Outpost
 * @since   0.1.70
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Perfmatters_Defang {

	/**
	 * Default filter names Perfmatters checks to skip an optimization.
	 * Adding `__return_true` on each disables that optimization for the
	 * current request.
	 *
	 * @var string[]
	 */
	private const DISABLE_FILTERS = array(
		'perfmatters_delay_js_disable',
		'perfmatters_lazy_load_disable',
		'perfmatters_lazy_load_css_disable',
		'perfmatters_disable_lazy_load_css',
		'perfmatters_remove_unused_css_disable',
		'perfmatters_disable_minify',
		'perfmatters_disable_combine',
		'perfmatters_disable_local_google_fonts',
		'perfmatters_disable_critical_css',
	);

	/**
	 * Hook registration. Called from outpost.php at plugins_loaded
	 * priority 5.
	 *
	 * @since 0.1.70
	 */
	public static function register(): void {
		// Priority 0 so our defang filters land BEFORE Outpost's route
		// dispatcher (priority 1) hands off to Outpost_PWA_Shell::render
		// and Perfmatters' own output-buffer hooks check the disable
		// filters during HTML emission.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_defang' ), 0 );
	}

	/**
	 * On Outpost PWA routes, register __return_true on every Perfmatters
	 * disable filter. No-op for non-PWA requests.
	 *
	 * @since 0.1.70
	 */
	public static function maybe_defang(): void {
		foreach ( self::disable_filters_for_request() as $filter ) {
			add_filter( $filter, '__return_true' );
		}
	}

	/**
	 * Resolve the list of Perfmatters disable-filter names that should
	 * fire on the current request. Empty array on non-Outpost routes;
	 * filtered list on Outpost routes. Pure function — no side effects —
	 * so unit tests can assert on the return value without mocking
	 * add_filter.
	 *
	 * @since 0.1.70
	 *
	 * @return string[]
	 */
	public static function disable_filters_for_request(): array {
		if ( ! self::is_outpost_route() ) {
			return array();
		}
		/**
		 * Filter the list of Perfmatters disable-filter names Outpost
		 * targets when defanging on PWA routes. Site owners can extend
		 * this list when Perfmatters adds new optimizations.
		 *
		 * @since 0.1.70
		 *
		 * @param string[] $filters Default disable-filter names.
		 */
		/** @var mixed $raw */
		$raw     = apply_filters( 'outpost_perfmatters_defang_filters', self::DISABLE_FILTERS );
		$filters = is_array( $raw ) ? $raw : array();
		$out     = array();
		foreach ( $filters as $f ) {
			if ( is_string( $f ) && '' !== $f ) {
				$out[] = $f;
			}
		}
		return $out;
	}

	/**
	 * True when the current request is an Outpost PWA route. Read via
	 * Outpost_Route_Handler::QUERY_VAR which dispatch() consults; non-
	 * empty values indicate one of `composer`, `auth-callback`,
	 * `share-target`, `manifest`, `sw`, `shortcut`.
	 *
	 * @since 0.1.70
	 */
	private static function is_outpost_route(): bool {
		$route = (string) get_query_var( Outpost_Route_Handler::QUERY_VAR );
		return '' !== $route;
	}
}
