<?php
/**
 * Outpost_Manual_Share_Intent_Payload_Builder
 *
 * Server-side construction of the manual-share intent payload returned
 * to the PWA. F10 builds the Android branch; the iOS branch returns
 * the F9 stub until F11 lands real iOS handling.
 *
 * Architecture (CLAUDE.md F10 #1): platform config drives behavior. The
 * builder reads `Platform_Config::to_array()` for `android_action`,
 * `android_pkg`, `android_mime`, `android_extras`, `web_intent_url`,
 * `caption_via`, `after_share` and assembles the payload from those
 * fields. Quirks like Facebook ignoring `EXTRA_TEXT` or TikTok using
 * `com.zhiliaoapp.musically` are config values the platform's config
 * declares — not branches in this builder.
 *
 * The PWA receives:
 *
 *     array(
 *         'platform'         => 'instagram-feed',
 *         'platform_label'   => 'Instagram',
 *         'files'            => array(
 *             array( 'url' => 'https://...', 'alt' => '...', 'mime' => 'image/jpeg' ),
 *             ...
 *         ),
 *         'caption'          => 'Short post excerpt for share intent',
 *         'clipboard_text'   => 'Full caption + alt-texts joined for clipboard fallback',
 *         'intent_strategy'  => 'navigator_share' | 'intent_url',
 *         'fallback_url'     => 'intent://...' | 'https://www.threads.net/intent/post?...',
 *         'after_share'      => 'mark_done' | 'prompt_for_silo_url' | 'silent',
 *         'audit_log_id'     => 'a1b2c3d4-...',
 *         'source_url'       => 'https://site.com/post-permalink',
 *     )
 *
 * The PWA's AndroidShareHandler consumes this shape verbatim; @image_uri
 * placeholders in the fallback_url are PWA-side substitutions because
 * content:// URIs only exist after the PWA has fetched the file.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Manual_Share_Intent_Payload_Builder {

	/** Maximum caption length for the inline share intent (matches X classic limit). */
	private const CAPTION_MAX_LENGTH = 280;

	/**
	 * Build the Android intent payload for a published post + platform.
	 * Records an audit log entry and embeds its id in the response.
	 *
	 * @param Outpost_Manual_Share_Platform_Config $platform Platform config.
	 * @param int                                  $post_id  Post id (must be a real post).
	 * @return array<string,mixed> Intent payload for the PWA.
	 */
	public static function build_for_android(
		Outpost_Manual_Share_Platform_Config $platform,
		int $post_id
	): array {
		$config         = $platform->to_array();
		$caption        = self::extract_caption( $post_id );
		$files          = self::collect_post_files( $post_id );
		$clipboard_text = self::build_clipboard_text( $caption, $files );
		$source_url     = self::extract_source_url( $post_id );
		$strategy       = self::resolve_strategy( $config );
		$fallback_url   = self::build_fallback_url( $config, $caption, $files, $source_url );

		$audit_entry = Outpost_Manual_Share_Audit_Log::add_entry(
			$post_id,
			$platform->id(),
			$strategy
		);

		return array(
			'platform'        => $platform->id(),
			'platform_label'  => $platform->label(),
			'files'           => $files,
			'caption'         => self::truncate( $caption, self::CAPTION_MAX_LENGTH ),
			'clipboard_text'  => $clipboard_text,
			'intent_strategy' => $strategy,
			'fallback_url'    => $fallback_url,
			'after_share'     => $config['after_share'],
			'audit_log_id'    => $audit_entry['id'],
			'source_url'      => $source_url,
		);
	}

	/**
	 * Build the F9 stub response for desktop platforms. iOS routes
	 * through {@see self::build_for_ios()} since F11.
	 *
	 * @param string $platform_id Platform id.
	 * @param int    $post_id     Post id.
	 * @return array<string,mixed> F9 stub shape.
	 */
	public static function build_stub_response( string $platform_id, int $post_id ): array {
		return array(
			'status'      => 'stub',
			'message'     => sprintf(
				/* translators: %s: platform id (e.g. "instagram-feed"). */
				__( 'Manual share intent firing is not yet implemented for this platform (desktop). Will fire intent for platform: %s', 'outpost-mobile-publishing' ),
				$platform_id
			),
			'platform_id' => $platform_id,
			'post_id'     => $post_id,
		);
	}

	/**
	 * Build the iOS intent payload for a published post + platform.
	 * Records an audit log entry and embeds its id in the response.
	 *
	 * Differences from {@see self::build_for_android()}:
	 *
	 *   - Returns `ios_strategy` as the per-platform fallback chain
	 *     (e.g. `['navigator_share_files', 'app_url_scheme', 'manual']`).
	 *     The PWA's StrategyRunner walks the chain.
	 *   - Returns `app_url_scheme` (filled with @caption_encoded /
	 *     @source_url substitutions) for platforms that declare one.
	 *   - Returns `web_intent_url` (substituted) for platforms that
	 *     have one. The PWA picks based on the strategy in play.
	 *   - Returns `in_pwa_mode` = passed-through hint from the PWA's
	 *     own platform detector. Defaults to false; the PWA may
	 *     hoist `is_pwa_installed_on_ios` and POST it as a request
	 *     parameter.
	 *
	 * The audit log entry's `strategy` field is initialised to the
	 * FIRST entry in the chain — but the actual strategy that fires
	 * is reported back via the telemetry endpoint (POST
	 * /manual-share/intent/log). F11's runner picks the first viable
	 * strategy at runtime; if it falls through to the second entry,
	 * the telemetry update reflects that.
	 *
	 * @param Outpost_Manual_Share_Platform_Config $platform    Platform config.
	 * @param int                                  $post_id     Post id.
	 * @param bool                                 $in_pwa_mode PWA-installed hint from client.
	 * @return array<string,mixed> Intent payload for the PWA.
	 */
	public static function build_for_ios(
		Outpost_Manual_Share_Platform_Config $platform,
		int $post_id,
		bool $in_pwa_mode
	): array {
		$config         = $platform->to_array();
		$caption        = self::extract_caption( $post_id );
		$files          = self::collect_post_files( $post_id );
		$clipboard_text = self::build_clipboard_text( $caption, $files );
		$source_url     = self::extract_source_url( $post_id );
		$caption_short  = self::truncate( $caption, self::CAPTION_MAX_LENGTH );

		$ios_strategy = is_array( $config['ios_strategy'] ) && ! empty( $config['ios_strategy'] )
			? $config['ios_strategy']
			: array( 'manual' );

		$first_image_url = self::first_image_url( $files );
		$replacements    = array(
			'@caption_encoded' => rawurlencode( $caption_short ),
			'@source_url'      => rawurlencode( $source_url ),
			'@image_url'       => rawurlencode( $first_image_url ),
		);

		$app_url_scheme_raw = $config['app_url_scheme'] ?? null;
		$app_url_scheme     = is_string( $app_url_scheme_raw ) && '' !== $app_url_scheme_raw
			? strtr( $app_url_scheme_raw, $replacements )
			: null;

		$web_intent_url_raw = $config['web_intent_url'] ?? null;
		$web_intent_url     = is_string( $web_intent_url_raw ) && '' !== $web_intent_url_raw
			? strtr( $web_intent_url_raw, $replacements )
			: null;

		$initial_strategy = (string) $ios_strategy[0];
		$audit_entry      = Outpost_Manual_Share_Audit_Log::add_entry(
			$post_id,
			$platform->id(),
			self::map_strategy_to_audit_label( $initial_strategy )
		);

		return array(
			'platform'       => $platform->id(),
			'platform_label' => $platform->label(),
			'files'          => $files,
			'caption'        => $caption_short,
			'clipboard_text' => $clipboard_text,
			'ios_strategy'   => array_values( array_filter( $ios_strategy, 'is_string' ) ),
			'app_url_scheme' => $app_url_scheme,
			'web_intent_url' => $web_intent_url,
			'in_pwa_mode'    => $in_pwa_mode,
			'after_share'    => $config['after_share'],
			'audit_log_id'   => $audit_entry['id'],
			'source_url'     => $source_url,
		);
	}

	/**
	 * Map a strategy chain entry to the audit-log-allowed label.
	 *
	 * The audit log accepts `navigator_share`, `intent_url`,
	 * `two_tap_fallback`. The iOS chain entries (`navigator_share_files`,
	 * `app_url_scheme`, `web_intent`, `manual`) project onto these
	 * coarser labels for storage. The PWA's telemetry POST overrides
	 * with the actual fired strategy at completion time.
	 */
	private static function map_strategy_to_audit_label( string $chain_entry ): string {
		switch ( $chain_entry ) {
			case 'navigator_share_files':
				return Outpost_Manual_Share_Audit_Log::STRATEGY_NAVIGATOR_SHARE;
			case 'app_url_scheme':
			case 'web_intent':
				return Outpost_Manual_Share_Audit_Log::STRATEGY_INTENT_URL;
			case 'manual':
			default:
				return Outpost_Manual_Share_Audit_Log::STRATEGY_TWO_TAP;
		}
	}

	/**
	 * Extract the caption from the post. For Note/Photo posts, this is
	 * the post content (the user's user-facing text). For Article, this
	 * is the post title; the article body would be too long for an
	 * intent. We trim and keep the structure simple — F-later sessions
	 * can refine per-mode caption extraction.
	 */
	private static function extract_caption( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		$source = ! empty( $post->post_title ) && '' !== trim( $post->post_title )
			? (string) $post->post_title
			: (string) $post->post_content;
		return trim( wp_strip_all_tags( $source ) );
	}

	/**
	 * Collect attached media (images + videos) parented to the post.
	 * Each entry returns url + alt + mime so the PWA can fetch into a
	 * Blob and pass to navigator.share.
	 *
	 * @return array<int, array{url: string, alt: string, mime: string}>
	 */
	private static function collect_post_files( int $post_id ): array {
		$out        = array();
		$candidates = array_merge(
			(array) get_attached_media( 'image', $post_id ),
			(array) get_attached_media( 'video', $post_id )
		);
		foreach ( $candidates as $attachment ) {
			if ( ! $attachment instanceof WP_Post ) {
				continue;
			}
			$url = (string) wp_get_attachment_url( $attachment->ID );
			if ( '' === $url ) {
				continue;
			}
			$alt   = (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true );
			$mime  = (string) get_post_mime_type( $attachment->ID );
			$out[] = array(
				'url'  => $url,
				'alt'  => $alt,
				'mime' => '' === $mime ? 'application/octet-stream' : $mime,
			);
		}
		return $out;
	}

	/**
	 * Build the clipboard payload — the universal safety net per Doc 1
	 * §4.5. Joins caption + alt texts so the user has everything they
	 * might need to paste in the destination app, regardless of which
	 * intent strategy succeeded.
	 *
	 * @param array<int, array{url: string, alt: string, mime: string}> $files
	 */
	private static function build_clipboard_text( string $caption, array $files ): string {
		$parts = array();
		if ( '' !== trim( $caption ) ) {
			$parts[] = trim( $caption );
		}
		$alts = array();
		foreach ( $files as $file ) {
			$alt = isset( $file['alt'] ) ? trim( (string) $file['alt'] ) : '';
			if ( '' !== $alt ) {
				$alts[] = $alt;
			}
		}
		if ( ! empty( $alts ) ) {
			$parts[] = implode( "\n", $alts );
		}
		return implode( "\n\n", $parts );
	}

	/**
	 * Resolve the post's permalink for the @source_url placeholder.
	 */
	private static function extract_source_url( int $post_id ): string {
		$permalink = get_permalink( $post_id );
		return is_string( $permalink ) ? $permalink : '';
	}

	/**
	 * Decide which path the PWA should take first.
	 *
	 * - `intent_url`      when the platform declares `caption_via =
	 *                     web_intent` AND has a `web_intent_url`.
	 *                     Pinterest, Reddit-manual, X (iOS path),
	 *                     Threads use this.
	 * - `navigator_share` everywhere else (the default Android path).
	 *
	 * The PWA-side handler treats this as the FIRST path; if it fails
	 * (rejected, no apps, throws), the handler tries the inverse path
	 * as a fallback.
	 *
	 * @param array<string,mixed> $config Normalized platform config.
	 */
	private static function resolve_strategy( array $config ): string {
		$caption_via    = (string) $config['caption_via'];
		$web_intent_url = $config['web_intent_url'] ?? null;
		if ( 'web_intent' === $caption_via && is_string( $web_intent_url ) && '' !== $web_intent_url ) {
			return Outpost_Manual_Share_Audit_Log::STRATEGY_INTENT_URL;
		}
		return Outpost_Manual_Share_Audit_Log::STRATEGY_NAVIGATOR_SHARE;
	}

	/**
	 * Build the fallback URL the PWA navigates to when navigator.share
	 * isn't viable (or as the primary path when intent_strategy ===
	 * 'intent_url'). Two shapes:
	 *
	 *   - `web_intent_url` from the platform config, with `@caption_encoded`
	 *     / `@source_url` / `@image_url` placeholders filled server-side.
	 *   - intent:// URL constructed from `android_action`, `android_pkg`,
	 *     `android_mime`, `android_extras` for navigator-share-first
	 *     platforms. The PWA fills `@image_uri` (content://) at runtime
	 *     because content URIs only exist after the PWA fetches the file.
	 *
	 * @param array<string,mixed>                                       $config     Normalized platform config.
	 * @param string                                                    $caption    Untruncated caption.
	 * @param array<int, array{url: string, alt: string, mime: string}> $files      Post's attached media.
	 * @param string                                                    $source_url Post permalink.
	 */
	private static function build_fallback_url(
		array $config,
		string $caption,
		array $files,
		string $source_url
	): string {
		$first_image_url = self::first_image_url( $files );
		$caption_short   = self::truncate( $caption, self::CAPTION_MAX_LENGTH );

		// For platforms whose primary path IS the web intent (Threads,
		// Reddit-manual, declared via caption_via='web_intent'), return
		// the substituted web URL. For platforms with caption_via='intent'
		// or 'clipboard' but a web_intent_url declared (e.g. X, Pinterest
		// on iOS), the web URL is iOS-only — Android falls through to
		// the intent:// URL where EXTRA_TEXT is honored. F11 will route
		// iOS to the web intent path; F10 keeps Android pure.
		$caption_via    = (string) ( $config['caption_via'] ?? '' );
		$web_intent_url = $config['web_intent_url'] ?? null;
		if (
			'web_intent' === $caption_via
			&& is_string( $web_intent_url )
			&& '' !== $web_intent_url
		) {
			$replacements = array(
				'@caption_encoded' => rawurlencode( $caption_short ),
				'@source_url'      => rawurlencode( $source_url ),
				'@image_url'       => rawurlencode( $first_image_url ),
			);
			return strtr( $web_intent_url, $replacements );
		}

		// Build the intent:// URL. android_extras keys to honor:
		// EXTRA_STREAM (file URI), EXTRA_TEXT (caption). Some platforms
		// (Facebook, TikTok, Flickr-manual, Instagram) declare
		// android_extras WITHOUT EXTRA_TEXT — the builder reads the
		// config and includes only declared keys, so config-driven
		// behavior naturally drops EXTRA_TEXT for those platforms.
		$action = (string) ( $config['android_action'] ?? 'android.intent.action.SEND' );
		$pkg    = (string) ( $config['android_pkg'] ?? '' );
		$mime   = (string) ( $config['android_mime'] ?? 'image/*' );
		$extras = is_array( $config['android_extras'] ?? null )
			? $config['android_extras']
			: array();

		$pieces   = array();
		$pieces[] = 'action=' . rawurlencode( $action );
		$pieces[] = 'type=' . rawurlencode( $mime );
		foreach ( $extras as $extra_key => $extra_value ) {
			if ( ! is_string( $extra_key ) || ! is_string( $extra_value ) ) {
				continue;
			}
			$resolved = self::resolve_intent_extra_value(
				$extra_value,
				$caption_short,
				$first_image_url,
				$source_url
			);
			$pieces[] = 'S.' . rawurlencode( self::expand_intent_extra_key( $extra_key ) )
				. '=' . rawurlencode( $resolved );
		}

		$query = implode( '&', $pieces );
		$tail  = 'end';
		if ( '' !== $pkg ) {
			$tail = 'package=' . $pkg . ';end';
		}
		return 'intent://share?' . $query . '#Intent;scheme=android-app;' . $tail;
	}

	/**
	 * Expand short Android extras names to their canonical
	 * `android.intent.extra.*` form. Platform configs use short names
	 * (`EXTRA_STREAM`, `EXTRA_TEXT`) for readability; intent:// URLs
	 * MUST use the canonical names for Android target apps to find
	 * them. Unknown short names pass through verbatim so future
	 * platforms with custom extras (e.g. Instagram Stories'
	 * `interactive_asset_uri`) work without map updates.
	 */
	private static function expand_intent_extra_key( string $key ): string {
		static $map = array(
			'EXTRA_STREAM'    => 'android.intent.extra.STREAM',
			'EXTRA_TEXT'      => 'android.intent.extra.TEXT',
			'EXTRA_TITLE'     => 'android.intent.extra.TITLE',
			'EXTRA_SUBJECT'   => 'android.intent.extra.SUBJECT',
			'EXTRA_HTML_TEXT' => 'android.intent.extra.HTML_TEXT',
		);
		return $map[ $key ] ?? $key;
	}

	/**
	 * Resolve `@caption` / `@caption_encoded` / `@source_url` /
	 * `@image_url` / `@image_uri` placeholders in an extras value.
	 * `@image_uri` is intentionally left unsubstituted — the PWA
	 * replaces it after fetching the image into a content:// URI.
	 */
	private static function resolve_intent_extra_value(
		string $value,
		string $caption,
		string $first_image_url,
		string $source_url
	): string {
		$replacements = array(
			'@caption'         => $caption,
			'@caption_encoded' => rawurlencode( $caption ),
			'@source_url'      => $source_url,
			'@image_url'       => $first_image_url,
		);
		return strtr( $value, $replacements );
	}

	/**
	 * @param array<int, array{url: string, alt: string, mime: string}> $files
	 */
	private static function first_image_url( array $files ): string {
		foreach ( $files as $file ) {
			$mime = (string) ( $file['mime'] ?? '' );
			if ( 0 === strpos( $mime, 'image/' ) ) {
				return (string) ( $file['url'] ?? '' );
			}
		}
		return '';
	}

	private static function truncate( string $text, int $max ): string {
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		// Use mb_substr to avoid splitting multi-byte chars; ellipsize.
		$candidate = function_exists( 'mb_substr' )
			? (string) mb_substr( $text, 0, $max - 1 )
			: substr( $text, 0, $max - 1 );
		return $candidate . '…';
	}
}
