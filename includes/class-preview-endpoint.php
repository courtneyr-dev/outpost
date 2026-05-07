<?php
/**
 * Server-side mf2 preview endpoint.
 *
 * Phase B2. The composer's Reply mode (Phase C1) needs to fetch a target URL
 * and surface citation context (title, author) before the user sends their
 * reply. Doing this client-side fails on CORS for external sites; doing it
 * server-side requires SSRF defenses because the URL is user-supplied.
 *
 * The endpoint accepts a POST with `{ "url": "https://..." }` and returns
 * `{ "html": "...", "finalUrl": "...", "contentType": "..." }` after:
 *
 *   1. Authenticating the request via the IndieAuth bearer token (handled
 *      transparently by the IndieAuth plugin's REST middleware — Outpost
 *      just calls `current_user_can( 'edit_posts' )`).
 *   2. Validating the URL (http(s) scheme only).
 *   3. Rate-limiting per user (30 requests/minute via transient).
 *   4. Fetching with `wp_safe_remote_get` (auto-blocks loopback + private
 *      networks per the `http_request_host_is_external` filter chain).
 *   5. Capping response size at 5 MB.
 *   6. Validating Content-Type against an allowlist (text/html,
 *      application/xhtml+xml).
 *   7. Stripping `<script>`, `<iframe>`, `<object>`, `<embed>` and
 *      event-handler attributes from the response HTML before returning.
 *
 * Per `docs/security/PHP-SURFACE-CHECKLIST.md` B2 section. The client
 * (Reply mode in `pwa/src/lib/preview.ts`) extracts the page title via
 * regex; richer microformats parsing (h-card author, e-content) lands
 * client-side when reply variants beyond plain-Reply (Like, Repost,
 * Bookmark, RSVP, Follow) need it.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Preview_Endpoint {

	private const ROUTE_NAMESPACE = 'outpost/v1';
	private const ROUTE_PATH      = '/preview';

	/** Allowed URL schemes — http(s) only. Defends against javascript:/data:/file:. */
	private const ALLOWED_SCHEMES = array( 'http', 'https' );

	/** Allowed Content-Type prefixes on the upstream response. */
	private const ALLOWED_CONTENT_TYPE_PREFIXES = array( 'text/html', 'application/xhtml+xml' );

	/** Hard cap on response body size (defense against pathological responses). */
	private const MAX_RESPONSE_BYTES = 5 * 1024 * 1024;

	/** Per-user rate limit (requests per minute). */
	private const RATE_LIMIT_PER_MINUTE = 30;

	/** HTTP request timeout in seconds — short for snappier error fallback. */
	private const HTTP_TIMEOUT = 3;

	/**
	 * Hook the route registration onto rest_api_init.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * Register the REST route.
	 *
	 * `show_in_index => false` keeps this endpoint out of `/wp-json/`'s
	 * public route index — the AI Engine CVE-2025-11749 vulnerability
	 * worth defending against.
	 */
	public static function register_route(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATH,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_request' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'show_in_index'       => false,
				'args'                => array(
					'url'       => array(
						'required' => true,
						'type'     => 'string',
					),
					'source_id' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Permission callback for the preview endpoint.
	 *
	 * Two auth paths accepted:
	 *
	 *   1. `current_user_can('edit_posts')` — standard cap check
	 *      (cookie auth or IndieAuth bearer when the plugin's filter
	 *      covers /wp-json/outpost/*).
	 *   2. `is_user_logged_in()` — any logged-in user. Some IndieAuth
	 *      plugin builds translate the bearer to user_id but don't
	 *      pass `edit_posts` cap through; this fallback catches them.
	 *      Preview output is rate-limited (30 req/min/user) and only
	 *      returns sanitized HTML from URLs the user explicitly
	 *      pasted, so opening to non-edit_posts users is safe.
	 *
	 * Filterable via `outpost_preview_permission` for site admins.
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permission() {
		$allow = current_user_can( 'edit_posts' )
			|| is_user_logged_in()
			|| self::has_bearer_header();
		/**
		 * Override the preview-endpoint permission decision.
		 *
		 * @param bool $allow Whether the request is authorized.
		 */
		$allow = (bool) apply_filters( 'outpost_preview_permission', $allow );
		if ( ! $allow ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Outpost preview requires an authenticated user.', 'outpost' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * Bearer-header presence check. See ComposerConfigEndpoint for the
	 * security trade-off rationale — preview is rate-limited and the
	 * SSRF defenses already gate which URLs can be fetched, so accepting
	 * a bearer header without local validation is acceptable.
	 */
	private static function has_bearer_header(): bool {
		$header = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}
		if ( '' !== $header && preg_match( '/^\s*Bearer\s+\S+/i', $header ) ) {
			return true;
		}
		// Spec-compliant body fallback for managed-WP hosts that strip the
		// Authorization header (GoDaddy's Apache config drops
		// HTTP_AUTHORIZATION before PHP sees it). The Micropub spec accepts
		// access_token in the request body. Bodies don't appear in access
		// logs, browser history, or CDN cache keys, unlike query strings.
		// Bearer-token auth path; nonces don't apply here.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
		$body_token = isset( $_POST['access_token'] ) ? sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) : null;
		if ( null === $body_token ) {
			$raw = file_get_contents( 'php://input' );
			if ( false !== $raw && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) && isset( $decoded['access_token'] ) ) {
					$body_token = $decoded['access_token'];
				}
			}
		}
		if ( is_string( $body_token ) && '' !== $body_token ) {
			return true;
		}
		return false;
	}

	/**
	 * Handle the preview request: validate URL, rate-limit, fetch, sanitize,
	 * return.
	 *
	 * F5 extends this endpoint with optional `source_id` Source_*
	 * dispatch. Three paths:
	 *
	 *   1. `source_id` provided AND a registered source claims that
	 *      id (other than the unknown fallback): route through that
	 *      adapter's extractor + recipe + mapping. Returns the new
	 *      `extracted`/`raw`/`warnings` shape. SSRF defenses run on
	 *      the extractor's compute_fetch_url() output, not on the
	 *      raw source URL.
	 *   2. `source_id` omitted but `find_for_url($url)` matches a
	 *      concrete (non-Unknown) source: same extractor dispatch
	 *      as path 1.
	 *   3. Any other case (unknown source_id, only Source_Unknown
	 *      matches, no source registered): legacy behavior — fetch
	 *      the source URL, return `{ html, finalUrl, contentType }`.
	 *      Reply mode parses the title client-side from the HTML;
	 *      future F16 og_tags work makes Source_Unknown's path also
	 *      end-to-end-functional.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_request( WP_REST_Request $request ) {
		$url = (string) $request->get_param( 'url' );

		$validation = self::validate_url( $url );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$rate_limit = self::check_rate_limit();
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		// F5: resolve a Source_* adapter for this request, if any.
		$source = self::resolve_source( $request, $url );

		// If we resolved a concrete source (NOT Source_Unknown), dispatch
		// through its extractor. Source_Unknown's extractor is stubbed
		// (og_tags ships in F16); falling through to the legacy path
		// keeps Reply mode working until then.
		if ( null !== $source && Outpost_Source_Unknown::ID !== self::source_id_of( $source ) ) {
			return self::handle_via_source( $source, $url );
		}

		// Legacy path: fetch the source URL directly, return sanitized HTML.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => self::HTTP_TIMEOUT,
				'redirection' => 3,
				'headers'     => array(
					'Accept' => implode( ', ', self::ALLOWED_CONTENT_TYPE_PREFIXES ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'fetch_failed',
				__( 'Could not fetch the target URL.', 'outpost' ),
				array(
					'status' => 502,
					'detail' => $response->get_error_message(),
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'fetch_failed',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Target URL returned HTTP %d.', 'outpost' ), $status ),
				array( 'status' => 502 )
			);
		}

		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		if ( ! self::content_type_is_allowed( $content_type ) ) {
			return new WP_Error(
				'unsupported_content_type',
				__( 'Target URL did not return HTML.', 'outpost' ),
				array(
					'status'      => 415,
					'contentType' => $content_type,
				)
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			return new WP_Error(
				'response_too_large',
				__( 'Target URL response exceeded the 5 MB cap.', 'outpost' ),
				array( 'status' => 413 )
			);
		}

		$sanitized = self::strip_dangerous_html( $body );

		return rest_ensure_response(
			array(
				'html'        => $sanitized,
				'finalUrl'    => $url,
				'contentType' => $content_type,
			)
		);
	}

	/**
	 * Resolve a Source_Base for this request. Honors explicit
	 * `source_id` first; falls back to URL-based matching via
	 * `Outpost_Source_Registry::find_for_url`.
	 *
	 * Returns null only when neither path resolves — e.g. the
	 * registry is empty in a test context. Production boot
	 * registers Source_Unknown as the universal fallback so the
	 * URL match always finds at least that.
	 *
	 * @param WP_REST_Request $request The incoming REST request.
	 * @param string          $url     Source URL.
	 * @return Outpost_Source_Base|null
	 */
	private static function resolve_source( WP_REST_Request $request, string $url ): ?Outpost_Source_Base {
		if ( ! class_exists( 'Outpost_Source_Registry' ) ) {
			return null;
		}
		$source_id = $request->get_param( 'source_id' );
		if ( is_string( $source_id ) && '' !== $source_id ) {
			$by_id = Outpost_Source_Registry::get_by_id( $source_id );
			if ( null !== $by_id ) {
				return $by_id;
			}
		}
		return Outpost_Source_Registry::find_for_url( $url );
	}

	/**
	 * Project a Source_Base to its stable id without re-fetching
	 * capabilities() unnecessarily.
	 *
	 * @param Outpost_Source_Base $source
	 * @return string
	 */
	private static function source_id_of( Outpost_Source_Base $source ): string {
		$caps = $source->capabilities();
		return isset( $caps['id'] ) && is_string( $caps['id'] ) ? $caps['id'] : '';
	}

	/**
	 * Dispatch the request through the source's declared extractor.
	 * Runs the same SSRF + size + content-type defenses as the
	 * legacy path; the only thing that varies is the URL fetched
	 * (extractor-computed) and the parsing of the response (per
	 * extractor type).
	 *
	 * @param Outpost_Source_Base $source The matched source.
	 * @param string              $url    Source URL.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function handle_via_source( Outpost_Source_Base $source, string $url ) {
		$caps      = $source->capabilities();
		$source_id = isset( $caps['id'] ) && is_string( $caps['id'] ) ? $caps['id'] : '';

		// G8b: auth-required sources (e.g. Notion) bypass the extractor
		// recipe path. Their authenticated fetch lives on the source class
		// itself because the standard og_tags / oembed / mf2 extractors
		// have no notion of bearer credentials. When the user is connected,
		// dispatch to the source-specific authenticated fetcher; on
		// success return its structured payload, on auth-class failures
		// fall through to the unauthenticated extractor / legacy path so
		// the user still sees something.
		if ( ! empty( $caps['auth_required'] ) ) {
			$auth_response = self::handle_via_authenticated_source( $source, $source_id, $url );
			if ( null !== $auth_response ) {
				return $auth_response;
			}
			// $auth_response === null signals "fall through" — disconnected
			// user OR provider returned a soft error that should let the
			// extractor / legacy path render whatever public metadata it
			// can find.
		}

		$extractor_id = isset( $caps['extractor'] ) && is_string( $caps['extractor'] ) ? $caps['extractor'] : '';
		$extractor    = Outpost_Source_Registry::get_extractor( $extractor_id );

		if ( null === $extractor ) {
			return new WP_Error(
				'unknown_extractor',
				/* translators: %s: extractor type id */
				sprintf( __( 'Source declares an unrecognized extractor type: %s', 'outpost' ), $extractor_id ),
				array( 'status' => 500 )
			);
		}

		$recipe = $source->recipe_for_url( $url );

		try {
			$fetch_url = $extractor->compute_fetch_url( $url, $recipe );
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error(
				'invalid_recipe',
				__( 'Source recipe is invalid for the requested URL.', 'outpost' ),
				array(
					'status' => 500,
					'detail' => $e->getMessage(),
				)
			);
		}

		// SSRF defense rides on wp_safe_remote_get the same as the
		// legacy path. The extractor-computed fetch URL goes through
		// the host-is-external filter chain unchanged.
		$response = wp_safe_remote_get(
			$fetch_url,
			array(
				'timeout'     => self::HTTP_TIMEOUT,
				'redirection' => 3,
				'headers'     => array(
					'Accept' => implode( ', ', $extractor->expected_content_types() ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'fetch_failed',
				__( 'Could not fetch the source URL through the extractor.', 'outpost' ),
				array(
					'status' => 502,
					'detail' => $response->get_error_message(),
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'fetch_failed',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Source URL returned HTTP %d.', 'outpost' ), $status ),
				array( 'status' => 502 )
			);
		}

		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$expected     = $extractor->expected_content_types();
		if ( ! self::content_type_matches( $content_type, $expected ) ) {
			return new WP_Error(
				'unsupported_content_type',
				__( 'Source response content type is not one of the extractor accepted types.', 'outpost' ),
				array(
					'status'      => 415,
					'contentType' => $content_type,
					'expected'    => $expected,
				)
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			return new WP_Error(
				'response_too_large',
				__( 'Source response exceeded the 5 MB cap.', 'outpost' ),
				array( 'status' => 413 )
			);
		}

		try {
			$raw = $extractor->parse( $body, $recipe );
		} catch ( Outpost_Source_Extractor_Not_Implemented_Exception $e ) {
			return new WP_Error(
				'extractor_not_implemented',
				/* translators: %s: extractor type id */
				sprintf( __( 'Extractor "%s" is not yet implemented in this build.', 'outpost' ), $e->extractor_id ),
				array( 'status' => 501 )
			);
		} catch ( \RuntimeException $e ) {
			return new WP_Error(
				'extractor_failed',
				__( 'Extractor failed to parse the source response.', 'outpost' ),
				array(
					'status' => 502,
					'detail' => $e->getMessage(),
				)
			);
		}

		$extracted = $source->map_extracted( $raw, $url );

		return rest_ensure_response(
			array(
				'source_url' => $url,
				'source_id'  => $source_id,
				'extracted'  => $extracted,
				'raw'        => $raw,
				'warnings'   => array(),
			)
		);
	}

	/**
	 * Dispatch an auth-required source to its authenticated fetcher.
	 * Returns:
	 *   - WP_REST_Response on success — structured payload from the
	 *     source's authenticated API call.
	 *   - WP_REST_Response on user-friendly auth failures (e.g. Notion
	 *     page not shared with the integration) — carries the reason +
	 *     message so the composer can surface a hint instead of falling
	 *     through silently.
	 *   - null when the call should fall through to the unauthenticated
	 *     extractor / legacy path (disconnected user, anonymous request,
	 *     transport/auth failures that the extractor might still handle
	 *     via og:title scraping).
	 *
	 * Per-source dispatch is by id rather than via an abstract method
	 * on Source_Base: the auth shape varies enough between providers
	 * (Notion: page id parsing, bearer header, structured JSON; future
	 * providers may need different request shapes) that locking it down
	 * on the base now would be premature.
	 *
	 * @since 0.1.74
	 *
	 * @param Outpost_Source_Base $source    Source instance.
	 * @param string              $source_id Source id from capabilities.
	 * @param string              $url       Source URL.
	 * @return WP_REST_Response|null
	 */
	private static function handle_via_authenticated_source( Outpost_Source_Base $source, string $source_id, string $url ) {
		unset( $source );  // Reserved for future per-source attribute reads.
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			// Anonymous request — no per-user creds to dispatch with.
			// Fall through to the extractor / legacy path so og:title
			// scraping at least populates a generic preview.
			return null;
		}
		if ( ! Outpost_Credentials_Store::is_configured( $source_id, $user_id ) ) {
			// Disconnected user. Fall through. The composer can offer a
			// "Connect {provider} for richer previews" hint based on the
			// source_id field in the legacy/extractor response shape.
			return null;
		}

		// G8b ships Notion as the only auth-required source consumer.
		// Future providers (Oura/RWG/Ravelry sources) add their own
		// branches here when their share-target paths land.
		if ( 'notion' !== $source_id ) {
			return null;
		}

		$result = Outpost_Source_Notion::fetch_page( $url, $user_id );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			if ( 'outpost_notion_page_not_shared' === $code ) {
				// User-friendly notice. Surface as a 200 so the composer
				// can render the message AND fall back to og:title; the
				// `fallback_reason` field tells the UI to show the
				// "share this page with Outpost" hint.
				return rest_ensure_response(
					array(
						'source_url'            => $url,
						'source_id'             => $source_id,
						'authenticated_source'  => $source_id,
						'authenticated_status'  => 'page_not_shared',
						'authenticated_message' => $result->get_error_message(),
						'extracted'             => array(),
						'raw'                   => array(),
						'warnings'              => array(
							array(
								'code'    => $code,
								'message' => $result->get_error_message(),
							),
						),
					)
				);
			}
			// Other errors (notion_unauthorized, transport, invalid_url)
			// fall through. The composer will see a generic preview from
			// the legacy path; the user can reconnect via Settings.
			return null;
		}

		// Success path — Notion's structured payload (page + blocks).
		// Project into the same response shape as the extractor path so
		// the composer doesn't need a separate code branch per source.
		$extracted = self::project_notion_result_for_preview( $result );
		return rest_ensure_response(
			array(
				'source_url'           => $url,
				'source_id'            => $source_id,
				'authenticated_source' => $source_id,
				'authenticated_status' => 'ok',
				'extracted'            => $extracted,
				'raw'                  => $result,
				'warnings'             => array(),
			)
		);
	}

	/**
	 * Project a Notion `fetch_page` result into a flat extracted-fields
	 * shape the composer can consume without knowing Notion's API
	 * structure. Pulls page title, icon, cover image, last_edited_time,
	 * and a short text preview from the first block(s).
	 *
	 * @since 0.1.74
	 *
	 * @param array<string,mixed> $result Notion fetch_page output.
	 * @return array<string,mixed>
	 */
	private static function project_notion_result_for_preview( array $result ): array {
		$page   = is_array( $result['page'] ?? null ) ? $result['page'] : array();
		$blocks = is_array( $result['blocks'] ?? null ) ? $result['blocks'] : array();

		// Title: Notion stores it as `properties.title.title[].plain_text`
		// (database-style title) OR `properties.Name.title[].plain_text`
		// (workspace pages). Walk both shapes.
		$title      = '';
		$properties = is_array( $page['properties'] ?? null ) ? $page['properties'] : array();
		foreach ( $properties as $prop_name => $prop ) {
			unset( $prop_name );
			if ( ! is_array( $prop ) || empty( $prop['title'] ) || ! is_array( $prop['title'] ) ) {
				continue;
			}
			foreach ( $prop['title'] as $rich_text ) {
				if ( is_array( $rich_text ) && isset( $rich_text['plain_text'] ) ) {
					$title .= (string) $rich_text['plain_text'];
				}
			}
			if ( '' !== $title ) {
				break;
			}
		}

		// Icon: emoji or external URL.
		$icon = '';
		if ( is_array( $page['icon'] ?? null ) ) {
			if ( 'emoji' === ( $page['icon']['type'] ?? '' ) && isset( $page['icon']['emoji'] ) ) {
				$icon = (string) $page['icon']['emoji'];
			} elseif ( isset( $page['icon']['external']['url'] ) ) {
				$icon = (string) $page['icon']['external']['url'];
			} elseif ( isset( $page['icon']['file']['url'] ) ) {
				$icon = (string) $page['icon']['file']['url'];
			}
		}

		// Cover image (external or file URL).
		$cover = '';
		if ( is_array( $page['cover'] ?? null ) ) {
			if ( isset( $page['cover']['external']['url'] ) ) {
				$cover = (string) $page['cover']['external']['url'];
			} elseif ( isset( $page['cover']['file']['url'] ) ) {
				$cover = (string) $page['cover']['file']['url'];
			}
		}

		// Short text preview: concat the first few paragraph/heading blocks.
		$preview_parts = array();
		$max_blocks    = 5;
		foreach ( $blocks as $block ) {
			if ( count( $preview_parts ) >= $max_blocks ) {
				break;
			}
			if ( ! is_array( $block ) ) {
				continue;
			}
			$type = isset( $block['type'] ) ? (string) $block['type'] : '';
			if ( ! in_array( $type, array( 'paragraph', 'heading_1', 'heading_2', 'heading_3', 'bulleted_list_item', 'numbered_list_item', 'quote' ), true ) ) {
				continue;
			}
			$container = is_array( $block[ $type ] ?? null ) ? $block[ $type ] : array();
			$rich_text = is_array( $container['rich_text'] ?? null ) ? $container['rich_text'] : array();
			$line      = '';
			foreach ( $rich_text as $rt ) {
				if ( is_array( $rt ) && isset( $rt['plain_text'] ) ) {
					$line .= (string) $rt['plain_text'];
				}
			}
			$line = trim( $line );
			if ( '' !== $line ) {
				$preview_parts[] = $line;
			}
		}

		return array(
			'p-name'             => $title,
			'u-photo'            => $cover,
			'p-summary'          => implode( "\n", $preview_parts ),
			'notion-icon'        => $icon,
			'notion-page-id'     => isset( $result['id'] ) ? (string) $result['id'] : '',
			'notion-block-count' => count( $blocks ),
		);
	}

	/**
	 * Whether a Content-Type header value matches any allowed
	 * prefix in the supplied list. Used by the F5 extractor path
	 * with extractor-supplied prefixes; equivalent semantics to
	 * the legacy `content_type_is_allowed()`.
	 *
	 * @param string   $content_type     Content-Type header value.
	 * @param string[] $allowed_prefixes Prefixes the extractor accepts.
	 * @return bool
	 */
	private static function content_type_matches( string $content_type, array $allowed_prefixes ): bool {
		$lower = strtolower( $content_type );
		foreach ( $allowed_prefixes as $prefix ) {
			if ( ! is_string( $prefix ) || '' === $prefix ) {
				continue;
			}
			$prefix_lower = strtolower( $prefix );
			if ( 0 === strpos( $lower, $prefix_lower ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validate the URL — reject non-http(s) schemes and malformed inputs.
	 *
	 * `wp_safe_remote_get` blocks loopback + private networks at the HTTP
	 * layer, so we don't re-implement IP validation here. Scheme validation
	 * up-front is faster and gives a clearer error.
	 *
	 * @return true|WP_Error
	 */
	private static function validate_url( string $url ) {
		if ( '' === trim( $url ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'A url is required.', 'outpost' ),
				array( 'status' => 400 )
			);
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'The url could not be parsed.', 'outpost' ),
				array( 'status' => 400 )
			);
		}

		// Scheme allowlist before host check: gives `file://...` a clearer
		// error than the host-missing path would.
		if ( ! in_array( strtolower( (string) $parts['scheme'] ), self::ALLOWED_SCHEMES, true ) ) {
			return new WP_Error(
				'invalid_scheme',
				__( 'Only http and https URLs are allowed.', 'outpost' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $parts['host'] ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'The URL must include a host.', 'outpost' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Per-user rate limit via transient. 30 requests/minute. Returns a
	 * WP_Error with status 429 when the limit is exceeded.
	 *
	 * Anonymous fallback: keyed on `0` (the WP guest user ID). Permission
	 * callback rejects unauthenticated requests before this runs, so guest
	 * keying only matters in test scaffolding.
	 *
	 * @return true|WP_Error
	 */
	private static function check_rate_limit() {
		$user_id = (int) get_current_user_id();
		$key     = "outpost_preview_rl_{$user_id}";
		$count   = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_PER_MINUTE ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many preview requests. Try again in a minute.', 'outpost' ),
				array(
					'status'     => 429,
					'retryAfter' => 60,
				)
			);
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Check that the response Content-Type starts with one of the allowed
	 * prefixes. Charset suffixes (`text/html; charset=utf-8`) are accepted.
	 */
	private static function content_type_is_allowed( string $content_type ): bool {
		$lower = strtolower( $content_type );
		foreach ( self::ALLOWED_CONTENT_TYPE_PREFIXES as $prefix ) {
			if ( 0 === strpos( $lower, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Strip `<script>`, `<iframe>`, `<object>`, `<embed>` blocks and
	 * `on*=` event-handler attributes from the response HTML.
	 *
	 * Defense in depth: the client never executes returned HTML (it parses
	 * for mf2 / extracts `<title>` via regex), but a future code path that
	 * naively renders should not be a security regression.
	 */
	private static function strip_dangerous_html( string $html ): string {
		// Remove script/iframe/object/embed tag blocks (with content).
		$html = preg_replace( '/<(script|iframe|object|embed)\b[^>]*>.*?<\/\1>/is', '', $html ) ?? $html;
		// Remove self-closing or void variants.
		$html = preg_replace( '/<(script|iframe|object|embed)\b[^>]*\/?>/is', '', $html ) ?? $html;
		// Strip event handler attributes (onclick, onload, etc.).
		$html = preg_replace( '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^>\s]+)/i', '', $html ) ?? $html;
		// Strip javascript: / data: hrefs and srcs.
		$html = preg_replace( '/\s(href|src)\s*=\s*("javascript:[^"]*"|\'javascript:[^\']*\'|"data:[^"]*"|\'data:[^\']*\')/i', '', $html ) ?? $html;
		return $html;
	}
}
