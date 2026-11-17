<?php
/**
 * Outpost_IOS_Shortcut_REST_Controller
 *
 * REST endpoint for the iOS Shortcut bridge: POST a JSON body
 * `{ url, shared_text? }` signed in with a WordPress Application Password,
 * get back a JSON response `{ redirect_url, source_id, mode }`. The iOS Shortcut
 * uses the redirect_url to navigate Safari to the composer.
 *
 * AUTH
 *
 * WordPress core authenticates the request: the Shortcut sends a
 * WordPress Application Password over HTTP Basic auth, and
 * `Outpost_IOS_Shortcut_App_Password` keeps an Outpost-created
 * password limited to this route. Outpost never sets the current
 * user itself. We require `edit_posts` so a Subscriber-level user
 * can't dispatch.
 *
 * This REST endpoint is the only Shortcut contract: JSON in, JSON out.
 * The earlier cookie-only `/post/shortcut` page route was removed in
 * 1.0.27.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_IOS_Shortcut_REST_Controller {

	use Outpost_Bearer_Auth;

	/**
	 * Hook registration. Called from outpost_init at REST init time.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route(): void {
		register_rest_route(
			'outpost/v1',
			'/shortcut',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_request' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'show_in_index'       => false,
				'args'                => array(
					'url'         => array(
						'type'              => 'string',
						'required'          => false,
						// Kindle / Apple Books / podcast clients bundle a
						// quote + title + URL into the share-sheet text
						// payload. iOS Shortcut authors typically wire
						// Shortcut Input into a single field (the `url`
						// field), so a multi-line text blob lands here
						// instead of a clean URL. sanitize_shared_url_field
						// returns a validated URL or that prose (T10-S3-13
						// v1.1); Outpost_Source_Detector::extract_url_from_payload
						// extracts the embedded link and validates it with
						// the URL-only sanitize_shared_url before use.
						//
						// None of the three args use sanitize_text_field
						// or sanitize_textarea_field: both delete %XX
						// octets, which rewrites an encoded link. A custom
						// sanitize_callback also replaces core's type
						// check, so an array can arrive here; the shared
						// sanitizers return '' for one.
						'sanitize_callback' => array( 'Outpost_Source_Detector', 'sanitize_shared_url_field' ),
					),
					'shared_text' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => array( 'Outpost_Source_Detector', 'sanitize_shared_text' ),
					),
					'title'       => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => array( 'Outpost_Source_Detector', 'sanitize_shared_text' ),
					),
				),
			)
		);
	}

	/**
	 * Permission gate. WordPress core has already authenticated the
	 * request; the Shortcut arrives with its application password over
	 * HTTP Basic. A cookie session must also carry a valid REST nonce,
	 * checked here rather than left to core alone, and a bearer token
	 * needs the `create` scope (T10-S4-14).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public static function permission_check( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() || ! self::bearer_has_scope( $request, array( 'create' ) ) ) {
			return new \WP_Error(
				'outpost_ios_shortcut_unauthorized',
				__( 'Authentication required.', 'outpost-mobile-publishing' ),
				array( 'status' => 401 )
			);
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'outpost_ios_shortcut_forbidden',
				__( 'You do not have permission to use the iOS Shortcut bridge.', 'outpost-mobile-publishing' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Handle the POST. Returns JSON with a redirect URL the Shortcut
	 * uses to navigate Safari to the composer.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_request( \WP_REST_Request $request ) {
		$url   = (string) $request->get_param( 'url' );
		$text  = (string) $request->get_param( 'shared_text' );
		$title = (string) $request->get_param( 'title' );

		$normalized = array(
			'url'   => $url,
			'text'  => $text,
			'title' => $title,
		);

		$resolved = Outpost_Source_Detector::extract_url_from_payload( $normalized );
		if ( null === $resolved ) {
			return new \WP_Error(
				'outpost_ios_shortcut_no_url',
				__( 'No URL detected in payload.', 'outpost-mobile-publishing' ),
				array( 'status' => 400 )
			);
		}

		$context = array(
			'method'    => 'POST',
			'platform'  => 'ios',
			'shortcut'  => true,
			'has_files' => false,
			'origin'    => 'shortcut',
		);

		$decision = Outpost_Source_Detector::dispatch( $resolved, $context );

		return new \WP_REST_Response(
			array(
				'redirect_url' => (string) $decision['redirect_url'],
				'source_id'    => (string) ( $decision['source_id'] ?? '' ),
				'route_type'   => (string) ( $decision['route_type'] ?? '' ),
				'mode'         => isset( $decision['mode'] ) ? (string) $decision['mode'] : null,
			),
			200
		);
	}
}
