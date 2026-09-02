<?php
/**
 * Outpost_Source_Notion (G3.5a — proof-of-concept consumer).
 *
 * Inbound capture for Notion page URLs. Validates the OAuth +
 * encryption foundation works end-to-end against a real provider.
 *
 * URL forms claimed (extracts trailing 32-hex page ID, with or without
 * dashes; Notion canonical URLs include the ID at the end of the slug):
 *
 *   - https://www.notion.so/{slug-with-id}
 *   - https://notion.so/{slug-with-id}
 *   - https://{workspace}.notion.site/{slug-with-id}
 *
 * On a successful match the adapter routes the user to bookmark mode
 * (default; the composer offers "use as quote" alternative). Page
 * fetch happens through the Notion Source's fetch_page() helper,
 * not via the F-phase og_tags extractor — Notion's API requires the
 * stored OAuth access token + the Notion-Version header.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Notion extends Outpost_Source_Base {

	public const ID = 'notion';

	/**
	 * Notion API base. v1 is current.
	 */
	private const API_BASE = 'https://api.notion.com/v1';

	/**
	 * 1-hour transient cache TTL for fetched Notion page payloads.
	 */
	private const CACHE_TTL = HOUR_IN_SECONDS;

	private const CACHE_PREFIX = 'outpost_notion_page_';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Notion', 'outpost-mobile-publishing' ),
			'host_patterns'    => array( 'notion.so', 'www.notion.so', '*.notion.site' ),
			'ambiguity'        => 'unambiguous',
			'mode'             => 'bookmark',
			'mode_options'     => null,
			'mode_default'     => null,
			'extractor'        => 'api_json',
			'recipe'           => array(
				'fetch_url' => '@source_url',
			),
			'mapping'          => array(
				'@source_url' => 'u-bookmark-of',
			),
			'h_entry_property' => 'u-bookmark-of',
			'auth_required'    => true,
			'tags_default'     => array( 'bookmark' ),
			'caveats'          => array(
				__( 'The user must share the Notion page with the Outpost integration in Notion (••• menu → Add connections → Outpost). Notion 404s on pages not shared with the integration.', 'outpost-mobile-publishing' ),
				__( 'Notion access tokens do not currently expire (per Notion docs). If the token is rejected, reconnect via Settings → OAuth.', 'outpost-mobile-publishing' ),
			),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Match Notion page URLs. Notion canonical URLs put the 32-char hex
	 * page ID at the trailing segment (with or without dashes).
	 *
	 * @param string $url URL the user shared.
	 */
	public function matches_url( string $url ): bool {
		if ( null === self::extract_page_id( $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( in_array( $host, array( 'notion.so', 'www.notion.so' ), true ) ) {
			return true;
		}
		// {workspace}.notion.site
		return ( strlen( $host ) > strlen( '.notion.site' ) )
			&& ( '.notion.site' === substr( $host, -strlen( '.notion.site' ) ) );
	}

	/**
	 * Extract the Notion page ID from a URL. Notion appends the 32-hex
	 * id (without dashes) to the end of the slug. Returns the dashless
	 * id when found, null otherwise.
	 *
	 * @param string $url URL the user shared.
	 * @return string|null 32-character lowercase hex page id, or null.
	 */
	public static function extract_page_id( string $url ): ?string {
		// Match a 32-hex tail after the final dash or slash.
		if ( preg_match( '~([0-9a-f]{32})(?:[/?#]|$)~i', $url, $m ) ) {
			return strtolower( $m[1] );
		}
		// Dashed UUID variant (less common in Notion URLs but possible):
		// 8-4-4-4-12.
		if ( preg_match( '~([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})~i', $url, $m ) ) {
			return strtolower( str_replace( '-', '', $m[1] ) );
		}
		return null;
	}

	/**
	 * Fetch a Notion page's content via the Notion REST API. Reads the
	 * stored OAuth credentials, hits /pages/{id} for metadata + the
	 * page's title-property, then /blocks/{id}/children for body
	 * content. Caches the assembled response for 1 hour.
	 *
	 * @since 0.1.69
	 *
	 * @param string $url     Notion page URL.
	 * @param int    $user_id User whose creds to use.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function fetch_page( string $url, int $user_id ) {
		$page_id = self::extract_page_id( $url );
		if ( null === $page_id ) {
			return new \WP_Error(
				'outpost_notion_invalid_url',
				__( 'No Notion page ID found in URL.', 'outpost-mobile-publishing' )
			);
		}
		// Credentials FIRST, before any cache read. A Notion page is private to
		// the connection that can see it, so the requesting user must have their
		// own credential before they may be served even a cached copy — a
		// disconnected user gets an error, never another user's cached page.
		$creds = Outpost_Credentials_Store::get( 'notion', $user_id );
		if ( null === $creds || empty( $creds['access_token'] ) ) {
			return new \WP_Error(
				'outpost_notion_not_connected',
				__( 'Notion is not connected. Connect via Settings → OAuth.', 'outpost-mobile-publishing' )
			);
		}
		$token = (string) $creds['access_token'];

		// The cache is scoped to the page AND the requesting user's specific
		// credential — one user's private page is never returned to another, and
		// a reconnect or token rotation invalidates the entry. See cache_key().
		$cache_key = self::cache_key( $page_id, $user_id, $token );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$page = self::api_get( '/pages/' . $page_id, $token );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$blocks = self::api_get( '/blocks/' . $page_id . '/children?page_size=100', $token );
		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}
		$result = array(
			'id'     => $page_id,
			'page'   => $page,
			'blocks' => is_array( $blocks['results'] ?? null ) ? $blocks['results'] : array(),
		);
		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Cache key for a Notion page, scoped so one user's private page can never
	 * be served to another.
	 *
	 * Keyed on the page id, the requesting user id, AND a non-secret fingerprint
	 * of that user's current access token. The user id keeps two connected users
	 * apart; the token fingerprint invalidates the entry on a reconnect or token
	 * rotation. The raw token is never placed in the key — only a truncated
	 * SHA-256 of it (a hash cannot be reversed to the credential, and appears in
	 * the options table).
	 *
	 * @param string $page_id 32-hex Notion page id.
	 * @param int    $user_id Requesting user.
	 * @param string $token   That user's Notion access token.
	 * @return string
	 */
	private static function cache_key( string $page_id, int $user_id, string $token ): string {
		return self::CACHE_PREFIX . $page_id . '_' . $user_id . '_' . substr( hash( 'sha256', $token ), 0, 16 );
	}

	/**
	 * GET helper for Notion's REST API.
	 *
	 * @param string $path  API path (e.g. '/pages/abc').
	 * @param string $token Access token.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function api_get( string $path, string $token ) {
		$response = wp_remote_get(
			self::API_BASE . $path,
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization'  => 'Bearer ' . $token,
					'Notion-Version' => Outpost_OAuth_Provider_Notion::NOTION_API_VERSION,
					'Accept'         => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'outpost_notion_transport',
				$response->get_error_message()
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $status ) {
			return new \WP_Error(
				'outpost_notion_page_not_shared',
				__( 'This Notion page has not been shared with Outpost. In Notion, click the ••• menu on the page → Add connections → Outpost.', 'outpost-mobile-publishing' )
			);
		}
		if ( 401 === $status || 403 === $status ) {
			return new \WP_Error(
				'outpost_notion_unauthorized',
				__( 'Notion rejected the access token. Reconnect Notion in Settings → OAuth.', 'outpost-mobile-publishing' )
			);
		}
		if ( $status < 200 || $status >= 300 ) {
			return new \WP_Error(
				'outpost_notion_http',
				/* translators: %d: HTTP status */
				sprintf( __( 'Notion API HTTP %d', 'outpost-mobile-publishing' ), $status ),
				array( 'status' => $status )
			);
		}
		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'outpost_notion_parse',
				__( 'Notion API returned non-JSON.', 'outpost-mobile-publishing' )
			);
		}
		return $decoded;
	}
}
