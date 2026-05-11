<?php
/**
 * Outpost_POSSE_Destination_Beehiiv (G5).
 *
 * Beehiiv POSSE-outbound destination. The WP post is canonical; on
 * publish, a fan-out copy lands in the configured Beehiiv publication
 * with the WP URL appended as an "originally appeared on…" paragraph
 * (Beehiiv's API has no native `canonical_url` field).
 *
 * Auth: Bearer API key stored encrypted in the G3.5d `api_keys`
 * settings tab.
 *
 * Endpoint: `POST https://api.beehiiv.com/v2/publications/{id}/posts`
 *
 * Status decision: payload sends `status: 'confirmed'` so the post is
 * scheduled for the publication's auto-publish flow rather than left
 * in draft. The Beehiiv Send API (different endpoint, paid-only)
 * isn't used here — POSSE-outbound uses the Posts endpoint which is
 * on standard plans.
 *
 * @package Outpost
 * @since   0.1.100
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_POSSE_Destination_Beehiiv extends Outpost_POSSE_Destination_Base {

	public const SETTINGS_TAB = 'api_keys';

	public const API_ENDPOINT_TEMPLATE = 'https://api.beehiiv.com/v2/publications/%s/posts';

	public function id(): string {
		return 'beehiiv';
	}

	public function label(): string {
		return __( 'Beehiiv', 'outpost' );
	}

	public function provider_id(): string {
		return '';
	}

	public function dispatch( int $post_id ): array {
		$settings = self::read_settings();

		if ( ! $settings['enabled'] ) {
			return self::failure_result( __( 'Beehiiv syndication is disabled in settings.', 'outpost' ), false );
		}
		if ( '' === $settings['api_key'] || '' === $settings['publication_id'] ) {
			return self::failure_result( __( 'Beehiiv API key or publication ID missing.', 'outpost' ), false );
		}
		$post = get_post( $post_id );
		if ( null === $post ) {
			return self::failure_result( __( 'Post not found.', 'outpost' ), false );
		}

		$endpoint = sprintf( self::API_ENDPOINT_TEMPLATE, rawurlencode( $settings['publication_id'] ) );

		$payload = array(
			'subject'      => (string) $post->post_title,
			'body_content' => Outpost_POSSE_Content_Transformer::to_html_with_canonical( $post_id ),
			'status'       => 'confirmed',
		);

		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout'   => 10,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $settings['api_key'],
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'      => (string) wp_json_encode( $payload ),
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::failure_result(
				sprintf(
					/* translators: %s: error message */
					__( 'Beehiiv request failed: %s', 'outpost' ),
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
			if ( is_array( $json ) && isset( $json['data'] ) && is_array( $json['data'] ) ) {
				$syndication_url = (string) ( $json['data']['web_url'] ?? $json['data']['url'] ?? '' );
			}
			if ( '' === $syndication_url ) {
				return self::failure_result( __( 'Beehiiv accepted the post but returned no URL.', 'outpost' ), false );
			}
			return self::success_result( $syndication_url );
		}

		return self::failure_result(
			sprintf(
				/* translators: 1: HTTP status code, 2: response body */
				__( 'Beehiiv returned HTTP %1$d: %2$s', 'outpost' ),
				$status,
				wp_strip_all_tags( $body )
			),
			self::is_transient_status( $status )
		);
	}

	/**
	 * Register the destination + its settings fields. Called once from
	 * outpost.php's plugins_loaded handler.
	 *
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
	 * Settings field definitions for the G3.5d API Keys tab.
	 *
	 * @since 0.1.100
	 *
	 * @param array<string,array<string,mixed>> $fields Existing fields registered by other platforms.
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_settings_fields( array $fields ): array {
		$fields['beehiiv_enabled']        = array(
			'label'       => __( 'Syndicate to Beehiiv', 'outpost' ),
			'type'        => 'checkbox',
			'sensitive'   => false,
			'description' => __( 'When checked, published posts fan out to your Beehiiv publication.', 'outpost' ),
			'default'     => false,
		);
		$fields['beehiiv_api_key']        = array(
			'label'       => __( 'Beehiiv API key', 'outpost' ),
			'type'        => 'password',
			'sensitive'   => true,
			'description' => __( 'Beehiiv → Settings → Integrations → API. Stored encrypted.', 'outpost' ),
			'default'     => '',
		);
		$fields['beehiiv_publication_id'] = array(
			'label'       => __( 'Beehiiv publication ID', 'outpost' ),
			'type'        => 'text',
			'sensitive'   => false,
			'description' => __( 'The pub_… identifier for the publication that should receive posts.', 'outpost' ),
			'default'     => '',
		);
		return $fields;
	}

	/**
	 * Decrypted settings snapshot, normalized.
	 *
	 * @return array{enabled: bool, api_key: string, publication_id: string}
	 */
	private static function read_settings(): array {
		$tab = Outpost_Settings_Handler::read_tab( self::SETTINGS_TAB );
		return array(
			'enabled'        => ! empty( $tab['beehiiv_enabled'] ),
			'api_key'        => (string) ( $tab['beehiiv_api_key'] ?? '' ),
			'publication_id' => (string) ( $tab['beehiiv_publication_id'] ?? '' ),
		);
	}

	/**
	 * Transient-failure status codes: rate-limited or upstream-gateway
	 * conditions that may resolve on retry. Matches the dispatcher's
	 * retry contract.
	 */
	private static function is_transient_status( int $status ): bool {
		return in_array( $status, array( 429, 502, 503, 504 ), true );
	}
}
