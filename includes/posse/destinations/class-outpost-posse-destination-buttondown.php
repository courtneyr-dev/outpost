<?php
/**
 * Outpost_POSSE_Destination_Buttondown (G5).
 *
 * Buttondown POSSE-outbound destination. WP post is canonical; on
 * publish, a fan-out copy lands in Buttondown's outbox via the emails
 * endpoint. Unlike the other three G5 destinations, Buttondown has a
 * native `canonical_url` field — Outpost uses it and does NOT append
 * the "originally appeared on…" paragraph to the body.
 *
 * Auth: `Authorization: Token {api_key}`. The `Token` scheme is
 * Buttondown-specific (NOT Bearer); the auth header is built
 * explicitly to avoid the common copy-paste error.
 *
 * Endpoint: `POST https://api.buttondown.email/v1/emails`
 *
 * Status decision: default `about_to_send` (immediate). When the
 * site has the `buttondown_send_as_draft` setting checked, status
 * becomes `draft` and the user can review in Buttondown before
 * pushing send.
 *
 * @package Outpost
 * @since   0.1.100
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_POSSE_Destination_Buttondown extends Outpost_POSSE_Destination_Base {

	public const SETTINGS_TAB = 'api_keys';

	public const API_ENDPOINT = 'https://api.buttondown.email/v1/emails';

	public function id(): string {
		return 'buttondown';
	}

	public function label(): string {
		return __( 'Buttondown', 'outpost' );
	}

	public function provider_id(): string {
		return '';
	}

	public function dispatch( int $post_id ): array {
		$settings = self::read_settings();

		if ( ! $settings['enabled'] ) {
			return self::failure_result( __( 'Buttondown syndication is disabled in settings.', 'outpost' ), false );
		}
		if ( '' === $settings['api_key'] ) {
			return self::failure_result( __( 'Buttondown API key missing.', 'outpost' ), false );
		}
		$post = get_post( $post_id );
		if ( null === $post ) {
			return self::failure_result( __( 'Post not found.', 'outpost' ), false );
		}

		$payload = array(
			'subject'       => (string) $post->post_title,
			'body'          => Outpost_POSSE_Content_Transformer::to_markdown_only( $post_id ),
			'status'        => $settings['send_as_draft'] ? 'draft' : 'about_to_send',
			'canonical_url' => (string) get_permalink( $post_id ),
		);

		$response = wp_safe_remote_post(
			self::API_ENDPOINT,
			array(
				'timeout'   => 10,
				'headers'   => array(
					'Authorization' => 'Token ' . $settings['api_key'],
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
					__( 'Buttondown request failed: %s', 'outpost' ),
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
				$syndication_url = (string) ( $json['web_url'] ?? $json['absolute_url'] ?? $json['permalink'] ?? '' );
			}
			if ( '' === $syndication_url ) {
				return self::failure_result( __( 'Buttondown accepted the email but returned no URL.', 'outpost' ), false );
			}
			return self::success_result( $syndication_url );
		}

		return self::failure_result(
			sprintf(
				/* translators: 1: HTTP status code, 2: response body */
				__( 'Buttondown returned HTTP %1$d: %2$s', 'outpost' ),
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
		$fields['buttondown_enabled']       = array(
			'label'       => __( 'Syndicate to Buttondown', 'outpost' ),
			'type'        => 'checkbox',
			'sensitive'   => false,
			'description' => __( 'When checked, published posts fan out as Buttondown emails.', 'outpost' ),
			'default'     => false,
		);
		$fields['buttondown_api_key']       = array(
			'label'       => __( 'Buttondown API key', 'outpost' ),
			'type'        => 'password',
			'sensitive'   => true,
			'description' => __( 'Buttondown → Settings → API. Stored encrypted.', 'outpost' ),
			'default'     => '',
		);
		$fields['buttondown_send_as_draft'] = array(
			'label'       => __( 'Send as draft for manual review', 'outpost' ),
			'type'        => 'checkbox',
			'sensitive'   => false,
			'description' => __( 'When checked, fan-out copies land as drafts in Buttondown so you can review before sending.', 'outpost' ),
			'default'     => false,
		);
		return $fields;
	}

	/**
	 * @return array{enabled: bool, api_key: string, send_as_draft: bool}
	 */
	private static function read_settings(): array {
		$tab = Outpost_Settings_Handler::read_tab( self::SETTINGS_TAB );
		return array(
			'enabled'       => ! empty( $tab['buttondown_enabled'] ),
			'api_key'       => (string) ( $tab['buttondown_api_key'] ?? '' ),
			'send_as_draft' => ! empty( $tab['buttondown_send_as_draft'] ),
		);
	}

	private static function is_transient_status( int $status ): bool {
		return in_array( $status, array( 429, 502, 503, 504 ), true );
	}
}
