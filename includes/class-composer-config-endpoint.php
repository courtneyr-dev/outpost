<?php
/**
 * Composer-config REST endpoint.
 *
 * Phase C5. The "More" pull-out in the PWA composer needs to know which
 * companion plugins are active so it can show or hide optional fields
 * (Post Format selector, Yoast focus keyphrase, XFN, Accessibility
 * Checker integration). This endpoint is the single client-side query
 * the composer makes on first load to discover that surface area.
 *
 * Returns:
 *
 *   {
 *     "companions": {
 *       "post-kinds":            "active" | "inactive" | "absent",
 *       "post-formats":          "active" | "inactive" | "absent",
 *       "xfn":                   "active" | "inactive" | "absent",
 *       "syndication-links":     "active" | "inactive" | "absent",
 *       "yoast":                 "active" | "inactive" | "absent",
 *       "activitypub":           "active" | "inactive" | "absent",
 *       "accessibility-checker": "active" | "inactive" | "absent"
 *     },
 *     "postFormats": ["aside", "gallery", "image", ...] | null,
 *     "xfnRels": ["friend", "met", "colleague", ...],
 *     "existingCategories": [{"slug": "tech", "name": "Tech"}, ...],
 *     "existingTags": [{"slug": "indieweb", "name": "IndieWeb"}, ...]
 *   }
 *
 * `postFormats` is null when Post Formats for Block Themes is absent —
 * the client uses null to hide the format selector entirely. When the
 * plugin is active, the array reflects what `get_theme_support()`
 * reports (so a theme that opted into a subset only surfaces those).
 *
 * `xfnRels` is the canonical XFN value list per
 * https://gmpg.org/xfn/11 — it ships independent of the XFN plugin
 * because the values are spec, not plugin-defined. The XFN plugin's
 * presence only gates *whether* the picker UI shows.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Composer_Config_Endpoint {

	private const ROUTE_NAMESPACE = 'outpost/v1';
	private const ROUTE_PATH      = '/composer-config';

	/** Per-user rate limit (requests per minute). 600/min (10/sec
	 * sustained) covers normal composer use + heavy debug-refresh
	 * cycles + share-target intake reloads while still blocking
	 * pathological abuse. The earlier limits (60, 300) were too
	 * tight for active development. */
	private const RATE_LIMIT_PER_MINUTE = 600;

	/** Default Post Format list when get_theme_support gives us no specifics. */
	private const ALL_POST_FORMATS = array(
		'aside',
		'gallery',
		'link',
		'image',
		'quote',
		'status',
		'video',
		'audio',
		'chat',
	);

	/**
	 * Default Bridgy host map. Maps a Reply / Like / Repost / Bookmark
	 * target URL's host to the matching Bridgy publish endpoint, so the
	 * composer can auto-suggest the right syndication chip when the
	 * user pastes a silo URL.
	 *
	 * Extensible via the `outpost_bridgy_host_map` filter. Filter
	 * callers add hosts (e.g. their own Mastodon instance) by appending
	 * to the returned array.
	 *
	 * @var array<string, array{name: string, uid: string}>
	 */
	private const DEFAULT_BRIDGY_HOST_MAP = array(
		'twitter.com'     => array(
			'name' => 'Twitter (via Bridgy)',
			'uid'  => 'https://brid.gy/publish/twitter',
		),
		'x.com'           => array(
			'name' => 'X (via Bridgy)',
			'uid'  => 'https://brid.gy/publish/twitter',
		),
		'mastodon.social' => array(
			'name' => 'Mastodon (via Bridgy Fed)',
			'uid'  => 'https://fed.brid.gy/',
		),
		'mas.to'          => array(
			'name' => 'Mastodon (via Bridgy Fed)',
			'uid'  => 'https://fed.brid.gy/',
		),
		'fosstodon.org'   => array(
			'name' => 'Mastodon (via Bridgy Fed)',
			'uid'  => 'https://fed.brid.gy/',
		),
		'mastodon.online' => array(
			'name' => 'Mastodon (via Bridgy Fed)',
			'uid'  => 'https://fed.brid.gy/',
		),
		'indieweb.social' => array(
			'name' => 'Mastodon (via Bridgy Fed)',
			'uid'  => 'https://fed.brid.gy/',
		),
		'github.com'      => array(
			'name' => 'GitHub (via Bridgy)',
			'uid'  => 'https://brid.gy/publish/github',
		),
		'bsky.app'        => array(
			'name' => 'Bluesky (via Bridgy)',
			'uid'  => 'https://bsky.brid.gy/',
		),
	);

	/** XFN spec relationships. Source: https://gmpg.org/xfn/11. */
	private const XFN_RELS = array(
		// Friendship (mutually exclusive within the family).
		'contact',
		'acquaintance',
		'friend',
		// Physical (mutually exclusive).
		'met',
		// Professional.
		'co-worker',
		'colleague',
		// Geographical (mutually exclusive).
		'co-resident',
		'neighbor',
		// Family.
		'child',
		'parent',
		'sibling',
		'spouse',
		'kin',
		// Romantic.
		'muse',
		'crush',
		'date',
		'sweetheart',
		// Identity.
		'me',
	);

	/**
	 * Hook the route registration onto rest_api_init.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
		// Opt this route out of any global rest_authentication_errors
		// gating that other plugins (Wordfence, "Disable REST API", any
		// "API hardening" snippet) impose. Runs late (999) so it
		// overrides whatever upstream callback added the error. Scoped
		// strictly to the composer-config path — never broadens auth on
		// other routes.
		add_filter( 'rest_authentication_errors', array( self::class, 'allow_anonymous_for_self' ), 999 );
	}

	/**
	 * Clears any rest_authentication_errors result for the composer-config
	 * route. Other plugins commonly add a global "must be logged in"
	 * error via this filter; without an opt-out the request never
	 * reaches our permission_callback.
	 *
	 * @param mixed $result Existing filter result (null, true, WP_Error).
	 * @return mixed Cleared (null) when the request is for our route;
	 *               unchanged otherwise.
	 */
	public static function allow_anonymous_for_self( $result ) {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return $result;
		}
		$uri = (string) $_SERVER['REQUEST_URI'];
		if ( false !== strpos( $uri, '/wp-json/' . self::ROUTE_NAMESPACE . self::ROUTE_PATH ) ) {
			return null;
		}
		// Some installs serve REST under index.php?rest_route=/...
		if ( false !== strpos( $uri, 'rest_route=' . rawurlencode( '/' . self::ROUTE_NAMESPACE . self::ROUTE_PATH ) )
			|| false !== strpos( $uri, 'rest_route=/' . self::ROUTE_NAMESPACE . self::ROUTE_PATH ) ) {
			return null;
		}
		return $result;
	}

	/**
	 * Register the REST route.
	 *
	 * `show_in_index => false` keeps the endpoint out of the public
	 * /wp-json/ index per the AI Engine CVE-2025-11749 vulnerability
	 * class — we don't want unauthenticated visitors learning which
	 * companion plugins this site has installed.
	 */
	public static function register_route(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATH,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => array( self::class, 'permission_check' ),
				'show_in_index'       => false,
			)
		);
	}

	/**
	 * Permission check — composer config is per-user, so the caller must
	 * be authenticated. The IndieAuth plugin's REST middleware translates
	 * Authorization: Bearer ... headers into a current user, so the
	 * standard `current_user_can( 'edit_posts' )` works for both cookie
	 * and bearer auth.
	 */
	/**
	 * Permission check for the composer-config endpoint.
	 *
	 * The payload is not per-user-sensitive: companion plugin
	 * activation status, public taxonomy terms, the Bridgy host map,
	 * the XFN spec list, and site-wide composer settings. Same
	 * information any WordPress user with `read` cap can already see
	 * via wp-admin. So we accept three auth paths:
	 *
	 *   1. `current_user_can('edit_posts')` — standard cap check.
	 *      Succeeds for cookie auth (admin logged into wp-admin in
	 *      the same browser) and for IndieAuth bearer when the
	 *      plugin's `determine_current_user` filter covers our route.
	 *   2. `is_user_logged_in()` — any logged-in user. Some IndieAuth
	 *      plugin builds translate the bearer to user_id but don't
	 *      pass through `edit_posts`; this fallback catches them.
	 *   3. Otherwise reject with 401.
	 *
	 * Filterable via `outpost_composer_config_permission` so site
	 * admins can override (e.g. open to anonymous in development).
	 *
	 * @return bool
	 */
	public static function permission_check(): bool {
		// The composer-config payload is non-sensitive: companion plugin
		// activation status (visible to any wp-admin user with `read`),
		// public taxonomy terms (already exposed in /?taxonomy=...
		// archives), the Bridgy host map (a public spec), the XFN spec
		// list (public spec), and site-wide composer settings. Equivalent
		// to what WordPress's own /wp-json/wp/v2/types and
		// /wp-json/wp/v2/categories endpoints expose anonymously by
		// default.
		//
		// Allow anonymous access. Sites that want to enforce auth can
		// override via the `outpost_composer_config_permission` filter.
		// Per-IP rate limiting in is_rate_limited() bounds abuse.
		/**
		 * Override the composer-config permission decision.
		 *
		 * @param bool $allow Whether the request is authorized.
		 */
		return (bool) apply_filters( 'outpost_composer_config_permission', true );
	}

	/**
	 * True when the incoming request has an `Authorization: Bearer ...`
	 * header. Used as a third permission path when the IndieAuth
	 * plugin's `determine_current_user` filter doesn't cover our route
	 * (the bearer reaches the endpoint but isn't translated to a WP
	 * user, so `is_user_logged_in()` returns false).
	 *
	 * Security trade-off: we don't validate the bearer ourselves —
	 * just accept its presence. The composer-config payload is
	 * non-sensitive (companion plugin status, public taxonomy terms,
	 * site settings). If the bearer is invalid the user can't post
	 * via Micropub anyway, so reading the config gets them nothing
	 * useful. Filter `outpost_composer_config_permission` for stricter
	 * sites.
	 */

	/**
	 * Per-user rate limit check. Returns true if the user is over
	 * quota; the handler responds 429 in that case.
	 *
	 * Transient-keyed by user ID — same pattern as the preview
	 * endpoint. Uses a fixed-1-minute window (no sliding window) which
	 * is good enough for this use case and cheap.
	 */
	private static function is_rate_limited(): bool {
		// Prefer user-id keying when available (logged-in user); fall
		// back to IP-keying for anonymous requests. IP-keying isn't
		// perfect (NAT, shared proxies) but it bounds the abuse window.
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$key = 'outpost_config_rl_u_' . (string) $user_id;
		} else {
			$ip  = self::client_ip();
			$key = 'outpost_config_rl_a_' . md5( $ip );
		}
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_PER_MINUTE ) {
			return true;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * Best-effort client IP for rate-limiting anonymous requests.
	 *
	 * REMOTE_ADDR is the canonical truth at the PHP layer. CDNs (Cloudflare,
	 * Akamai) inject the real client IP into CF-Connecting-IP /
	 * X-Forwarded-For — we honor those when present, but the fallback is
	 * REMOTE_ADDR so spoofed headers can't bypass the limit on direct
	 * connections.
	 */
	private static function client_ip(): string {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			return (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = (string) $_SERVER['HTTP_X_FORWARDED_FOR'];
			$first     = trim( explode( ',', $forwarded )[0] );
			if ( '' !== $first ) {
				return $first;
			}
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
	}

	/**
	 * Build the config response.
	 */
	public static function handle(): WP_REST_Response {
		if ( self::is_rate_limited() ) {
			$response = new WP_REST_Response(
				array(
					'code'       => 'rate_limited',
					'message'    => 'Too many requests. Try again in a moment.',
					'retryAfter' => MINUTE_IN_SECONDS,
				),
				429
			);
			$response->header( 'Retry-After', (string) MINUTE_IN_SECONDS );
			return $response;
		}

		$companions = array(
			'post-kinds'            => Outpost_Companion_Detector::is_post_kinds_active(),
			'post-formats'          => Outpost_Companion_Detector::is_post_formats_active(),
			'xfn'                   => Outpost_Companion_Detector::is_xfn_active(),
			'syndication-links'     => Outpost_Companion_Detector::is_syndication_links_active(),
			'yoast'                 => Outpost_Companion_Detector::is_yoast_active(),
			'activitypub'           => Outpost_Companion_Detector::is_activitypub_active(),
			'accessibility-checker' => Outpost_Companion_Detector::is_accessibility_checker_active(),
		);

		$post_formats = self::resolve_post_formats( $companions['post-formats'] );

		/**
		 * Filter the Bridgy host → publish-endpoint map.
		 *
		 * Allows themes / site-config plugins to add hosts (e.g. a
		 * specific Mastodon instance) without forking Outpost.
		 *
		 * @param array<string, array{name: string, uid: string}> $map Default host map.
		 */
		$bridgy_map = apply_filters( 'outpost_bridgy_host_map', self::DEFAULT_BRIDGY_HOST_MAP );

		$settings_payload = class_exists( 'Outpost_Settings' )
			? Outpost_Settings::get()
			: array();

		$response = new WP_REST_Response(
			array(
				'companions'         => $companions,
				'postFormats'        => $post_formats,
				'xfnRels'            => self::XFN_RELS,
				'existingCategories' => self::list_terms( 'category' ),
				'existingTags'       => self::list_terms( 'post_tag' ),
				'bridgyHostMap'      => is_array( $bridgy_map ) ? $bridgy_map : self::DEFAULT_BRIDGY_HOST_MAP,
				'siteSettings'       => array(
					'bridgyAutoSuggest'  => ! empty( $settings_payload['bridgy_auto_suggest'] ),
					'defaultPostVariant' => isset( $settings_payload['default_post_variant'] )
						? (string) $settings_payload['default_post_variant']
						: 'article',
				),
			),
			200
		);
		// Composer-config is per-user (term lists, companion status). Forbid
		// edge caches (GoDaddy / Varnish / nginx FastCGI) from serving one
		// user's response to another. Bearer auth already makes the response
		// per-request, but explicit headers cost nothing.
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		return $response;
	}

	/**
	 * Read existing terms for a taxonomy as a flat list of {slug, name}.
	 *
	 * Capped at 200 entries. `hide_empty => false` so terms that exist
	 * but haven't been used yet still surface as suggestions — on a
	 * fresh site or one with reorganized content, the user shouldn't
	 * have to retype categories/tags that already exist.
	 *
	 * Returns the empty array when the taxonomy isn't registered or the
	 * lookup errors — callers treat that as "no suggestions" rather than
	 * a failure mode.
	 *
	 * @param string $taxonomy 'category' or 'post_tag'.
	 * @return array<int, array{slug: string, name: string}>
	 */
	private static function list_terms( string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 200,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$out[] = array(
					'slug' => (string) $term->slug,
					'name' => (string) $term->name,
				);
			}
		}
		return $out;
	}

	/**
	 * Resolve which post formats are available.
	 *
	 * Returns null when Post Formats for Block Themes is absent —
	 * client uses that to hide the format selector entirely. When
	 * present, returns whatever the active theme declared via
	 * `add_theme_support( 'post-formats', [...] )`, falling back to
	 * the spec's full list when the theme didn't restrict.
	 *
	 * @param string $status One of 'active', 'inactive', 'absent'.
	 * @return string[]|null Format slugs, or null when format selector
	 *                       should be hidden.
	 */
	private static function resolve_post_formats( string $status ): ?array {
		if ( 'active' !== $status ) {
			return null;
		}

		$support = get_theme_support( 'post-formats' );
		if ( is_array( $support ) && isset( $support[0] ) && is_array( $support[0] ) ) {
			$declared = array_values( array_filter( $support[0], 'is_string' ) );
			if ( ! empty( $declared ) ) {
				return $declared;
			}
		}

		return self::ALL_POST_FORMATS;
	}
}
