<?php
/**
 * Outpost_Manual_Share_Platform_Registry
 *
 * Holds the default 10 manual-share platform configs and applies the
 * `outpost_manual_share_platforms` filter so site owners can append,
 * modify, or remove platforms without forking core. F9 ships:
 *
 *   instagram-feed, instagram-stories, facebook, x-twitter, linkedin,
 *   threads, tiktok, pinterest, reddit-manual, flickr-manual
 *
 * Each entry is a raw associative array; the registry validates every
 * entry through {@see Outpost_Manual_Share_Platform_Config} at resolution
 * time. Bad configs raise `Outpost_Manual_Share_Invalid_Config_Exception`
 * — site owners adding a custom platform see the validation error during
 * filter resolution, not later when a user taps a chip and finds the
 * intent firing into nothing.
 *
 * Per-platform quirks documented in concepts/posse-outbound-may-2026.md
 * §4.4 are encoded as config fields (caption_via, android_pkg, etc.);
 * the F10/F11 intent-fire logic reads them at runtime. F9 ships the
 * declarative layer only — every chip-tap returns the F9 stub response
 * pending F10/F11.
 *
 * Reddit and Flickr default to `prefers_bridgy => true` so F14's
 * Companion_BridgyPublish becomes the preferred route once configured.
 * Manual chips for those platforms remain visible in F9 because Bridgy
 * detection always reports false until F14; the hide-when-Bridgy logic
 * lives in {@see Outpost_Manual_Share_Adapter::platform_chips()}.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Manual_Share_Platform_Registry {

	/**
	 * Cached resolved platforms (post-filter + post-validation). Reset
	 * by tests via reset_for_tests().
	 *
	 * @var Outpost_Manual_Share_Platform_Config[]|null
	 */
	private static ?array $resolved = null;

	/**
	 * Resolve all platforms (defaults + filter additions), validate
	 * each, return the final list.
	 *
	 * @return Outpost_Manual_Share_Platform_Config[]
	 *
	 * @throws Outpost_Manual_Share_Invalid_Config_Exception When any filter-registered
	 *         config fails Platform_Config validation.
	 */
	public static function all_platforms(): array {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}

		$defaults = self::default_configs();
		/**
		 * Filter the manual-share platform configs before validation.
		 *
		 * Site owners and third-party plugins can:
		 *
		 *   - Append a new platform (e.g. VSCO, Glass.photo, 500px)
		 *   - Remove a default platform (filter the array, drop entries
		 *     whose `id` matches what to remove)
		 *   - Modify a default platform's caveats, accepts_modes, etc.
		 *
		 * Returned configs are validated through Platform_Config; any
		 * malformed entry throws `Outpost_Manual_Share_Invalid_Config_Exception`
		 * during resolution so misconfiguration is visible at boot, not
		 * silently broken at chip-render or intent-fire time.
		 *
		 * Each config is a flat associative array. See CLAUDE.md F9
		 * Session Log for the documented config shape.
		 *
		 * @param array<int, array<string,mixed>> $configs Default platform configs.
		 */
		$filtered   = apply_filters( 'outpost_manual_share_platforms', $defaults );
		$candidates = is_array( $filtered ) ? array_values( $filtered ) : $defaults;

		$resolved = array();
		foreach ( $candidates as $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}
			$resolved[] = new Outpost_Manual_Share_Platform_Config( $config );
		}

		self::$resolved = $resolved;
		return $resolved;
	}

	/**
	 * Reset the cache. Test hook only.
	 */
	public static function reset_for_tests(): void {
		self::$resolved = null;
	}

	/**
	 * The 10 default platform configs F9 ships. Each is a raw associative
	 * array that gets validated into a {@see Outpost_Manual_Share_Platform_Config}
	 * during resolution.
	 *
	 * Per-platform quirks per concepts/posse-outbound-may-2026.md §4.4:
	 *
	 *   - Facebook EXTRA_TEXT has been silently ignored by the official
	 *     app since 2014 — caption goes to clipboard only.
	 *   - X (Twitter) honors EXTRA_TEXT on Android; iOS uses a web intent
	 *     for the caption since native intent paths are unreliable.
	 *   - LinkedIn honors EXTRA_TEXT on Android; iOS uses clipboard.
	 *   - Threads' Android package is com.instagram.barcelona (Meta's
	 *     internal codename); iOS uses threads.net/intent/post.
	 *   - TikTok's Android package is com.zhiliaoapp.musically (legacy
	 *     ByteDance namespace); caption clipboard only.
	 *   - Pinterest's web intent works on both platforms; Android also
	 *     honors EXTRA_TEXT.
	 *   - Reddit-manual and Flickr-manual ship with prefers_bridgy=true
	 *     so F14's Companion_BridgyPublish becomes the preferred route.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function default_configs(): array {
		return array(
			array(
				'id'             => 'instagram-feed',
				'label'          => __( 'Instagram', 'outpost' ),
				'icon'           => 'instagram',
				'accepts_modes'  => array( 'photo', 'gallery' ),
				'accepts_media'  => array( 'image', 'video' ),
				'caption_via'    => 'clipboard',
				'ios_strategy'   => 'navigator_share_files',
				'ios_url'        => null,
				'android_action' => 'android.intent.action.SEND',
				'android_pkg'    => 'com.instagram.android',
				'android_mime'   => 'image/*',
				'android_extras' => array(
					'EXTRA_STREAM' => '@image_uri',
					'EXTRA_TEXT'   => '@caption',
				),
				'web_intent_url' => null,
				'after_share'    => 'prompt_for_silo_url',
				'caveats'        => array(
					__( 'Caption is copied to clipboard; paste in app.', 'outpost' ),
				),
			),
			array(
				'id'             => 'instagram-stories',
				'label'          => __( 'Instagram Stories', 'outpost' ),
				'icon'           => 'instagram-stories',
				'accepts_modes'  => array( 'photo', 'gallery' ),
				'accepts_media'  => array( 'image', 'video' ),
				'caption_via'    => 'clipboard',
				'ios_strategy'   => 'navigator_share_files',
				'ios_url'        => 'instagram-stories://share',
				'android_action' => 'com.instagram.share.ADD_TO_STORY',
				'android_pkg'    => 'com.instagram.android',
				'android_mime'   => 'image/*',
				'android_extras' => array(
					'interactive_asset_uri' => '@image_uri',
				),
				'web_intent_url' => null,
				'after_share'    => 'mark_done',
				'caveats'        => array(
					__( 'Stories cannot accept captions via intent; type in app after share.', 'outpost' ),
				),
			),
			array(
				'id'             => 'facebook',
				'label'          => __( 'Facebook', 'outpost' ),
				'icon'           => 'facebook',
				'accepts_modes'  => array( 'photo', 'gallery' ),
				'accepts_media'  => array( 'image', 'video' ),
				'caption_via'    => 'clipboard',
				'ios_strategy'   => 'navigator_share_files',
				'ios_url'        => null,
				'android_action' => 'android.intent.action.SEND',
				'android_pkg'    => 'com.facebook.katana',
				'android_mime'   => 'image/*',
				'android_extras' => array(
					'EXTRA_STREAM' => '@image_uri',
				),
				'web_intent_url' => null,
				'after_share'    => 'prompt_for_silo_url',
				'caveats'        => array(
					__( 'Facebook ignores EXTRA_TEXT in shares since 2014; caption is copied to clipboard for manual paste.', 'outpost' ),
				),
			),
			array(
				'id'             => 'x-twitter',
				'label'          => __( 'X', 'outpost' ),
				'icon'           => 'x-twitter',
				'accepts_modes'  => array( 'photo', 'gallery' ),
				'accepts_media'  => array( 'image', 'video' ),
				'caption_via'    => 'intent',
				'ios_strategy'   => 'web_intent_with_clipboard_image',
				'ios_url'        => 'https://twitter.com/intent/tweet?text=@caption_encoded',
				'android_action' => 'android.intent.action.SEND',
				'android_pkg'    => 'com.twitter.android',
				'android_mime'   => 'image/*',
				'android_extras' => array(
					'EXTRA_STREAM' => '@image_uri',
					'EXTRA_TEXT'   => '@caption',
				),
				'web_intent_url' => 'https://twitter.com/intent/tweet?text=@caption_encoded',
				'after_share'    => 'prompt_for_silo_url',
				'caveats'        => array(
					__( 'On iOS the caption arrives via web intent; the image attaches separately from the share sheet.', 'outpost' ),
				),
			),
			array(
				'id'             => 'linkedin',
				'label'          => __( 'LinkedIn', 'outpost' ),
				'icon'           => 'linkedin',
				'accepts_modes'  => array( 'photo', 'gallery' ),
				'accepts_media'  => array( 'image', 'video' ),
				'caption_via'    => 'intent',
				'ios_strategy'   => 'navigator_share_files_clipboard_caption',
				'ios_url'        => null,
				'android_action' => 'android.intent.action.SEND',
				'android_pkg'    => 'com.linkedin.android',
				'android_mime'   => 'image/*',
				'android_extras' => array(
					'EXTRA_STREAM' => '@image_uri',
					'EXTRA_TEXT'   => '@caption',
				),
				'web_intent_url' => null,
				'after_share'    => 'prompt_for_silo_url',
				'caveats'        => array(
					__( 'On iOS the caption goes to clipboard; paste it into the LinkedIn share dialog.', 'outpost' ),
				),
			),
			array(
				'id'             => 'threads',
				'label'          => __( 'Threads', 'outpost' ),
				'icon'           => 'threads',
				'accepts_modes'  => array( 'photo', 'gallery' ),
				'accepts_media'  => array( 'image', 'video' ),
				'caption_via'    => 'web_intent',
				'ios_strategy'   => 'web_intent',
				'ios_url'        => 'https://www.threads.net/intent/post?text=@caption_encoded',
				'android_action' => 'android.intent.action.SEND',
				'android_pkg'    => 'com.instagram.barcelona',
				'android_mime'   => 'image/*',
				'android_extras' => array(
					'EXTRA_STREAM' => '@image_uri',
					'EXTRA_TEXT'   => '@caption',
				),
				'web_intent_url' => 'https://www.threads.net/intent/post?text=@caption_encoded',
				'after_share'    => 'prompt_for_silo_url',
				'caveats'        => array(),
			),
			array(
				'id'             => 'tiktok',
				'label'          => __( 'TikTok', 'outpost' ),
				'icon'           => 'tiktok',
				'accepts_modes'  => array( 'photo', 'gallery' ),
				'accepts_media'  => array( 'video' ),
				'caption_via'    => 'clipboard',
				'ios_strategy'   => 'navigator_share_files',
				'ios_url'        => null,
				'android_action' => 'android.intent.action.SEND',
				'android_pkg'    => 'com.zhiliaoapp.musically',
				'android_mime'   => 'video/*',
				'android_extras' => array(
					'EXTRA_STREAM' => '@image_uri',
				),
				'web_intent_url' => null,
				'after_share'    => 'mark_done',
				'caveats'        => array(
					__( 'TikTok ignores caption intents; copy/paste in the app.', 'outpost' ),
				),
			),
			array(
				'id'             => 'pinterest',
				'label'          => __( 'Pinterest', 'outpost' ),
				'icon'           => 'pinterest',
				'accepts_modes'  => array( 'photo', 'gallery' ),
				'accepts_media'  => array( 'image' ),
				'caption_via'    => 'intent',
				'ios_strategy'   => 'web_intent',
				'ios_url'        => 'https://www.pinterest.com/pin/create/button/?url=@source_url&media=@image_url&description=@caption_encoded',
				'android_action' => 'android.intent.action.SEND',
				'android_pkg'    => 'com.pinterest',
				'android_mime'   => 'image/*',
				'android_extras' => array(
					'EXTRA_STREAM' => '@image_uri',
					'EXTRA_TEXT'   => '@caption',
				),
				'web_intent_url' => 'https://www.pinterest.com/pin/create/button/?url=@source_url&media=@image_url&description=@caption_encoded',
				'after_share'    => 'prompt_for_silo_url',
				'caveats'        => array(),
			),
			array(
				'id'             => 'reddit-manual',
				'label'          => __( 'Reddit (manual)', 'outpost' ),
				'icon'           => 'reddit',
				'accepts_modes'  => array( 'photo', 'gallery' ),
				'accepts_media'  => array( 'image' ),
				'caption_via'    => 'web_intent',
				'ios_strategy'   => 'web_intent',
				'ios_url'        => 'https://www.reddit.com/submit?url=@source_url&title=@caption_encoded',
				'android_action' => 'android.intent.action.SEND',
				'android_pkg'    => 'com.reddit.frontpage',
				'android_mime'   => 'image/*',
				'android_extras' => array(
					'EXTRA_STREAM' => '@image_uri',
					'EXTRA_TEXT'   => '@caption',
				),
				'web_intent_url' => 'https://www.reddit.com/submit?url=@source_url&title=@caption_encoded',
				'after_share'    => 'prompt_for_silo_url',
				'caveats'        => array(
					__( 'Pick the subreddit in the app after the share intent fires. Bridgy Publish is the preferred route when configured.', 'outpost' ),
				),
				'prefers_bridgy' => true,
			),
			array(
				'id'             => 'flickr-manual',
				'label'          => __( 'Flickr (manual)', 'outpost' ),
				'icon'           => 'flickr',
				'accepts_modes'  => array( 'photo', 'gallery' ),
				'accepts_media'  => array( 'image' ),
				'caption_via'    => 'clipboard',
				'ios_strategy'   => 'navigator_share_files',
				'ios_url'        => null,
				'android_action' => 'android.intent.action.SEND',
				'android_pkg'    => 'com.yahoo.mobile.client.android.flickr',
				'android_mime'   => 'image/*',
				'android_extras' => array(
					'EXTRA_STREAM' => '@image_uri',
				),
				'web_intent_url' => null,
				'after_share'    => 'prompt_for_silo_url',
				'caveats'        => array(
					__( 'Caption is copied to clipboard; paste in app. Bridgy Publish is the preferred route when configured.', 'outpost' ),
				),
				'prefers_bridgy' => true,
			),
		);
	}
}
