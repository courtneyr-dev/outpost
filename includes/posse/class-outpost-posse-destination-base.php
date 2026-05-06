<?php
/**
 * Outpost_POSSE_Destination_Base (G3.5b).
 *
 * Abstract base for direct-API POSSE-outbound destinations. Concrete
 * destination classes (Beehiiv, Buttondown, Kit, write.as in G5;
 * Notion / Ravelry / RWG / WHOOP outbound in later phases) extend this
 * base and implement `dispatch()`.
 *
 * Each destination declares:
 *   - `id()` — stable lowercase kebab-case (e.g., 'beehiiv').
 *   - `label()` — human-readable for UI.
 *   - `provider_id()` — the OAuth provider whose credentials drive this
 *     destination. Used by `get_credentials_for_user()` to load via the
 *     G3.5a Outpost_Credentials_Store.
 *   - `dispatch( int $post_id ): array` — the actual API call.
 *
 * The dispatcher (Outpost_POSSE_Dispatcher) handles scheduling, retry,
 * and post-meta accounting. Destinations only need to make the API call
 * and return a normalized result shape.
 *
 * Result shape returned by dispatch():
 *   [
 *     'success'         => bool,
 *     'syndication_url' => ?string,  // Set on success; null otherwise.
 *     'error'           => ?string,  // Set on failure; null on success.
 *     'retryable'       => bool,     // True for transient failures (5xx,
 *                                    //  timeout). False for auth/4xx —
 *                                    //  retrying won't help.
 *   ]
 *
 * @package Outpost
 * @since   0.1.78
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Outpost_POSSE_Destination_Base {

	/**
	 * Stable destination id (lowercase kebab-case). Used as key in
	 * post_meta arrays (_outpost_posse_targets, etc.) and as the
	 * registration key in the registry.
	 */
	abstract public function id(): string;

	/**
	 * Human-readable label for UI. Translatable.
	 */
	abstract public function label(): string;

	/**
	 * The OAuth provider id whose credentials this destination uses.
	 * Resolved against Outpost_Credentials_Store via the G3.5a
	 * foundation. Returns empty string when no OAuth provider is
	 * required (e.g., a destination using site-scoped API keys via
	 * wp-config constants — uncommon but possible).
	 */
	abstract public function provider_id(): string;

	/**
	 * Make the actual API call to publish the post to this destination.
	 * Called by the dispatcher inside a wp-cron event. Must return the
	 * standard result shape (see class docblock).
	 *
	 * Implementations should:
	 *  - Read post data via get_post( $post_id ) and any needed meta.
	 *  - Call get_credentials_for_user( get_post_field( 'post_author', $post_id ) )
	 *    to load the publishing user's encrypted credentials.
	 *  - Make the API call with sane timeouts (5-10 seconds).
	 *  - Return retryable=true for transport errors and 5xx responses.
	 *  - Return retryable=false for 401/403/422 — the user needs to fix
	 *    something before retry would help.
	 *
	 * @since 0.1.78
	 *
	 * @param int $post_id WordPress post id being syndicated.
	 * @return array{success: bool, syndication_url: ?string, error: ?string, retryable: bool}
	 */
	abstract public function dispatch( int $post_id ): array;

	/**
	 * Load and decrypt the publishing user's credentials for this
	 * destination's OAuth provider. Returns null when no credentials
	 * are stored OR when provider_id() is empty.
	 *
	 * Concrete destinations call this from `dispatch()` to get the
	 * `access_token` (and refresh_token, member_id, etc., per provider).
	 *
	 * @since 0.1.78
	 *
	 * @param int $user_id User id whose credentials to load.
	 * @return array<string,mixed>|null
	 */
	protected function get_credentials_for_user( int $user_id ): ?array {
		$provider_id = $this->provider_id();
		if ( '' === $provider_id ) {
			return null;
		}
		if ( ! class_exists( 'Outpost_Credentials_Store' ) ) {
			return null;
		}
		return Outpost_Credentials_Store::get( $provider_id, $user_id );
	}

	/**
	 * Helper: build the standard success result shape.
	 *
	 * @param string $syndication_url The URL where the syndicated copy lives.
	 * @return array{success: bool, syndication_url: ?string, error: ?string, retryable: bool}
	 */
	protected static function success_result( string $syndication_url ): array {
		return array(
			'success'         => true,
			'syndication_url' => $syndication_url,
			'error'           => null,
			'retryable'       => false,
		);
	}

	/**
	 * Helper: build the standard failure result shape. Defaults to
	 * non-retryable so accidental omissions surface immediately rather
	 * than spinning the retry loop.
	 *
	 * @param string $error_message  Human-readable error.
	 * @param bool   $retryable      True for transient failures.
	 * @return array{success: bool, syndication_url: ?string, error: ?string, retryable: bool}
	 */
	protected static function failure_result( string $error_message, bool $retryable = false ): array {
		return array(
			'success'         => false,
			'syndication_url' => null,
			'error'           => $error_message,
			'retryable'       => $retryable,
		);
	}
}
