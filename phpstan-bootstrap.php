<?php
/**
 * PHPStan static-analysis bootstrap.
 *
 * Defines Outpost's runtime constants so PHPStan knows about them when
 * scanning files in includes/ in isolation. Mirrors the constant block
 * from outpost.php's "Plugin metadata constants" + "Companion plugin
 * file paths" sections.
 *
 * Not loaded at runtime — PHPStan loads this only during analysis,
 * configured via `bootstrapFiles` in phpstan.neon.dist.
 *
 * @package Outpost
 */

declare(strict_types=1);

if ( ! defined( 'OUTPOST_VERSION' ) ) {
	define( 'OUTPOST_VERSION', '0.0.0-phpstan' );
}
if ( ! defined( 'OUTPOST_PLUGIN_FILE' ) ) {
	define( 'OUTPOST_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'OUTPOST_PLUGIN_DIR' ) ) {
	define( 'OUTPOST_PLUGIN_DIR', __DIR__ . '/' );
}
if ( ! defined( 'OUTPOST_PLUGIN_URL' ) ) {
	define( 'OUTPOST_PLUGIN_URL', 'http://example.test/wp-content/plugins/outpost/' );
}
if ( ! defined( 'OUTPOST_PLUGIN_BASENAME' ) ) {
	define( 'OUTPOST_PLUGIN_BASENAME', 'outpost/outpost.php' );
}
if ( ! defined( 'OUTPOST_MIN_WP' ) ) {
	define( 'OUTPOST_MIN_WP', '6.5' );
}
if ( ! defined( 'OUTPOST_MIN_PHP' ) ) {
	define( 'OUTPOST_MIN_PHP', '8.2' );
}
if ( ! defined( 'OUTPOST_INDIEAUTH_PLUGIN_FILE' ) ) {
	define( 'OUTPOST_INDIEAUTH_PLUGIN_FILE', 'indieauth/indieauth.php' );
}
if ( ! defined( 'OUTPOST_MICROPUB_PLUGIN_FILE' ) ) {
	define( 'OUTPOST_MICROPUB_PLUGIN_FILE', 'micropub/micropub.php' );
}
if ( ! defined( 'OUTPOST_POST_KINDS_PLUGIN_FILE' ) ) {
	define( 'OUTPOST_POST_KINDS_PLUGIN_FILE', 'indieweb-post-kinds/post-kinds.php' );
}
if ( ! defined( 'OUTPOST_POST_FORMATS_PLUGIN_FILE' ) ) {
	define( 'OUTPOST_POST_FORMATS_PLUGIN_FILE', 'post-formats-for-block-themes/post-formats-for-block-themes.php' );
}
if ( ! defined( 'OUTPOST_LINK_EXTENSION_XFN_PLUGIN_FILE' ) ) {
	define( 'OUTPOST_LINK_EXTENSION_XFN_PLUGIN_FILE', 'link-extension-for-xfn/link-extension-for-xfn.php' );
}
if ( ! defined( 'OUTPOST_SYNDICATION_LINKS_PLUGIN_FILE' ) ) {
	define( 'OUTPOST_SYNDICATION_LINKS_PLUGIN_FILE', 'syndication-links/syndication-links.php' );
}
if ( ! defined( 'OUTPOST_YOAST_PLUGIN_FILE' ) ) {
	define( 'OUTPOST_YOAST_PLUGIN_FILE', 'wordpress-seo/wp-seo.php' );
}
if ( ! defined( 'OUTPOST_ACTIVITYPUB_PLUGIN_FILE' ) ) {
	define( 'OUTPOST_ACTIVITYPUB_PLUGIN_FILE', 'activitypub/activitypub.php' );
}
