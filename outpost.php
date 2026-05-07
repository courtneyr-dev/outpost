<?php
/**
 * Plugin Name:       Outpost
 * Plugin URI:        https://github.com/courtneyr-dev/outpost
 * Description:       Mobile-first Progressive Web App composer for IndieWeb POSSE workflows. Post notes, replies, likes, photos, and life-tracking entries from your phone, with one-tap syndication. Requires the Micropub plugin.
 * Version:           0.1.80
 * Requires at least: 6.5
 * Tested up to:      6.9
 * Requires PHP:      8.2
 * Author:            Courtney Robertson
 * Author URI:        https://courtneyr.dev
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       outpost
 * Domain Path:       /languages
 *
 * @package Outpost
 */

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin metadata constants.
define( 'OUTPOST_VERSION', '0.1.80' );
define( 'OUTPOST_PLUGIN_FILE', __FILE__ );
define( 'OUTPOST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OUTPOST_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OUTPOST_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Requirements.
define( 'OUTPOST_MIN_WP', '6.5' );
define( 'OUTPOST_MIN_PHP', '8.2' );

// Companion plugin file paths (for is_plugin_active checks).
// Required chain (upstream-first; see Outpost_Companion_Detector::dependency_chain).
define( 'OUTPOST_INDIEAUTH_PLUGIN_FILE', 'indieauth/indieauth.php' );
define( 'OUTPOST_MICROPUB_PLUGIN_FILE', 'micropub/micropub.php' );

// Optional companions (enable feature surfaces; never block the gate).
define( 'OUTPOST_POST_KINDS_PLUGIN_FILE', 'post-kinds-for-indieweb/post-kinds-for-indieweb.php' );
define( 'OUTPOST_POST_FORMATS_PLUGIN_FILE', 'post-formats-for-block-themes/post-formats-for-block-themes.php' );
define( 'OUTPOST_LINK_EXTENSION_XFN_PLUGIN_FILE', 'link-extension-for-xfn/link-extension-for-xfn.php' );
define( 'OUTPOST_SYNDICATION_LINKS_PLUGIN_FILE', 'syndication-links/syndication-links.php' );
// Yoast: slug is `wordpress-seo` but the main file is `wp-seo.php`.
define( 'OUTPOST_YOAST_PLUGIN_FILE', 'wordpress-seo/wp-seo.php' );
define( 'OUTPOST_ACTIVITYPUB_PLUGIN_FILE', 'activitypub/activitypub.php' );
define( 'OUTPOST_ACCESSIBILITY_CHECKER_PLUGIN_FILE', 'accessibility-checker/accessibility-checker.php' );

// Load the detector class and the companion-adapter base class up front so the
// rest of this bootstrap file can stay procedural shims that delegate to them.
require_once OUTPOST_PLUGIN_DIR . 'includes/class-companion-detector.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-companion-base.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-post-kinds-adapter.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-post-formats-adapter.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-xfn-adapter.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-syndication-links-adapter.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-yoast-adapter.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-activitypub-adapter.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-accessibility-checker-adapter.php';
// Phase F9 manual-share umbrella + supporting value objects;
// F10 adds the audit log + intent payload builder; F12 adds the
// phase-2 capture detector + syndication writeback.
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/manual-share/class-invalid-config-exception.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/manual-share/class-platform-config.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/manual-share/class-platform-registry.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/manual-share/class-audit-log.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/manual-share/class-intent-payload-builder.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/manual-share/class-pending-capture-detector.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/manual-share/class-syndication-writeback.php';
// Phase F13 audit-log surfacing helpers.
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/manual-share/class-status-computer.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/manual-share/class-reminder-manager.php';
// Phase F14 Bridgy Publish companion + supporting classes.
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/bridgy-publish/class-invalid-config-exception.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/bridgy-publish/class-silo-config.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/bridgy-publish/class-silo-registry.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/bridgy-publish/class-silo-detector.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/bridgy-publish/class-bridgy-settings.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/bridgy-publish/class-publish-log.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/bridgy-publish/class-webmention-response-handler.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-manual-share-adapter.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-bridgy-publish-adapter.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-companion-registry.php';
// Inbound source-adapter contract (Phase F5). Mirrors Companion_* with
// the inbound direction; concrete sources land in F7+.
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/exceptions/class-extractor-not-implemented-exception.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/extractors/class-extractor-base.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/extractors/class-extractor-oembed.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/extractors/class-extractor-og-tags.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/extractors/class-extractor-mf2.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/extractors/class-extractor-rss.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/extractors/class-extractor-api-json.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/extractors/class-extractor-api-xml.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/extractors/class-extractor-composite.php';
// G4a — Adapter primitives. Higher-level than F-phase per-source extractors:
// Og_Inbound wraps fetch + OG + JSON-LD parse + category dispatch;
// Composite_Inbound wraps multi-source merging with primary/fallback/enrich roles.
// Concrete schema extractors (Recipe / Event / Article / Book / Restaurant)
// land in G4b — register via Outpost_Og_Inbound::register_extractor.
require_once OUTPOST_PLUGIN_DIR . 'includes/adapters/primitives/interface-schema-extractor.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/adapters/primitives/class-og-inbound.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/adapters/primitives/class-composite-inbound.php';
// G4b concrete schema extractors. Auto-register on plugins_loaded below.
require_once OUTPOST_PLUGIN_DIR . 'includes/adapters/primitives/extractors/trait-schema-helpers.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/adapters/primitives/extractors/class-article-extractor.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/adapters/primitives/extractors/class-recipe-extractor.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/adapters/primitives/extractors/class-event-extractor.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/adapters/primitives/extractors/class-book-extractor.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/adapters/primitives/extractors/class-restaurant-extractor.php';
// G4b composite-primitive demo: Apple Music + iTunes Lookup enrichment.
require_once OUTPOST_PLUGIN_DIR . 'includes/adapters/class-apple-music-adapter.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-base.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-unknown.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-registry.php';
// Phase F6 dispatcher + share-target / Shortcut bridge controllers.
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-detector.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-share-target-controller.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-shortcut-controller.php';
// Phase F7 first concrete inbound source.
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-spotify.php';
// Phase F15 second concrete inbound source.
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-youtube.php';
// Phase F16 og_tags-based bulk-coverage sources.
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-snipd.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-twitch.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-goodreads.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-lastfm.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-readwise.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-amazon.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-pinterest.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-tiktok.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-vimeo.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-soundcloud.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-applemusic.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-applepodcasts.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-bandcamp.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-substack.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-medium.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-reddit.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-mastodon.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-bluesky.php';
// G3.5a — Encryption + credentials store + OAuth foundation + Notion (proof-of-concept consumer).
require_once OUTPOST_PLUGIN_DIR . 'includes/encryption/class-outpost-encryption-exception.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/encryption/class-outpost-encryption-key-resolver.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/encryption/class-outpost-encryption.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/credentials/class-outpost-credentials-store.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/oauth/class-outpost-oauth-state.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/oauth/class-outpost-oauth-provider-base.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/oauth/providers/class-outpost-oauth-provider-notion.php';
// G11a — Oura OAuth provider (membership-gated wellness data).
require_once OUTPOST_PLUGIN_DIR . 'includes/oauth/providers/class-outpost-oauth-provider-oura.php';
// G12a — Ride With GPS OAuth provider (cycling activity capture).
require_once OUTPOST_PLUGIN_DIR . 'includes/oauth/providers/class-outpost-oauth-provider-ridewithgps.php';
// G14b — Ravelry OAuth provider (knit/crochet patterns + projects).
require_once OUTPOST_PLUGIN_DIR . 'includes/oauth/providers/class-outpost-oauth-provider-ravelry.php';
// G11b — WHOOP wellness OAuth provider.
require_once OUTPOST_PLUGIN_DIR . 'includes/oauth/providers/class-outpost-oauth-provider-whoop.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/oauth/class-outpost-oauth-controller.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/notion/class-outpost-notion-blocks-converter.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-outpost-source-notion.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/admin/class-outpost-encryption-key-notice.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/admin/class-outpost-oauth-settings-page.php';
// G3.5d — Multi-tab settings UI foundation.
require_once OUTPOST_PLUGIN_DIR . 'includes/settings/class-outpost-settings-registry.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/settings/class-outpost-settings-fields.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/settings/class-outpost-settings-handler.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/settings/class-outpost-settings-page.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/settings/tabs/class-outpost-settings-tab-api-keys.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/companions/class-perfmatters-defang.php';
// G3.5b — POSSE-outbound foundation (base class + dispatcher + meta + registry).
// No concrete destinations registered yet; G5 ships the first ones.
require_once OUTPOST_PLUGIN_DIR . 'includes/posse/class-outpost-posse-destination-base.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/posse/class-outpost-posse-meta.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/posse/class-outpost-posse-registry.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/posse/class-outpost-posse-dispatcher.php';
// G10a — scripture inbound (og_tags-only; api/license/translator-aware paths in G10b).
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-sefaria.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-suttacentral.php';
// G14a — iFixit repair-guide inbound (og_tags-only; full api_json integration in G14b).
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-ifixit.php';
// G13a — Pretalx hosted SaaS conference inbound (og_tags-only;
// self-hosted Pretalx and Sessionize wait on settings-UI foundation).
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-pretalx.php';
// G9 outbound adapter: Telegraph (telegra.ph) — anonymous publishing.
require_once OUTPOST_PLUGIN_DIR . 'includes/adapters/outbound/class-telegraph-adapter.php';
// G6 minimalist-blog inbound (og_tags-only; RSS-as-primary deferred to RSS extractor session).
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-bear-blog.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/sources/class-source-mataroa.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-route-handler.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-pwa-assets.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-pwa-shell.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-preview-endpoint.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-composer-config-endpoint.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-geocode-endpoint.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-syndicate-targets-endpoint.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-manual-share-controller.php';
// Phase F12 phase-2 capture surfaces.
require_once OUTPOST_PLUGIN_DIR . 'includes/class-syndication-capture-controller.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-syndication-links-renderer.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-pending-syndication-notice.php';
// Phase F13 audit-log surfacing.
require_once OUTPOST_PLUGIN_DIR . 'includes/class-syndication-admin-column.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-manual-share-status-controller.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-micropub-bridges.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-settings.php';
// FX iOS Shortcut bridge.
require_once OUTPOST_PLUGIN_DIR . 'includes/auth/class-ios-shortcut-token.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/auth/class-ios-shortcut-token-authenticator.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-ios-shortcut-rest-controller.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/admin/class-ios-shortcut-settings-page.php';
// FY Theming — token resolver, theme.json reader, contrast, mode controller.
require_once OUTPOST_PLUGIN_DIR . 'includes/theming/class-contrast.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/theming/class-theme-json-reader.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/theming/class-mode-controller.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/theming/class-token-resolver.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/class-appearance-rest-controller.php';
require_once OUTPOST_PLUGIN_DIR . 'includes/admin/class-appearance-settings-page.php';

/**
 * Wire up Outpost's component registrations at `init` priority 0.
 *
 * Each `::register()` method below only calls `add_action`/`add_filter` —
 * it doesn't translate or otherwise touch i18n. So calling them at file
 * scope works today. But WordPress 6.7 added `_load_textdomain_just_in_time`
 * which emits a `_doing_it_wrong` notice for any `__()` call before `init`.
 * The notice flushes the output buffer, which breaks login (cookies can't
 * set if headers were already sent). Post Kinds for IndieWeb hit exactly
 * this trap during PR #31's CI green-up (2026-05-04) — translation-touching
 * component constructors fired at `plugins_loaded`, login broke on staging.
 *
 * Deferring to `init` priority 0 is purely defensive: if a future
 * `register()` ever starts touching translated strings (a settings field
 * label, a CPT register call, etc.), it lands AT `init` instead of before
 * it, and the JIT trap never fires.
 *
 * Priority 0 keeps these registrations ahead of any priority-10
 * `init` callbacks they might be a prerequisite for.
 */
add_action(
	'init',
	static function () {
		// Register the /wp-json/outpost/v1/preview REST route (Phase B2).
		Outpost_Preview_Endpoint::register();
		// Register the /wp-json/outpost/v1/composer-config REST route (Phase C5).
		Outpost_Composer_Config_Endpoint::register();
		// Register the /wp-json/outpost/v1/geocode REST route (Checkin coordinates).
		Outpost_Geocode_Endpoint::register();
		// Register the /wp-json/outpost/v1/syndicate-targets REST route (Phase F2).
		Outpost_Syndicate_Targets_Endpoint::register();
		// Register the /wp-json/outpost/v1/manual-share-* REST routes (Phase F9).
		Outpost_Manual_Share_Controller::register();
		// Phase F12 phase-2 capture flow + syndication-link rendering.
		Outpost_Syndication_Capture_Controller::register();
		Outpost_Syndication_Links_Renderer::register();
		Outpost_Pending_Syndication_Notice::register();
		// Phase F13 audit-log surfacing — status controller + admin column.
		Outpost_Manual_Share_Status_Controller::register();
		Outpost_Syndication_Admin_Column::register();
		// Expose outpost_syndication_links via WP REST so other tools
		// can read syndication state programmatically. Uses
		// register_post_meta with show_in_rest = full schema.
		register_post_meta(
			'',
			'outpost_syndication_links',
			array(
				'type'          => 'array',
				'description'   => 'Outpost manual-share syndication links (Phase F12).',
				'single'        => true,
				'show_in_rest'  => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'platform_id' => array( 'type' => 'string' ),
								'url'         => array( 'type' => 'string' ),
								'added_at'    => array( 'type' => 'string' ),
								'source'      => array( 'type' => 'string' ),
							),
						),
					),
				),
				'auth_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
		// Hook the Micropub bridges (Yoast focus keyphrase, post format, XFN) (Phase C5).
		Outpost_Micropub_Bridges::register();
		// Register the wp-admin Outpost menu + bookmarklet generator page (Phase E1).
		Outpost_Admin_Page::register();
		// Register Settings API options (Phase H).
		Outpost_Settings::register();
		// FX iOS Shortcut bridge — REST endpoint + auth filter + admin page.
		Outpost_IOS_Shortcut_Token_Authenticator::register();
		Outpost_IOS_Shortcut_REST_Controller::register();
		Outpost_IOS_Shortcut_Settings_Page::register();
		// FY Theming — REST endpoint + appearance settings sub-menu.
		Outpost_Appearance_REST_Controller::register();
		Outpost_Appearance_Settings_Page::register();
	},
	0
);

/**
 * Register concrete inbound source adapters at the F5
 * `outpost_sources_init` action. Concrete sources MUST register
 * before `Outpost_Source_Registry::ensure_bootstrapped` appends
 * `Outpost_Source_Unknown` as the trailing fallback — the registry
 * fires this action from within `ensure_bootstrapped` precisely so
 * concrete sources land first and exact-host matches win over the
 * universal `*` fallback.
 *
 * F7 ships Source_Spotify. Future Phase F sessions add YouTube
 * (F15), the og_tags-driven Source_Unknown end-to-end (F16), then
 * the rest of the inbound shipping queue (concepts/capture-inbound-may-2026.md
 * §7 / posse-outbound-may-2026.md §7's parallel inbound list).
 */
add_action(
	'outpost_sources_init',
	static function () {
		Outpost_Source_Registry::register( new Outpost_Source_Spotify() );
		Outpost_Source_Registry::register( new Outpost_Source_YouTube() );
		// F16 OG-tags-based bulk-coverage sources.
		Outpost_Source_Registry::register( new Outpost_Source_Snipd() );
		Outpost_Source_Registry::register( new Outpost_Source_Twitch() );
		Outpost_Source_Registry::register( new Outpost_Source_Goodreads() );
		Outpost_Source_Registry::register( new Outpost_Source_LastFm() );
		Outpost_Source_Registry::register( new Outpost_Source_Readwise() );
		Outpost_Source_Registry::register( new Outpost_Source_Amazon() );
		Outpost_Source_Registry::register( new Outpost_Source_Pinterest() );
		Outpost_Source_Registry::register( new Outpost_Source_TikTok() );
		// F17 Tier 1 (oEmbed) + Tier 2 (og_tags) bulk-coverage adapters.
		Outpost_Source_Registry::register( new Outpost_Source_Vimeo() );
		Outpost_Source_Registry::register( new Outpost_Source_SoundCloud() );
		Outpost_Source_Registry::register( new Outpost_Source_AppleMusic() );
		Outpost_Source_Registry::register( new Outpost_Source_ApplePodcasts() );
		Outpost_Source_Registry::register( new Outpost_Source_Bandcamp() );
		Outpost_Source_Registry::register( new Outpost_Source_Substack() );
		Outpost_Source_Registry::register( new Outpost_Source_Medium() );
		Outpost_Source_Registry::register( new Outpost_Source_Reddit() );
		Outpost_Source_Registry::register( new Outpost_Source_Mastodon() );
		Outpost_Source_Registry::register( new Outpost_Source_Bluesky() );
		// G3.5a Notion source (auth-required; uses OAuth credentials store).
		Outpost_Source_Registry::register( new Outpost_Source_Notion() );
		// G10a scripture inbound (og_tags-only batch).
		Outpost_Source_Registry::register( new Outpost_Source_Sefaria() );
		Outpost_Source_Registry::register( new Outpost_Source_SuttaCentral() );
		// G14a iFixit repair-guide inbound (og_tags-only).
		Outpost_Source_Registry::register( new Outpost_Source_Ifixit() );
		// G13a Pretalx hosted SaaS conference inbound.
		Outpost_Source_Registry::register( new Outpost_Source_Pretalx() );
		// G6 — minimalist-blog inbound capture sources.
		Outpost_Source_Registry::register( new Outpost_Source_Bear_Blog() );
		Outpost_Source_Registry::register( new Outpost_Source_Mataroa() );
	}
);

// G3.5a — Wire OAuth foundation + admin pieces into WordPress.
add_action(
	'plugins_loaded',
	static function () {
		Outpost_OAuth_Controller::add_provider( new Outpost_OAuth_Provider_Notion() );
		// G11a — Oura wellness OAuth provider.
		Outpost_OAuth_Controller::add_provider( new Outpost_OAuth_Provider_Oura() );
		// G12a — Ride With GPS cycling OAuth provider.
		Outpost_OAuth_Controller::add_provider( new Outpost_OAuth_Provider_Ridewithgps() );
		// G14b — Ravelry knit/crochet OAuth provider.
		Outpost_OAuth_Controller::add_provider( new Outpost_OAuth_Provider_Ravelry() );
		// G11b — WHOOP wellness OAuth provider.
		Outpost_OAuth_Controller::add_provider( new Outpost_OAuth_Provider_Whoop() );
		Outpost_OAuth_Controller::register();
		Outpost_OAuth_Settings_Page::register();
		// G3.5d — Multi-tab settings UI.
		Outpost_Settings_Page::register();
		Outpost_Settings_Handler::register();
		// G3.5b — POSSE foundation: register meta keys + cron handler.
		Outpost_POSSE_Meta::register();
		Outpost_POSSE_Dispatcher::register();
		Outpost_Encryption_Key_Notice::register();
		// Defang Perfmatters' Delay JS / Lazy CSS / Remove Unused CSS on
		// Outpost PWA routes — those features postpone JS/CSS until first
		// interaction and break the composer's first-paint mounting.
		Outpost_Perfmatters_Defang::register();
		// G4b — Auto-register the five built-in JSON-LD schema extractors
		// so Og_Inbound dispatches to them when matching @type values
		// surface in fetched HTML. Site owners can extend or override
		// via the `outpost_og_extractors` filter.
		Outpost_Og_Inbound::register_extractor( new Outpost_Article_Extractor() );
		Outpost_Og_Inbound::register_extractor( new Outpost_Recipe_Extractor() );
		Outpost_Og_Inbound::register_extractor( new Outpost_Event_Extractor() );
		Outpost_Og_Inbound::register_extractor( new Outpost_Book_Extractor() );
		Outpost_Og_Inbound::register_extractor( new Outpost_Restaurant_Extractor() );
	},
	5
);

/**
 * Check whether the host environment meets the plugin's minimum requirements.
 *
 * @since 0.1.0
 *
 * @return bool True when both WP and PHP versions meet the floor.
 */
function outpost_meets_requirements(): bool {
	return version_compare( get_bloginfo( 'version' ), OUTPOST_MIN_WP, '>=' )
		&& version_compare( PHP_VERSION, OUTPOST_MIN_PHP, '>=' );
}

/**
 * Detect the registration state of any companion plugin by its main file path.
 *
 * Thin shim around {@see Outpost_Companion_Detector::status()}. Kept as a
 * procedural wrapper because earlier sessions and external callers reference
 * it; new code should call the detector class directly.
 *
 * @since 0.1.0
 *
 * @param string $plugin_file Plugin main file path relative to wp-content/plugins/.
 * @return string One of 'active', 'inactive', or 'absent'.
 */
function outpost_companion_plugin_status( string $plugin_file ): string {
	return Outpost_Companion_Detector::status( $plugin_file );
}

/**
 * Detect the state of the IndieAuth companion plugin.
 *
 * @since 0.1.0
 *
 * @return string One of 'active', 'inactive', or 'absent'.
 */
function outpost_indieauth_status(): string {
	return Outpost_Companion_Detector::is_indieauth_active();
}

/**
 * Detect the state of the Micropub companion plugin.
 *
 * @since 0.1.0
 *
 * @return string One of 'active', 'inactive', or 'absent'.
 */
function outpost_micropub_status(): string {
	return Outpost_Companion_Detector::is_micropub_active();
}

/**
 * Whether the plugin's full feature surface (PWA composer, REST endpoints) is available.
 *
 * Both required companions must be active. The Micropub plugin hard-requires
 * IndieAuth at its own preflight, so a Micropub-active-but-IndieAuth-missing
 * environment is functionally broken from Outpost's perspective. The dependency
 * order lives in {@see Outpost_Companion_Detector::dependency_chain()}.
 *
 * @since 0.1.0
 *
 * @return bool True only when host requirements are met and the dependency chain is fully satisfied.
 */
function outpost_is_ready(): bool {
	return outpost_meets_requirements()
		&& null === Outpost_Companion_Detector::first_unsatisfied();
}

/**
 * Resolve a dependency-chain plugin file to its presentation pair (label + wp.org slug).
 *
 * Returns `array( 'label' => string, 'slug' => string )` for known dependency
 * files and `null` for unknown ones. The map is filterable through
 * `outpost_dependency_presentation` so future chain extensions and third-party
 * plugins can register their own labels/slugs without editing this file.
 *
 * Two consumers share this helper today: the admin notice path
 * (`outpost_render_admin_notices()`) and the PWA install-prompt page rendered
 * by `Outpost_PWA_Shell`. Keeping the source-of-truth in one place stops the
 * two surfaces from drifting.
 *
 * @since 0.1.0
 *
 * @param string $plugin_file Plugin main file path relative to wp-content/plugins/.
 * @return array{label:string,slug:string}|null Presentation pair or null when the
 *                                              file isn't a known dependency.
 */
function outpost_dependency_presentation( string $plugin_file ): ?array {
	$map = array(
		OUTPOST_INDIEAUTH_PLUGIN_FILE => array(
			'label' => 'IndieAuth',
			'slug'  => 'indieauth',
		),
		OUTPOST_MICROPUB_PLUGIN_FILE  => array(
			'label' => 'Micropub',
			'slug'  => 'micropub',
		),
	);

	/**
	 * Filter the dependency presentation map.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array{label:string,slug:string}> $map Map of plugin file
	 *        path to presentation pair. Keys are OUTPOST_*_PLUGIN_FILE values; values
	 *        are arrays with `label` (untranslated brand name) and `slug` (wp.org
	 *        plugin slug for the install link).
	 */
	$map = apply_filters( 'outpost_dependency_presentation', $map );

	return $map[ $plugin_file ] ?? null;
}

/**
 * Render a single dependency notice with an action button.
 *
 * @since 0.1.0
 *
 * @param string $plugin_label Human-readable plugin name (untranslated; brand name).
 * @param string $plugin_file  Plugin main file path relative to wp-content/plugins/.
 * @param string $wporg_slug   WordPress.org plugin slug for the install link.
 * @param string $status       'inactive' or 'absent' (caller filters out 'active').
 */
function outpost_render_dependency_notice( string $plugin_label, string $plugin_file, string $wporg_slug, string $status ): void {
	if ( 'inactive' === $status ) {
		$action_url   = wp_nonce_url(
			self_admin_url( 'plugins.php?action=activate&plugin=' . $plugin_file ),
			'activate-plugin_' . $plugin_file
		);
		$action_label = sprintf(
			/* translators: %s: plugin name. */
			__( 'Activate %s', 'outpost' ),
			$plugin_label
		);
		$message = sprintf(
			/* translators: %s: plugin name. */
			__( 'Outpost needs the %s plugin to be activated before the composer can run.', 'outpost' ),
			$plugin_label
		);
	} else {
		$action_url   = wp_nonce_url(
			self_admin_url( 'update.php?action=install-plugin&plugin=' . $wporg_slug ),
			'install-plugin_' . $wporg_slug
		);
		$action_label = sprintf(
			/* translators: %s: plugin name. */
			__( 'Install %s', 'outpost' ),
			$plugin_label
		);
		$message = sprintf(
			/* translators: %s: plugin name. */
			__( 'Outpost requires the %s plugin. Install it from WordPress.org to continue.', 'outpost' ),
			$plugin_label
		);
	}

	printf(
		'<div class="notice notice-warning"><p>%1$s &nbsp; <a class="button button-primary" href="%2$s">%3$s</a></p></div>',
		esc_html( $message ),
		esc_url( $action_url ),
		esc_html( $action_label )
	);
}

/**
 * Render the dependency-status admin notice on every admin screen.
 *
 * Hybrid gate: plugin loads regardless, but warns when a required dependency
 * is missing or inactive. Notices surface in upstream-first order driven by
 * {@see Outpost_Companion_Detector::first_unsatisfied()}; host requirements
 * are checked before the chain so an unsupported PHP/WP version doesn't get
 * masked by a missing companion.
 *
 * Optional companions (Post Kinds, Yoast, ActivityPub, etc.) never produce a
 * notice here — their absence reduces feature surface without breaking the
 * gate. Admins without `install_plugins` capability see nothing.
 *
 * @since 0.1.0
 */
function outpost_render_admin_notices(): void {
	if ( ! current_user_can( 'install_plugins' ) ) {
		return;
	}

	if ( ! outpost_meets_requirements() ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: minimum WP version, 2: minimum PHP version. */
					__( 'Outpost requires WordPress %1$s or newer and PHP %2$s or newer.', 'outpost' ),
					OUTPOST_MIN_WP,
					OUTPOST_MIN_PHP
				)
			)
		);
		return;
	}

	$blocker = Outpost_Companion_Detector::first_unsatisfied();
	if ( null === $blocker ) {
		return;
	}

	$presentation = outpost_dependency_presentation( $blocker );
	if ( null === $presentation ) {
		// If dependency_chain() ever extends without a matching presentation entry
		// the notice would silently disappear. Surface it via Query Monitor's
		// doing_it_wrong panel instead so the gap can't hide. esc_html() is
		// defensive — $blocker comes from dependency_chain()'s known-safe set,
		// but the message reaches the doing_it_wrong panel which renders HTML.
		_doing_it_wrong(
			__FUNCTION__,
			esc_html( 'Dependency chain entry has no presentation mapping: ' . $blocker ),
			'0.1.0'
		);
		return;
	}

	outpost_render_dependency_notice(
		$presentation['label'],
		$blocker,
		$presentation['slug'],
		Outpost_Companion_Detector::status( $blocker )
	);
}
add_action( 'admin_notices', 'outpost_render_admin_notices' );

/**
 * Register the plugin's row meta link to the bookmarklet generator (added in Phase E).
 *
 * Stub for Session A0; expanded in later sessions.
 *
 * @since 0.1.0
 *
 * @param string[] $links Plugin action links.
 * @return string[] Filtered action links.
 */
function outpost_filter_plugin_action_links( array $links ): array {
	// Future sessions add a "Settings" and "Bookmarklets" link here.
	return $links;
}
add_filter( 'plugin_action_links_' . OUTPOST_PLUGIN_BASENAME, 'outpost_filter_plugin_action_links' );

/**
 * Wire the route handler on each page load so /post/* requests get routed.
 *
 * Hooks register on `init` (rewrite rules + query var) and `template_redirect`
 * (dispatch). See {@see Outpost_Route_Handler::init()} for details.
 *
 * @since 0.1.0
 */
Outpost_Route_Handler::init();

/**
 * One-shot rewrite-rule flush on version change.
 *
 * The activation hook only runs when an admin clicks "Activate" — not when a
 * deploy/update replaces the plugin file in place. Without this guard the
 * route table from the previous version stays cached in `rewrite_rules`
 * and `/post/*` URLs fall through to canonical-redirect into unrelated
 * permalinks.
 *
 * Compares the running OUTPOST_VERSION against the value stashed in
 * `outpost_rewrite_version`. On mismatch, re-registers and flushes once,
 * then writes the new version. Idempotent — registers/flushes do nothing on
 * subsequent requests.
 *
 * Hooked at priority 11 so it runs immediately after Outpost_Route_Handler's
 * own register_rewrite_rules call (default priority 10).
 *
 * @since 0.1.0
 */
function outpost_maybe_flush_rewrite_rules(): void {
	if ( get_option( 'outpost_rewrite_version' ) === OUTPOST_VERSION ) {
		return;
	}
	flush_rewrite_rules();
	update_option( 'outpost_rewrite_version', OUTPOST_VERSION );
}
add_action( 'init', 'outpost_maybe_flush_rewrite_rules', 11 );

/**
 * Activation hook. Registers the rewrite rules immediately, then flushes so
 * the freshly-registered rules land in the rewrite rule cache without
 * requiring a manual Settings → Permalinks visit.
 *
 * @since 0.1.0
 */
function outpost_activate(): void {
	Outpost_Route_Handler::register_rewrite_rules();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'outpost_activate' );

// G9 — Register Telegraph outbound adapter's transition_post_status hook.
Outpost_Telegraph_Adapter::register();

/**
 * Deactivation hook. Flushes rewrite rules so /post/* rules are dropped from
 * the cache. The rules themselves don't need explicit removal — they're
 * regenerated on every `init` and naturally disappear when this plugin is
 * inactive (the `init` hook stops firing).
 *
 * @since 0.1.0
 */
function outpost_deactivate(): void {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'outpost_deactivate' );
