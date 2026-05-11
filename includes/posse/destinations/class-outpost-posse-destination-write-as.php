<?php
/**
 * Outpost_POSSE_Destination_WriteAs (G5).
 *
 * write.as POSSE-outbound destination. WP post is canonical; on
 * publish, a fan-out copy lands on write.as as a markdown post with
 * the WP URL appended as an "originally appeared on…" paragraph
 * (write.as posts have no native `canonical_url` field — the API
 * supports custom slugs but not canonical URLs).
 *
 * Auth: `Authorization: Token {api_token}`.
 *
 * Endpoint: `POST https://write.as/api/posts`. When a collection
 * alias is configured, posts are pushed to that collection via the
 * `/collections/{alias}/posts` endpoint instead; otherwise posts are
 * standalone (anonymous-ish — owned by the auth token but not
 * grouped into a blog).
 *
 * @package Outpost
 * @since   0.1.100
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_POSSE_Destination_WriteAs extends Outpost_POSSE_Destination_Base {

	public const SETTINGS_TAB = 'api_keys';

	public const API_ENDPOINT_STANDALONE = 'https://write.as/api/posts';
	public const API_ENDPOINT_COLLECTION = 'https://write.as/api/collections/%s/posts';

	public function id(): string {
		return 'write-as';
	}

	public function label(): string {
		return __( 'write.as', 'outpost' );
	}

	public function provider_id(): string {
		return '';
	}

	public function dispatch( int $post_id ): array {
		$settings = self::read_settings();

		if ( ! $settings['enabled'] ) {
			return self::failure_result( __( 'write.as syndication is disabled in settings.', 'outpost' ), false );
		}
		if ( '' === $settings['api_token'] ) {
			return self::failure_result( __( 'write.as API token missing.', 'outpost' ), false );
		}
		$post = get_post( $post_id );
		if ( null === $post ) {
			return self::failure_result( __( 'Post not found.', 'outpost' ), false );
		}

		$endpoint = '' === $settings['collection']
			? self::API_ENDPOINT_STANDALONE
			: sprintf( self::API_ENDPOINT_COLLECTION, rawurlencode( $settings['collection'] ) );

		$payload = array(
			'title' => (string) $post->post_title,
			'body'  => Outpost_POSSE_Content_Transformer::to_markdown_with_canonical( $post_id ),
		);

		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout'   => 10,
				'headers'   => array(
					'Authorization' => 'Token ' . $settings['api_token'],
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
					__( 'write.as request failed: %s', 'outpost' ),
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
				$syndication_url = (string) ( $json['data']['url'] ?? '' );
				if ( '' === $syndication_url && isset( $json['data']['id'], $json['data']['slug'] ) ) {
					$syndication_url = 'https://write.as/' . rawurlencode( (string) $json['data']['slug'] );
				}
			}
			if ( '' === $syndication_url ) {
				return self::failure_result( __( 'write.as accepted the post but returned no URL.', 'outpost' ), false );
			}
			return self::success_result( $syndication_url );
		}

		return self::failure_result(
			sprintf(
				/* translators: 1: HTTP status code, 2: response body */
				__( 'write.as returned HTTP %1$d: %2$s', 'outpost' ),
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
		$fields['write_as_enabled']    = array(
			'label'       => __( 'Syndicate to write.as', 'outpost' ),
			'type'        => 'checkbox',
			'sensitive'   => false,
			'description' => __( 'When checked, published posts fan out to write.as.', 'outpost' ),
			'default'     => false,
		);
		$fields['write_as_api_token']  = array(
			'label'       => __( 'write.as API token', 'outpost' ),
			'type'        => 'password',
			'sensitive'   => true,
			'description' => __( 'write.as → Settings → Get API token. Stored encrypted.', 'outpost' ),
			'default'     => '',
		);
		$fields['write_as_collection'] = array(
			'label'       => __( 'write.as collection alias (optional)', 'outpost' ),
			'type'        => 'text',
			'sensitive'   => false,
			'description' => __( 'The collection (blog) alias posts should publish into. Leave blank to publish standalone posts owned by your account.', 'outpost' ),
			'default'     => '',
		);
		return $fields;
	}

	/**
	 * @return array{enabled: bool, api_token: string, collection: string}
	 */
	private static function read_settings(): array {
		$tab = Outpost_Settings_Handler::read_tab( self::SETTINGS_TAB );
		return array(
			'enabled'    => ! empty( $tab['write_as_enabled'] ),
			'api_token'  => (string) ( $tab['write_as_api_token'] ?? '' ),
			'collection' => (string) ( $tab['write_as_collection'] ?? '' ),
		);
	}

	private static function is_transient_status( int $status ): bool {
		return in_array( $status, array( 429, 502, 503, 504 ), true );
	}
}
