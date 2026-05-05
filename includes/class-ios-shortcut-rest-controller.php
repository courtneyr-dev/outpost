<?php
/**
 * Outpost_IOS_Shortcut_REST_Controller
 *
 * REST endpoint for the iOS Shortcut bridge: POST a JSON body
 * `{ url, shared_text? }` with a Bearer token, get back a JSON
 * response `{ redirect_url, source_id, mode }`. The iOS Shortcut
 * uses the redirect_url to navigate Safari to the composer.
 *
 * AUTH
 *
 * Authentication is handled by `Outpost_IOS_Shortcut_Token_Authenticator`
 * via the `rest_authentication_errors` filter. By the time
 * permission_callback runs, the current user is set if (and only
 * if) the token resolved. We require `edit_posts` so even a valid
 * token belonging to a Subscriber-level user can't dispatch.
 *
 * RELATIONSHIP TO F6'S /post/shortcut
 *
 * F6 ships `/post/shortcut` (a PWA route handled at template_redirect)
 * which returns 303 redirects for cookie-authed users. FX adds this
 * REST endpoint as the iOS-Shortcut-friendly contract: JSON in,
 * JSON out, Bearer token auth. Both endpoints can coexist; iOS
 * Shortcut users point at this one, the F6 endpoint stays for
 * cookie-authed direct hits.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_IOS_Shortcut_REST_Controller {

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
						// instead of a clean URL. esc_url_raw strips the
						// blob to empty string before the extractor sees
						// it. sanitize_textarea_field preserves the raw
						// content; Outpost_Source_Detector::extract_url_from_payload
						// regex-extracts the embedded URL and runs full
						// is_http_url validation before use.
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'shared_text' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'title'       => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Permission gate. By the time this runs, the token authenticator
	 * has set the current user if the Bearer token resolved.
	 *
	 * @return bool|\WP_Error
	 */
	public static function permission_check() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'outpost_ios_shortcut_unauthorized',
				__( 'Authentication required.', 'outpost' ),
				array( 'status' => 401 )
			);
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'outpost_ios_shortcut_forbidden',
				__( 'You do not have permission to use the iOS Shortcut bridge.', 'outpost' ),
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
				__( 'No URL detected in payload.', 'outpost' ),
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

		if ( 'auto' === ( $decision['route_type'] ?? '' ) && ! empty( $decision['prefill_token'] ) ) {
			Outpost_Share_Target_Controller::enqueue_preview_transient(
				$resolved,
				(string) $decision['prefill_token'],
				(string) ( $decision['source_id'] ?? '' )
			);
		}

		// Authenticator already recorded first-seen on Bearer success.
		// For cookie-authed POSTs to this REST route (atypical but
		// possible), record here too.
		Outpost_IOS_Shortcut_Token::record_first_seen_if_unset( get_current_user_id() );

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
