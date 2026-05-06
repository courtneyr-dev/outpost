<?php
/**
 * Outpost_OAuth_Provider_Notion (G3.5a).
 *
 * Notion-specific OAuth 2.0 provider:
 *
 * - Authorize URL: https://api.notion.com/v1/oauth/authorize
 * - Token URL:     https://api.notion.com/v1/oauth/token
 * - Revocation:    NOT SUPPORTED (Notion does not currently expose
 *                  RFC 7009 revocation; revocation_endpoint() returns
 *                  null. disconnect() falls through to local delete.)
 * - Scopes:        Notion uses workspace-level permissions managed in
 *                  Notion's UI; OAuth scopes are empty.
 * - Token shape:   Notion returns workspace_id / workspace_name /
 *                  bot_id / owner alongside access_token. shape_credentials()
 *                  preserves these in the stored credentials array.
 * - API version:   `Notion-Version: 2025-09-03` header is required on
 *                  the token endpoint and on every API request.
 *
 * Client credentials resolved from constants:
 *   OUTPOST_NOTION_CLIENT_ID
 *   OUTPOST_NOTION_CLIENT_SECRET
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_OAuth_Provider_Notion extends Outpost_OAuth_Provider_Base {

	public const NOTION_API_VERSION = '2025-09-03';

	public function id(): string {
		return 'notion';
	}

	public function label(): string {
		return __( 'Notion', 'outpost' );
	}

	public function authorize_url(): string {
		return 'https://api.notion.com/v1/oauth/authorize';
	}

	public function token_url(): string {
		return 'https://api.notion.com/v1/oauth/token';
	}

	public function revocation_endpoint(): ?string {
		// Notion does not currently expose a revocation endpoint.
		// Documented in docs/adapters/notion.md.
		return null;
	}

	public function client_id(): string {
		if ( defined( 'OUTPOST_NOTION_CLIENT_ID' ) ) {
			return (string) constant( 'OUTPOST_NOTION_CLIENT_ID' );
		}
		return '';
	}

	public function client_secret(): string {
		if ( defined( 'OUTPOST_NOTION_CLIENT_SECRET' ) ) {
			return (string) constant( 'OUTPOST_NOTION_CLIENT_SECRET' );
		}
		return '';
	}

	/**
	 * Notion's token endpoint requires Basic auth using the integration's
	 * client_id:client_secret in the Authorization header instead of in
	 * the request body. Override extra_token_request_headers to send it.
	 *
	 * @return array<string,string>
	 */
	protected function extra_token_request_headers(): array {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Building HTTP Basic auth header per Notion's token-endpoint spec.
		$basic = base64_encode( $this->client_id() . ':' . $this->client_secret() );
		return array(
			'Authorization'  => 'Basic ' . $basic,
			'Notion-Version' => self::NOTION_API_VERSION,
			'Content-Type'   => 'application/json',
		);
	}

	/**
	 * Notion's token response includes non-standard fields. Preserve
	 * them in the stored credentials so callers can identify the
	 * connected workspace without a separate users.me API call.
	 *
	 * Shape stored:
	 *   - access_token (Notion never expires; no expires_in in response)
	 *   - workspace_id, workspace_name, workspace_icon
	 *   - bot_id (the integration's identity in the workspace)
	 *   - owner (Notion's owner object — could be a user or workspace)
	 *   - obtained_at (Unix ts when we received the token)
	 *
	 * @param array<string,mixed> $response_body Decoded token response.
	 * @return array<string,mixed>
	 */
	public function shape_credentials( array $response_body ): array {
		return array(
			'access_token'   => (string) ( $response_body['access_token'] ?? '' ),
			'workspace_id'   => (string) ( $response_body['workspace_id'] ?? '' ),
			'workspace_name' => (string) ( $response_body['workspace_name'] ?? '' ),
			'workspace_icon' => (string) ( $response_body['workspace_icon'] ?? '' ),
			'bot_id'         => (string) ( $response_body['bot_id'] ?? '' ),
			'owner'          => is_array( $response_body['owner'] ?? null ) ? $response_body['owner'] : array(),
			'obtained_at'    => time(),
		);
	}
}
