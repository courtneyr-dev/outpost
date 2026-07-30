<?php
/**
 * Outpost_POSSE_Destination_Kit (G5).
 *
 * Kit (formerly ConvertKit) POSSE-outbound destination. WP post is
 * canonical; on publish, a fan-out copy lands in Kit as a broadcast
 * draft with the WP URL appended as an "originally appeared on…"
 * paragraph (Kit broadcasts have no native `canonical_url` field).
 *
 * Auth path: v3 API secret. G5 ships this path only; the v4 OAuth
 * "Connect Kit" flow lands in a follow-up PR (G5-kit-oauth). The
 * settings UI surfaces a v3 API secret input with an inline note
 * about the upcoming OAuth option — NOT a disabled Connect button
 * stub.
 *
 * Endpoint: `POST https://api.convertkit.com/v3/broadcasts`
 *
 * v3 auth shape: `api_secret` carried as a JSON body field, NOT as a
 * header. This matches the ConvertKit v3 API contract.
 *
 * @package Outpost
 * @since   0.1.100
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_POSSE_Destination_Kit extends Outpost_POSSE_Destination_Base {

	public const SETTINGS_TAB = 'api_keys';

	public const API_ENDPOINT = 'https://api.convertkit.com/v3/broadcasts';

	public function id(): string {
		return 'kit';
	}

	public function label(): string {
		return __( 'Kit', 'outpost-mobile-publishing' );
	}

	public function provider_id(): string {
		return '';
	}

	public function dispatch( int $post_id ): array {
		$settings = self::read_settings();

		if ( ! $settings['enabled'] ) {
			return self::failure_result( __( 'Kit syndication is disabled in settings.', 'outpost-mobile-publishing' ), false );
		}
		if ( '' === $settings['api_secret'] ) {
			return self::failure_result( __( 'Kit API secret missing.', 'outpost-mobile-publishing' ), false );
		}
		$post = get_post( $post_id );
		if ( null === $post ) {
			return self::failure_result( __( 'Post not found.', 'outpost-mobile-publishing' ), false );
		}

		$payload = array(
			'api_secret'  => $settings['api_secret'],
			'subject'     => (string) $post->post_title,
			'content'     => Outpost_POSSE_Content_Transformer::to_html_with_canonical( $post_id ),
			'description' => sprintf(
				/* translators: %s: WordPress post permalink */
				__( 'Syndicated from %s', 'outpost-mobile-publishing' ),
				(string) get_permalink( $post_id )
			),
		);

		$response = wp_safe_remote_post(
			self::API_ENDPOINT,
			array(
				'timeout'   => 10,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'      => (string) wp_json_encode( $payload ),
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::failure_result(
				sprintf(
					/* translators: %s: error message */
					__( 'Kit request failed: %s', 'outpost-mobile-publishing' ),
					$response->get_error_message()
				),
				true
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$json   = json_decode( $body, true );

		if ( $status >= 200 && $status < 300 ) {
			$syndication_url = '';
			if ( is_array( $json ) ) {
				$broadcast       = is_array( $json['broadcast'] ?? null ) ? $json['broadcast'] : $json;
				$syndication_url = (string) ( $broadcast['public_url'] ?? $broadcast['url'] ?? '' );
				// Kit's create-broadcast response doesn't always include a
				// public URL until the broadcast is scheduled or sent. Fall
				// back to a stable archive URL derived from the broadcast id
				// so the dispatcher can still mark this as a syndicated copy.
				if ( '' === $syndication_url && isset( $broadcast['id'] ) ) {
					$syndication_url = 'https://app.kit.com/broadcasts/' . rawurlencode( (string) $broadcast['id'] );
				}
			}
			if ( '' === $syndication_url ) {
				return self::failure_result( __( 'Kit accepted the broadcast but returned no identifier.', 'outpost-mobile-publishing' ), false );
			}
			return self::success_result( $syndication_url );
		}

		return self::failure_result(
			sprintf(
				/* translators: 1: HTTP status code, 2: response body */
				__( 'Kit returned HTTP %1$d: %2$s', 'outpost-mobile-publishing' ),
				$status,
				wp_strip_all_tags( $body )
			),
			self::is_transient_status( $status )
		);
	}

	/**
	 * @since 0.1.100
	 */
	public static function register(): void {
		add_action(
			'init',
			static function (): void {
				Outpost_POSSE_Registry::register( new self() );
			}
		);
		add_filter( 'outpost_settings_fields_api_keys', array( __CLASS__, 'register_settings_fields' ) );
	}

	/**
	 * @since 0.1.100
	 *
	 * @param array<string,array<string,mixed>> $fields
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_settings_fields( array $fields ): array {
		$fields['kit_enabled']    = array(
			'label'       => __( 'Syndicate to Kit', 'outpost-mobile-publishing' ),
			'type'        => 'checkbox',
			'sensitive'   => false,
			'description' => __( 'When checked, published posts fan out as Kit broadcast drafts.', 'outpost-mobile-publishing' ),
			'default'     => false,
		);
		$fields['kit_api_secret'] = array(
			'label'       => __( 'Kit API secret (v3)', 'outpost-mobile-publishing' ),
			'type'        => 'password',
			'sensitive'   => true,
			'description' => __(
				'Kit account → Settings → Advanced → API. Use the API Secret (not the API Key) — broadcast creation requires the secret. Stored encrypted. Kit OAuth v4 connection arrives in the next release; this v3 path stays supported alongside it.',
				'outpost-mobile-publishing'
			),
			'default'     => '',
		);
		return $fields;
	}

	/**
	 * @return array{enabled: bool, api_secret: string}
	 */
	private static function read_settings(): array {
		$tab = Outpost_Settings_Handler::read_tab( self::SETTINGS_TAB );
		return array(
			'enabled'    => ! empty( $tab['kit_enabled'] ),
			'api_secret' => (string) ( $tab['kit_api_secret'] ?? '' ),
		);
	}

	private static function is_transient_status( int $status ): bool {
		return in_array( $status, array( 429, 502, 503, 504 ), true );
	}
}
