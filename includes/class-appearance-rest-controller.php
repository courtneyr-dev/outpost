<?php
/**
 * Outpost_Appearance_REST_Controller
 *
 * REST endpoints for the FY-Theming subsystem:
 *
 *   GET  /wp-json/outpost/v1/appearance/tokens?mode=day|night
 *   POST /wp-json/outpost/v1/appearance/tokens
 *
 * GET returns the resolved token map for the requesting user at the
 * requested mode (day or night). The response includes the
 * `colors`, `fonts`, `sizes`, `sources` (so callers can show "from
 * theme" / "default" badges), and `adjusted` (records of any auto-
 * contrast adjustments — settings UI surfaces these as warnings).
 *
 * POST accepts a per-mode override payload, validates each color
 * against hex shape and each font-family string against a lenient
 * shape that rejects newline / control characters, merges into the
 * user's existing override-meta entry (per-token; non-clobbering),
 * invalidates the resolved-tokens transient cache, and returns the
 * newly-resolved token map so callers can update UI without a
 * second roundtrip.
 *
 * Both endpoints are gated on `edit_posts` so contributor-level
 * users can manage their own appearance preferences.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Appearance_REST_Controller {

	/** REST route registered under outpost/v1 namespace. */
	public const ROUTE = '/appearance/tokens';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			'outpost/v1',
			self::ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'handle_get' ),
					'permission_callback' => array( self::class, 'permission_check' ),
					'args'                => array(
						'mode' => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'day',
							'sanitize_callback' => 'sanitize_key',
						),
					),
					'show_in_index'       => false,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'handle_post' ),
					'permission_callback' => array( self::class, 'permission_check' ),
					'show_in_index'       => false,
				),
			)
		);
	}

	/**
	 * @return bool|\WP_Error
	 */
	public static function permission_check() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'outpost_appearance_unauthorized',
				__( 'Authentication required.', 'outpost-mobile-publishing' ),
				array( 'status' => 401 )
			);
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'outpost_appearance_forbidden',
				__( 'You do not have permission to manage appearance preferences.', 'outpost-mobile-publishing' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET handler. Returns resolved tokens for the current user at the
	 * requested mode. Mode 'system' resolves server-side to 'day' for
	 * the response payload (the client class controls
	 * actual day/night switching via prefers-color-scheme); the
	 * payload still indicates the user's stored mode preference so
	 * the UI knows which radio to highlight.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_get( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'outpost_appearance_no_user',
				__( 'Authentication required.', 'outpost-mobile-publishing' ),
				array( 'status' => 401 )
			);
		}

		$mode = self::normalize_mode_param( (string) $request->get_param( 'mode' ) );

		$resolved = Outpost_Token_Resolver::resolve( $user_id, $mode );

		return new \WP_REST_Response(
			array(
				'mode'            => $resolved['mode'],
				'mode_preference' => Outpost_Mode_Controller::get_mode( $user_id ),
				'colors'          => $resolved['colors'],
				'fonts'           => $resolved['fonts'],
				'sizes'           => $resolved['sizes'],
				'sources'         => $resolved['sources'],
				'adjusted'        => $resolved['adjusted'],
			),
			200
		);
	}

	/**
	 * POST handler. Accepts an override payload; validates each value;
	 * merges into the user's existing override meta; invalidates the
	 * cached resolved-tokens transient; returns the updated resolved
	 * map so the UI can immediately reflect the change.
	 *
	 * Payload shape:
	 *
	 *   {
	 *     "mode_preference": "day" | "night" | "system" | null,
	 *     "day":   { "colors": { "bg": "#abc" }, "fonts": { "body": "..." } },
	 *     "night": { ... same shape ... },
	 *     "bypass_contrast": ["text", "accent"]
	 *   }
	 *
	 * Any field omitted is left unchanged. To CLEAR a token (revert to
	 * theme/default fallback), pass an empty string for that token's
	 * value in the payload.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_post( \WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'outpost_appearance_no_user',
				__( 'Authentication required.', 'outpost-mobile-publishing' ),
				array( 'status' => 401 )
			);
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}
		if ( ! is_array( $body ) ) {
			return new \WP_Error(
				'outpost_appearance_invalid_body',
				__( 'Request body must be a JSON object.', 'outpost-mobile-publishing' ),
				array( 'status' => 400 )
			);
		}

		// Mode preference update is independent of override updates.
		// An empty string means "no change requested" — only validate
		// when the caller actually supplied a value.
		if ( isset( $body['mode_preference'] ) && is_string( $body['mode_preference'] ) ) {
			$mode_value = trim( $body['mode_preference'] );
			if ( '' !== $mode_value ) {
				$saved = Outpost_Mode_Controller::set_mode( $user_id, $mode_value );
				if ( ! $saved ) {
					return new \WP_Error(
						'outpost_appearance_invalid_mode',
						__( 'Invalid mode_preference value.', 'outpost-mobile-publishing' ),
						array( 'status' => 400 )
					);
				}
			}
		}

		$existing = self::read_overrides( $user_id );
		$updated  = $existing;

		foreach ( array( 'day', 'night' ) as $mode ) {
			if ( ! isset( $body[ $mode ] ) || ! is_array( $body[ $mode ] ) ) {
				continue;
			}
			$mode_payload = $body[ $mode ];
			$validated    = self::validate_mode_payload( $mode_payload );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
			$updated[ $mode ] = self::merge_mode_overrides(
				is_array( $existing[ $mode ] ?? null ) ? $existing[ $mode ] : array(),
				$validated
			);
		}

		if ( isset( $body['bypass_contrast'] ) ) {
			$bypass_validated = self::validate_bypass_list( $body['bypass_contrast'] );
			if ( is_wp_error( $bypass_validated ) ) {
				return $bypass_validated;
			}
			$updated['bypass_contrast'] = $bypass_validated;
		}

		update_user_meta( $user_id, Outpost_Token_Resolver::OVERRIDE_META_KEY, $updated );
		Outpost_Token_Resolver::invalidate_cache_for_user( $user_id );

		// Re-resolve at requested mode (or day default) to return the
		// up-to-date token map for the UI.
		$mode_param = self::normalize_mode_param( (string) $request->get_param( 'mode' ) );
		$resolved   = Outpost_Token_Resolver::resolve( $user_id, $mode_param );

		return new \WP_REST_Response(
			array(
				'saved'           => true,
				'mode'            => $resolved['mode'],
				'mode_preference' => Outpost_Mode_Controller::get_mode( $user_id ),
				'colors'          => $resolved['colors'],
				'fonts'           => $resolved['fonts'],
				'sizes'           => $resolved['sizes'],
				'sources'         => $resolved['sources'],
				'adjusted'        => $resolved['adjusted'],
			),
			200
		);
	}

	/**
	 * Validate a per-mode payload `{ colors: {...}, fonts: {...} }`.
	 * Returns the cleaned shape on success or WP_Error on rejection.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,array<string,string>>|\WP_Error
	 */
	private static function validate_mode_payload( array $payload ) {
		$out = array(
			'colors' => array(),
			'fonts'  => array(),
		);
		if ( isset( $payload['colors'] ) && is_array( $payload['colors'] ) ) {
			foreach ( $payload['colors'] as $slug => $value ) {
				$slug = (string) $slug;
				if ( ! self::is_valid_token_slug( $slug ) ) {
					return new \WP_Error(
						'outpost_appearance_invalid_color_slug',
						sprintf(
							/* translators: %s: rejected token slug. */
							__( 'Invalid color token slug: %s', 'outpost-mobile-publishing' ),
							$slug
						),
						array( 'status' => 400 )
					);
				}
				$value = is_string( $value ) ? trim( $value ) : '';
				if ( '' === $value ) {
					$out['colors'][ $slug ] = '';
					continue;
				}
				if ( ! self::is_valid_color_value( $value ) ) {
					return new \WP_Error(
						'outpost_appearance_invalid_color_value',
						sprintf(
							/* translators: 1: token slug, 2: rejected value. */
							__( 'Invalid color value for %1$s: %2$s', 'outpost-mobile-publishing' ),
							$slug,
							$value
						),
						array( 'status' => 400 )
					);
				}
				$out['colors'][ $slug ] = $value;
			}
		}
		if ( isset( $payload['fonts'] ) && is_array( $payload['fonts'] ) ) {
			foreach ( $payload['fonts'] as $slug => $value ) {
				$slug = (string) $slug;
				if ( ! self::is_valid_token_slug( $slug ) ) {
					return new \WP_Error(
						'outpost_appearance_invalid_font_slug',
						sprintf(
							/* translators: %s: rejected token slug. */
							__( 'Invalid font token slug: %s', 'outpost-mobile-publishing' ),
							$slug
						),
						array( 'status' => 400 )
					);
				}
				$value = is_string( $value ) ? trim( $value ) : '';
				if ( '' === $value ) {
					$out['fonts'][ $slug ] = '';
					continue;
				}
				if ( ! self::is_valid_font_value( $value ) ) {
					return new \WP_Error(
						'outpost_appearance_invalid_font_value',
						sprintf(
							/* translators: 1: token slug, 2: rejected value. */
							__( 'Invalid font value for %1$s: %2$s', 'outpost-mobile-publishing' ),
							$slug,
							$value
						),
						array( 'status' => 400 )
					);
				}
				$out['fonts'][ $slug ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Validate the bypass_contrast list — must be an array of token slugs.
	 *
	 * @param mixed $value
	 * @return string[]|\WP_Error
	 */
	private static function validate_bypass_list( $value ) {
		if ( ! is_array( $value ) ) {
			return new \WP_Error(
				'outpost_appearance_invalid_bypass',
				__( 'bypass_contrast must be a list of token slugs.', 'outpost-mobile-publishing' ),
				array( 'status' => 400 )
			);
		}
		$out = array();
		foreach ( $value as $slug ) {
			if ( ! is_string( $slug ) ) {
				continue;
			}
			$slug = trim( $slug );
			if ( '' !== $slug && self::is_valid_token_slug( $slug ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * Token slug shape: kebab- or snake_case alphanumerics. Defends
	 * against arbitrary keys leaking into the override-meta storage.
	 */
	private static function is_valid_token_slug( string $slug ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9_]*$/', $slug ) && strlen( $slug ) <= 32;
	}

	/**
	 * Color value shape: hex (#xxx, #xxxxxx, #xxxxxxxx) or rgba(...)
	 * or a CSS named color from a small allowlist of safe values.
	 * No urls(), no calc(), no expressions — those have no purpose in
	 * a color token and would expand the validation surface.
	 */
	private static function is_valid_color_value( string $value ): bool {
		$value = trim( $value );
		if ( 1 === preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) ) {
			return true;
		}
		if ( 1 === preg_match( '/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*[01](?:\.\d+)?\s*)?\)$/', $value ) ) {
			return true;
		}
		if ( in_array( strtolower( $value ), self::SAFE_NAMED_COLORS, true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Font-family value shape: comma-separated list of family names,
	 * each either a quoted string or a generic family. Reject control
	 * characters and CSS injection attempts.
	 */
	private static function is_valid_font_value( string $value ): bool {
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 256 ) {
			return false;
		}
		// Reject control chars + characters that have no place in a
		// font-family value but could close out the property.
		if ( 1 === preg_match( '/[\r\n\x00-\x1F\x7F;{}<>\\\\]/', $value ) ) {
			return false;
		}
		return true;
	}

	private static function normalize_mode_param( string $mode ): string {
		$mode = strtolower( trim( $mode ) );
		return Outpost_Mode_Controller::MODE_NIGHT === $mode
			? Outpost_Mode_Controller::MODE_NIGHT
			: Outpost_Mode_Controller::MODE_DAY;
	}

	/**
	 * Per-token merge: existing user-meta override for a mode is
	 * preserved; only the keys present in the request payload are
	 * updated. Empty-string value clears that token.
	 *
	 * @param array<string,mixed>            $existing
	 * @param array<string,array<string,string>> $incoming
	 * @return array<string,array<string,string>>
	 */
	private static function merge_mode_overrides( array $existing, array $incoming ): array {
		$out = array(
			'colors' => is_array( $existing['colors'] ?? null ) ? $existing['colors'] : array(),
			'fonts'  => is_array( $existing['fonts'] ?? null ) ? $existing['fonts'] : array(),
		);
		foreach ( array( 'colors', 'fonts' ) as $kind ) {
			foreach ( $incoming[ $kind ] ?? array() as $slug => $value ) {
				if ( '' === $value ) {
					unset( $out[ $kind ][ $slug ] );
					continue;
				}
				$out[ $kind ][ $slug ] = $value;
			}
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function read_overrides( int $user_id ): array {
		$value = get_user_meta( $user_id, Outpost_Token_Resolver::OVERRIDE_META_KEY, true );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Small allowlist of safe CSS named colors. The full named-color
	 * set has 147 entries; only common ones are allowed for token
	 * inputs to keep the validation surface narrow.
	 */
	private const SAFE_NAMED_COLORS = array(
		'transparent',
		'currentcolor',
		'black',
		'white',
		'red',
		'green',
		'blue',
		'yellow',
		'orange',
		'purple',
		'gray',
		'grey',
	);
}
