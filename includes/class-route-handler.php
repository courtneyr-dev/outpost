<?php
/**
 * Route handler for /post/*.
 *
 * Owns five rewrite rules:
 *
 *  - /post/manifest.json   → PWA manifest (JSON)
 *  - /post/sw.js           → service worker (JS)
 *  - /post/share-target/?  → Web Share Target endpoint (placeholder; Phase E)
 *  - /post/auth/callback/? → IndieAuth callback (placeholder; Phase B)
 *  - /post/?               → composer (PWA shell or install-prompt)
 *
 * Specific routes register first so they don't collide with the catch-all.
 * Every rule registers with the `top` flag so this ordering survives WP's
 * internal rule sort.
 *
 * Dispatch reads the `outpost_route` query var and hands off to
 * {@see Outpost_PWA_Shell}. The shell decides whether to render the composer
 * or the install-prompt page based on {@see outpost_is_ready()}.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Route_Handler {

	/**
	 * Query var that carries the matched route target.
	 */
	public const QUERY_VAR = 'outpost_route';

	/**
	 * Wire the WP hooks that activate routing on each request.
	 *
	 * Called from outpost.php at `plugins_loaded`.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );
		// Priority 1 so dispatch fires before redirect_canonical (priority 10),
		// otherwise WP would 302 our manifest/sw URLs into trailing-slash variants
		// before the route handler sees them.
		add_action( 'template_redirect', array( __CLASS__, 'dispatch' ), 1 );
	}

	/**
	 * The rule table — exposed so tests and the PWA install-prompt page can
	 * iterate it without poking at WP internals. Order matters: specific routes
	 * register before the /post/?$ catch-all.
	 *
	 * @return array<string, string> Map of regex pattern to query-var target value.
	 */
	public static function rules(): array {
		return array(
			'^post/manifest\.json$'  => 'manifest',
			// SW path has no .js extension on purpose — most managed-WP hosts
			// (GoDaddy, WP Engine, Kinsta) configure nginx to short-circuit
			// `.js` requests with a static-file lookup before WordPress runs,
			// which 404s our SW. Stripping the extension keeps the request in
			// WP's hands. The browser doesn't care about the script URL's
			// extension as long as the response is JavaScript.
			'^post/sw/?$'            => 'sw',
			'^post/share-target/?$'  => 'share-target',
			// iOS Shortcut bridge (Phase F6). Web Share Target API
			// never landed in iOS Safari; the Shortcut hits this
			// JSON endpoint instead.
			'^post/shortcut/?$'      => 'shortcut',
			'^post/auth/callback/?$' => 'auth-callback',
			'^post/?$'               => 'composer',
		);
	}

	/**
	 * Append the outpost_route query var to WP's allow-list.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public static function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Register every rule from {@see self::rules()}.
	 *
	 * Called on `init` and again on plugin activation (so the rewrite rules
	 * are present in the rule cache the moment the user lands on /post/).
	 */
	public static function register_rewrite_rules(): void {
		foreach ( self::rules() as $regex => $target ) {
			add_rewrite_rule(
				$regex,
				'index.php?' . self::QUERY_VAR . '=' . $target,
				'top'
			);
		}
	}

	/**
	 * Hand off to the right renderer for a matched Outpost rewrite rule.
	 *
	 * Hooked on template_redirect so WP's main query has already resolved,
	 * which means `$GLOBALS['wp']->matched_rule` holds the rewrite rule
	 * WordPress matched. The renderer target comes from that matched rule, not
	 * from the raw `outpost_route` query var — a public query var an arbitrary
	 * URL can set. Requests that matched no Outpost rule return silently; WP's
	 * own 404 handling catches URLs that matched nothing.
	 */
	public static function dispatch(): void {
		$route = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $route ) {
			return;
		}

		// `outpost_route` is a public query var, so any URL can carry
		// `?outpost_route=sw` without WordPress having matched one of our
		// rewrite rules. Rendering the shell / manifest / service worker from
		// that raw value lets an arbitrary URL emit Outpost responses. Dispatch
		// only when WordPress actually matched one of OUR rewrite rules, and
		// only to the target that rule maps to — never to the attacker-supplied
		// query-var value.
		$rules        = self::rules();
		$matched_rule = isset( $GLOBALS['wp'], $GLOBALS['wp']->matched_rule )
			? (string) $GLOBALS['wp']->matched_rule
			: '';
		if ( '' === $matched_rule || ! isset( $rules[ $matched_rule ] ) ) {
			return;
		}
		$route = $rules[ $matched_rule ];

		switch ( $route ) {
			case 'manifest':
				Outpost_PWA_Shell::render_manifest();
				break;
			case 'sw':
				Outpost_PWA_Shell::render_service_worker();
				break;
			case 'share-target':
				// F6: try server-side dispatch first. Returns to fall through
				// to the PWA shell only when no share data is present.
				Outpost_Share_Target_Controller::handle_request();
				Outpost_PWA_Shell::render();
				break;
			case 'shortcut':
				// F6: iOS Shortcut bridge endpoint. Always halts after
				// handle_request — POST-only, JSON-in, redirect-out.
				Outpost_Shortcut_Controller::handle_request();
				break;
			case 'composer':
			case 'auth-callback':
				Outpost_PWA_Shell::render();
				break;
		}
	}
}
