<?php
/**
 * Outpost_Fetch_Recent_REST (G-fetch-recent-picker).
 *
 * Composer primitive for "no shareable URL" platforms. Wellness
 * providers (Oura, WHOOP, Polar) don't expose URLs for individual
 * workouts / sleep sessions, so users can't paste-a-URL the way they
 * can for Notion or Ravelry. The Gutenberg sidebar picker calls into
 * this REST endpoint to fetch a list of recent items from a registered
 * provider; the user picks one and the picker inserts the item's
 * canonical payload into the editor.
 *
 * REST endpoint: GET /wp-json/outpost/v1/fetch-recent/<provider_id>?count=10
 *
 * Providers register declaratively via the `outpost_fetch_recent_providers`
 * filter. Each provider config:
 *
 *   [
 *       'label'          => string,                  // 'Oura'
 *       'callback'       => callable( int $count ): array<int,array>,
 *       'capability'     => string,                  // 'edit_posts' default
 *       'oauth_provider' => ?string,                 // null = no auth
 *   ]
 *
 * Each item the callback returns must follow the canonical shape:
 *
 *   [
 *       'id'           => string,
 *       'title'        => string,
 *       'subtitle'     => ?string,
 *       'icon_url'     => ?string,
 *       'fetched_at'   => string (ISO 8601),
 *       'post_kind'    => string,
 *       'post_payload' => [
 *           'title'                  => string,
 *           'content'                => string,
 *           'post_meta'              => array<string,mixed>,
 *           'syndication_source_url' => ?string,
 *       ],
 *   ]
 *
 * @package Outpost
 * @since   0.1.79
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Fetch_Recent_REST {

	public const NAMESPACE_ROOT = 'outpost/v1';
	public const ROUTE_BASE     = '/fetch-recent/(?P<provider_id>[a-z0-9_-]+)';
	public const ROUTE_LIST     = '/fetch-recent-providers';

	public const REASON_NOT_CONNECTED = 'not_connected';
	public const REASON_AUTH_FAILED   = 'auth_failed';

	/**
	 * Hook the REST registration. Called once on plugins_loaded.
	 *
	 * @since 0.1.79
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the REST route. Called from rest_api_init.
	 *
	 * @since 0.1.79
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_ROOT,
			self::ROUTE_LIST,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_list_request' ),
				'permission_callback' => array( __CLASS__, 'permission_callback' ),
				'show_in_index'       => false,
			)
		);
		register_rest_route(
			self::NAMESPACE_ROOT,
			self::ROUTE_BASE,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_request' ),
				'permission_callback' => array( __CLASS__, 'permission_callback' ),
				'show_in_index'       => false,
				'args'                => array(
					'provider_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'count'       => array(
						'type'              => 'integer',
						'default'           => 10,
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Initial gate: route is private to logged-in users with edit_posts.
	 * Per-provider capability further refines inside handle_request.
	 *
	 * @since 0.1.79
	 */
	public static function permission_callback(): bool {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	/**
	 * List registered providers (id + label only — never callbacks).
	 * Used by the sidebar panel to render one button per provider.
	 *
	 * @since 0.1.79
	 *
	 * @return \WP_REST_Response
	 */
	public static function handle_list_request(): \WP_REST_Response {
		$providers = self::get_providers();
		$out       = array();
		foreach ( $providers as $id => $config ) {
			// Per-provider capability filter — only show buttons the user can actually use.
			$capability = isset( $config['capability'] ) ? (string) $config['capability'] : 'edit_posts';
			if ( ! current_user_can( $capability ) ) {
				continue;
			}
			$out[] = array(
				'id'             => $id,
				'label'          => (string) $config['label'],
				'oauth_provider' => isset( $config['oauth_provider'] ) ? (string) $config['oauth_provider'] : null,
			);
		}
		return new \WP_REST_Response( array( 'providers' => $out ), 200 );
	}

	/**
	 * Handle GET. Public for testability.
	 *
	 * @since 0.1.79
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_request( \WP_REST_Request $request ) {
		$provider_id = (string) $request->get_param( 'provider_id' );
		$count       = (int) $request->get_param( 'count' );
		$count       = max( 1, min( 50, $count ) );

		$providers = self::get_providers();
		if ( ! isset( $providers[ $provider_id ] ) ) {
			return new \WP_Error(
				'outpost_fetch_recent_unknown_provider',
				__( 'Unknown fetch-recent provider.', 'outpost-mobile-publishing' ),
				array( 'status' => 404 )
			);
		}
		$config = $providers[ $provider_id ];

		// Per-provider capability gate (default edit_posts).
		$capability = isset( $config['capability'] ) ? (string) $config['capability'] : 'edit_posts';
		if ( ! current_user_can( $capability ) ) {
			return new \WP_Error(
				'outpost_fetch_recent_forbidden',
				__( 'You do not have permission to use this picker.', 'outpost-mobile-publishing' ),
				array( 'status' => 403 )
			);
		}

		// Per-provider OAuth gate. When the provider declares an
		// oauth_provider id and the user hasn't connected, return a
		// 200-with-reason payload — non-error UX so the modal can
		// render a "Connect first" prompt cleanly.
		$oauth_provider = isset( $config['oauth_provider'] ) ? (string) $config['oauth_provider'] : '';
		if ( '' !== $oauth_provider && ! self::user_has_oauth_connection( $oauth_provider ) ) {
			return new \WP_REST_Response(
				array(
					'provider_id' => $provider_id,
					'items'       => array(),
					'reason'      => self::REASON_NOT_CONNECTED,
					'message'     => sprintf(
						/* translators: %s: provider label. */
						__( 'Connect %s in OAuth settings before using this picker.', 'outpost-mobile-publishing' ),
						(string) ( $config['label'] ?? $provider_id )
					),
				),
				200
			);
		}

		try {
			$items = self::resolve_items( $config, $count );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'transport_failed',
				sprintf(
					/* translators: 1: provider label, 2: error message. */
					__( "Couldn't reach %1\$s right now: %2\$s", 'outpost-mobile-publishing' ),
					(string) ( $config['label'] ?? $provider_id ),
					$e->getMessage()
				),
				array( 'status' => 503 )
			);
		}

		return new \WP_REST_Response(
			array(
				'provider_id' => $provider_id,
				'items'       => $items,
				'fetched_at'  => gmdate( 'c' ),
			),
			200
		);
	}

	/**
	 * Resolve the items array from the provider's callback. Coerces to
	 * the canonical shape, drops malformed entries, caps to $count.
	 *
	 * @since 0.1.79
	 *
	 * @param array<string,mixed> $config Provider config.
	 * @return array<int,array<string,mixed>>
	 */
	public static function resolve_items( array $config, int $count ): array {
		$callback = isset( $config['callback'] ) ? $config['callback'] : null;
		if ( ! is_callable( $callback ) ) {
			return array();
		}
		$raw = call_user_func( $callback, $count );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $entry ) {
			$normalized = self::normalize_item( $entry );
			if ( null !== $normalized ) {
				$out[] = $normalized;
			}
			if ( count( $out ) >= $count ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Coerce a raw item into the canonical shape, returning null if the
	 * required fields are missing.
	 *
	 * @since 0.1.79
	 *
	 * @param mixed $entry Raw entry from the provider's callback.
	 * @return array<string,mixed>|null
	 */
	public static function normalize_item( $entry ): ?array {
		if ( ! is_array( $entry ) ) {
			return null;
		}
		$id    = isset( $entry['id'] ) ? (string) $entry['id'] : '';
		$title = isset( $entry['title'] ) ? (string) $entry['title'] : '';
		if ( '' === $id || '' === $title ) {
			return null;
		}
		$payload = isset( $entry['post_payload'] ) && is_array( $entry['post_payload'] )
			? $entry['post_payload']
			: array();
		return array(
			'id'           => $id,
			'title'        => $title,
			'subtitle'     => isset( $entry['subtitle'] ) ? (string) $entry['subtitle'] : null,
			'icon_url'     => isset( $entry['icon_url'] ) ? (string) $entry['icon_url'] : null,
			'fetched_at'   => isset( $entry['fetched_at'] ) ? (string) $entry['fetched_at'] : gmdate( 'c' ),
			'post_kind'    => isset( $entry['post_kind'] ) ? (string) $entry['post_kind'] : 'note',
			'post_payload' => array(
				'title'                  => isset( $payload['title'] ) ? (string) $payload['title'] : $title,
				'content'                => isset( $payload['content'] ) ? (string) $payload['content'] : '',
				'post_meta'              => isset( $payload['post_meta'] ) && is_array( $payload['post_meta'] ) ? $payload['post_meta'] : array(),
				'syndication_source_url' => isset( $payload['syndication_source_url'] ) ? (string) $payload['syndication_source_url'] : null,
			),
		);
	}

	/**
	 * Get all registered fetch-recent providers, applying the
	 * `outpost_fetch_recent_providers` filter. Drops malformed entries.
	 *
	 * @since 0.1.79
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_providers(): array {
		/**
		 * Filter the registered fetch-recent providers.
		 *
		 * @since 0.1.79
		 *
		 * @param array<string,array<string,mixed>> $providers
		 */
		$providers = apply_filters( 'outpost_fetch_recent_providers', array() );
		if ( ! is_array( $providers ) ) {
			return array();
		}
		$valid = array();
		foreach ( $providers as $id => $config ) {
			if ( ! is_string( $id ) || '' === $id ) {
				continue;
			}
			if ( ! is_array( $config ) ) {
				continue;
			}
			if ( empty( $config['label'] ) || empty( $config['callback'] ) || ! is_callable( $config['callback'] ) ) {
				continue;
			}
			if ( empty( $config['capability'] ) ) {
				$config['capability'] = 'edit_posts';
			}
			$valid[ sanitize_key( $id ) ] = $config;
		}
		return $valid;
	}

	/**
	 * Check whether the current user has an active OAuth connection to
	 * the given provider. Defers to Outpost_Credentials_Store from G3.5a.
	 *
	 * @since 0.1.79
	 */
	public static function user_has_oauth_connection( string $oauth_provider ): bool {
		if ( ! class_exists( 'Outpost_Credentials_Store' ) ) {
			return false;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		$creds = Outpost_Credentials_Store::get( $oauth_provider, $user_id );
		return is_array( $creds ) && ! empty( $creds['access_token'] );
	}
}
