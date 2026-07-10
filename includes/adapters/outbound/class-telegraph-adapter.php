<?php
/**
 * Outpost_Telegraph_Adapter (G9).
 *
 * POSSE-outbound adapter for Telegraph (https://telegra.ph/), the
 * anonymous publishing surface from the Telegram team. Distinctive
 * properties:
 *
 * - No OAuth. First call hits POST https://api.telegra.ph/createAccount
 *   with short_name + author_name + author_url. Returns access_token
 *   that the adapter stores per-user in user-meta.
 * - Pages are immutable URLs. POST /createPage returns a path; later
 *   edits via /editPage replace content but not URL.
 * - Limited tag set. Telegraph's DOM accepts a small whitelist:
 *   p, aside, blockquote, code, pre, figure, img, iframe (whitelist),
 *   h3, h4, ul, ol, li, a, b, em, s, u, br, hr. Anything else is
 *   stripped or downgraded.
 *
 * SCOPE for G9 (deferred items documented in PR description):
 * - Settings page UI: out of scope (no F-phase admin-page scaffolding
 *   for outbound adapters; the existing FX iOS Shortcut settings page
 *   is bespoke). Configure via WP CLI / direct user-meta for v1.
 * - Update path (editPage): the adapter stores `outpost_telegraph_page_path`
 *   per post; the actual editPage call is deferred to a follow-up.
 * - Pseudonymous per-post override: post-meta key
 *   `outpost_telegraph_author_name_override` + `outpost_telegraph_author_url_override`
 *   are read by the adapter. UI for setting them is deferred.
 * - Encrypted credential storage: F-phase has no encryption helper;
 *   tokens stored plain with a TODO comment to encrypt in a future
 *   security pass.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Telegraph_Adapter {

	private const API_BASE = 'https://api.telegra.ph';

	private const ACCESS_TOKEN_META_PREFIX = 'outpost_telegraph_access_token_user_';

	/**
	 * Provider id under which the access token lives in the encrypted
	 * credentials store. The META_PREFIX key above is the legacy
	 * plaintext location, kept only for one-time migration.
	 */
	private const CREDENTIALS_PROVIDER = 'telegraph';

	private const POST_URL_META = 'outpost_telegraph_post_url';

	private const POST_PATH_META = 'outpost_telegraph_page_path';

	private const SHORT_NAME_OPTION = 'outpost_telegraph_short_name';

	private const AUTHOR_NAME_OPTION = 'outpost_telegraph_author_name';

	private const AUTHOR_URL_OPTION = 'outpost_telegraph_author_url';

	private const SKIP_POST_META = '_outpost_skip_telegraph';

	/**
	 * Hook registration. Hooks transition_post_status at priority 20
	 * (after WP's own publish-state machinery).
	 *
	 * @since 0.1.69
	 */
	public static function register(): void {
		add_action(
			'transition_post_status',
			array( __CLASS__, 'maybe_syndicate_on_publish' ),
			20,
			3
		);
	}

	/**
	 * Post-publish hook handler. Filters out non-applicable transitions
	 * and dispatches to syndicate() for actual publishes.
	 *
	 * @since 0.1.69
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post object.
	 */
	public static function maybe_syndicate_on_publish( string $new_status, string $old_status, $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		/**
		 * Filter the post types that Telegraph syndicates.
		 *
		 * @param string[] $types Default ['post'].
		 */
		$allowed_types = apply_filters( 'outpost_telegraph_post_types', array( 'post' ) );
		if ( ! in_array( $post->post_type, (array) $allowed_types, true ) ) {
			return;
		}
		// Per-post skip override.
		if ( '1' === (string) get_post_meta( $post->ID, self::SKIP_POST_META, true ) ) {
			return;
		}
		// Already syndicated → defer to update path (out of G9 scope).
		if ( '' !== (string) get_post_meta( $post->ID, self::POST_URL_META, true ) ) {
			return;
		}
		self::syndicate( $post );
	}

	/**
	 * Syndicate a post to Telegraph. Resolves the user's access token
	 * (creating an account if necessary), converts the post body to
	 * Telegraph DOM, calls /createPage, and stores the resulting URL
	 * in post-meta.
	 *
	 * @since 0.1.69
	 *
	 * @param WP_Post $post Post object.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function syndicate( \WP_Post $post ) {
		$user_id = (int) $post->post_author;
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'outpost_telegraph_no_author', __( 'Post has no author.', 'outpost' ) );
		}

		$token = self::ensure_access_token( $user_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$blocks = parse_blocks( (string) $post->post_content );
		$dom    = self::convert_blocks_to_telegraph_dom( $blocks );
		if ( empty( $dom ) ) {
			$dom = array(
				array(
					'tag'      => 'p',
					'children' => array( __( '(empty)', 'outpost' ) ),
				),
			);
		}

		$author_name = (string) get_post_meta( $post->ID, 'outpost_telegraph_author_name_override', true );
		if ( '' === $author_name ) {
			$author_name = (string) get_option( self::AUTHOR_NAME_OPTION, '' );
		}
		$author_url = (string) get_post_meta( $post->ID, 'outpost_telegraph_author_url_override', true );
		if ( '' === $author_url ) {
			$author_url = (string) get_option( self::AUTHOR_URL_OPTION, '' );
			if ( '' === $author_url ) {
				$author_url = (string) get_permalink( $post->ID );
			}
		}

		$response = wp_remote_post(
			self::API_BASE . '/createPage',
			array(
				'timeout' => 10,
				'body'    => array(
					'access_token'   => $token,
					'title'          => (string) $post->post_title,
					'author_name'    => $author_name,
					'author_url'     => $author_url,
					'content'        => wp_json_encode( $dom ),
					'return_content' => 'false',
				),
			)
		);

		$parsed = self::parse_response( $response );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$result = $parsed['result'] ?? array();
		if ( ! is_array( $result ) || empty( $result['url'] ) ) {
			return new \WP_Error( 'outpost_telegraph_no_url', __( 'Telegraph response missing URL.', 'outpost' ) );
		}

		update_post_meta( $post->ID, self::POST_URL_META, (string) $result['url'] );
		if ( isset( $result['path'] ) ) {
			update_post_meta( $post->ID, self::POST_PATH_META, (string) $result['path'] );
		}

		return $result;
	}

	/**
	 * Get or create the user's Telegraph access token.
	 *
	 * @since 0.1.69
	 *
	 * @param int $user_id User ID.
	 * @return string|WP_Error
	 */
	public static function ensure_access_token( int $user_id ) {
		// Encrypted store first (Outpost_Credentials_Store, per-user scope).
		$creds = Outpost_Credentials_Store::get( self::CREDENTIALS_PROVIDER, $user_id );
		if ( is_array( $creds ) && ! empty( $creds['access_token'] ) ) {
			return (string) $creds['access_token'];
		}

		// One-time migration: earlier versions stored the token as plain
		// user meta. Move it into the encrypted store and delete the
		// plaintext copy only after the encrypted write succeeds.
		$key    = self::ACCESS_TOKEN_META_PREFIX . $user_id;
		$legacy = (string) get_user_meta( $user_id, $key, true );
		if ( '' !== $legacy ) {
			if ( Outpost_Credentials_Store::set( self::CREDENTIALS_PROVIDER, array( 'access_token' => $legacy ), $user_id ) ) {
				delete_user_meta( $user_id, $key );
			}
			return $legacy;
		}

		$short_name = (string) get_option( self::SHORT_NAME_OPTION, get_bloginfo( 'name' ) );
		$author     = (string) get_option( self::AUTHOR_NAME_OPTION, $short_name );
		$author_url = (string) get_option( self::AUTHOR_URL_OPTION, get_home_url() );

		$response = wp_remote_post(
			self::API_BASE . '/createAccount',
			array(
				'timeout' => 10,
				'body'    => array(
					'short_name'  => $short_name,
					'author_name' => $author,
					'author_url'  => $author_url,
				),
			)
		);
		$parsed   = self::parse_response( $response );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$result = $parsed['result'] ?? array();
		if ( ! is_array( $result ) || empty( $result['access_token'] ) ) {
			return new \WP_Error( 'outpost_telegraph_account_failed', __( 'Telegraph account creation returned no token.', 'outpost' ) );
		}
		$token = (string) $result['access_token'];
		if ( ! Outpost_Credentials_Store::set( self::CREDENTIALS_PROVIDER, array( 'access_token' => $token ), $user_id ) ) {
			return new \WP_Error( 'outpost_telegraph_token_store_failed', __( 'Telegraph token could not be stored securely.', 'outpost' ) );
		}
		return $token;
	}

	/**
	 * Convert Gutenberg block array → Telegraph DOM nodes. Limited tag
	 * set: p, h3, h4, blockquote, ul/ol/li, a, b/em, hr, code/pre,
	 * figure/img. Anything else stripped or downgraded.
	 *
	 * @since 0.1.69
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<int,array<string,mixed>> Telegraph DOM nodes.
	 */
	public static function convert_blocks_to_telegraph_dom( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$node = self::convert_block( $block );
			if ( null !== $node ) {
				if ( isset( $node[0] ) ) {
					// Multi-node return (e.g., a list block returns a single node, but handle both).
					foreach ( $node as $n ) {
						if ( is_array( $n ) ) {
							$out[] = $n;
						}
					}
				} else {
					$out[] = $node;
				}
			}
		}
		return $out;
	}

	/**
	 * Convert a single block. Returns null for unsupported / empty.
	 *
	 * @param array<string,mixed> $block Parsed block.
	 * @return array<string,mixed>|null
	 */
	private static function convert_block( array $block ): ?array {
		$name = (string) ( $block['blockName'] ?? '' );
		$html = trim( (string) ( $block['innerHTML'] ?? '' ) );

		switch ( $name ) {
			case 'core/paragraph':
				return self::text_block( 'p', $html );
			case 'core/heading':
				$level = isset( $block['attrs']['level'] ) ? (int) $block['attrs']['level'] : 2;
				// Telegraph supports only h3 + h4. Map h1/h2 → h3, h5/h6 → h4.
				$tag = ( $level <= 2 || 3 === $level ) ? 'h3' : 'h4';
				return self::text_block( $tag, $html );
			case 'core/quote':
				return self::text_block( 'blockquote', $html );
			case 'core/code':
				return array(
					'tag'      => 'pre',
					'children' => array(
						array(
							'tag'      => 'code',
							'children' => array( wp_strip_all_tags( $html ) ),
						),
					),
				);
			case 'core/separator':
				return array( 'tag' => 'hr' );
			case 'core/list':
				$ordered = ! empty( $block['attrs']['ordered'] );
				return self::list_block( $ordered ? 'ol' : 'ul', $html );
			case 'core/image':
				$src = '';
				if ( preg_match( '~<img[^>]+src="([^"]+)"~i', $html, $m ) ) {
					$src = $m[1];
				}
				if ( '' === $src ) {
					return null;
				}
				return array(
					'tag'      => 'figure',
					'children' => array(
						array(
							'tag'   => 'img',
							'attrs' => array( 'src' => $src ),
						),
					),
				);
			case 'core/embed':
				$url = (string) ( $block['attrs']['url'] ?? '' );
				if ( '' === $url ) {
					return null;
				}
				// Telegraph iframe support is restricted to YouTube / Vimeo / Twitter.
				$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				$ok   = false;
				foreach ( array( 'youtube.com', 'youtu.be', 'vimeo.com', 'twitter.com', 'x.com' ) as $allowed ) {
					if ( $host === $allowed || str_ends_with( $host, '.' . $allowed ) ) {
						$ok = true;
						break;
					}
				}
				if ( ! $ok ) {
					return null;
				}
				return array(
					'tag'   => 'iframe',
					'attrs' => array( 'src' => $url ),
				);
			case '':
				// Block separators / empty inner blocks.
				return null;
			default:
				// Unsupported block — drop. Future enhancement: allow filter
				// to inject a custom converter.
				if ( '' !== $html ) {
					// Best-effort plain-text fallback.
					$text = trim( wp_strip_all_tags( $html ) );
					if ( '' !== $text ) {
						return array(
							'tag'      => 'p',
							'children' => array( $text ),
						);
					}
				}
				return null;
		}
	}

	/**
	 * Build a text block ({tag, children: [text|child-nodes]}).
	 *
	 * @param string $tag  Telegraph tag.
	 * @param string $html Inner HTML.
	 * @return array<string,mixed>
	 */
	private static function text_block( string $tag, string $html ): array {
		// Strip the wrapping tag if present, since we inject our own.
		$inner = preg_replace( '~^<' . preg_quote( $tag, '~' ) . '\b[^>]*>(.*)</' . preg_quote( $tag, '~' ) . '>$~is', '$1', $html );
		if ( ! is_string( $inner ) ) {
			$inner = $html;
		}
		// Telegraph supports inline a/b/em/s/u/br; everything else strip
		// to text. wp_kses with a narrow allowlist handles this cleanly.
		$allowed = array(
			'a'  => array( 'href' => true ),
			'b'  => array(),
			'em' => array(),
			'i'  => array(),
			's'  => array(),
			'u'  => array(),
			'br' => array(),
		);
		$cleaned = wp_kses( (string) $inner, $allowed );
		return array(
			'tag'      => $tag,
			'children' => array( $cleaned ),
		);
	}

	/**
	 * Build a Telegraph list (ul / ol with li children).
	 *
	 * @param string $tag  'ul' or 'ol'.
	 * @param string $html Inner HTML containing <li> elements.
	 * @return array<string,mixed>
	 */
	private static function list_block( string $tag, string $html ): array {
		$items = array();
		if ( preg_match_all( '~<li\b[^>]*>(.*?)</li>~is', $html, $matches ) ) {
			foreach ( $matches[1] as $item ) {
				$items[] = array(
					'tag'      => 'li',
					'children' => array(
						wp_kses(
							(string) $item,
							array(
								'a'  => array( 'href' => true ),
								'b'  => array(),
								'em' => array(),
								'br' => array(),
							)
						),
					),
				);
			}
		}
		return array(
			'tag'      => $tag,
			'children' => $items,
		);
	}

	/**
	 * Parse a wp_remote_post response into the Telegraph result envelope.
	 *
	 * @param mixed $response wp_remote_post return.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'outpost_telegraph_transport',
				/* translators: %s: error message */
				sprintf( __( 'Telegraph transport error: %s', 'outpost' ), $response->get_error_message() )
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new \WP_Error(
				'outpost_telegraph_http',
				/* translators: %d: HTTP status */
				sprintf( __( 'Telegraph HTTP %d', 'outpost' ), $status ),
				array( 'status' => $status )
			);
		}
		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'outpost_telegraph_parse', __( 'Telegraph returned non-JSON.', 'outpost' ) );
		}
		if ( ! ( $decoded['ok'] ?? false ) ) {
			return new \WP_Error(
				'outpost_telegraph_api_error',
				(string) ( $decoded['error'] ?? __( 'Telegraph API error.', 'outpost' ) )
			);
		}
		return $decoded;
	}
}
