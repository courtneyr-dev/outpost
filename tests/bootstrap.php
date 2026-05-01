<?php
/**
 * PHPUnit bootstrap for Outpost.
 *
 * Loads Composer's autoloader, primes WP_Mock, defines the WP-side constants
 * the SUT expects to be present (ABSPATH plus the OUTPOST_*_PLUGIN_FILE
 * constants from outpost.php), and registers the production source files for
 * the unit suite. Integration tests will load WordPress core separately.
 *
 * @package Outpost
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/stubs/wordpress/' );
}

// Outpost constants. We load only the constant block from outpost.php; the rest
// of the bootstrap (admin_notices hook, activation hooks) is procedural and
// would call WP functions at file-load time, so we mirror the constants here
// instead of `require`-ing the bootstrap file.
$outpost_constants = array(
	'OUTPOST_VERSION'                          => '0.1.0',
	'OUTPOST_PLUGIN_FILE'                      => dirname( __DIR__ ) . '/outpost.php',
	'OUTPOST_PLUGIN_DIR'                       => dirname( __DIR__ ) . '/',
	'OUTPOST_PLUGIN_URL'                       => 'http://example.test/wp-content/plugins/outpost/',
	'OUTPOST_PLUGIN_BASENAME'                  => 'outpost/outpost.php',
	'OUTPOST_MIN_WP'                           => '6.5',
	'OUTPOST_MIN_PHP'                          => '8.2',
	'OUTPOST_MICROPUB_PLUGIN_FILE'             => 'micropub/micropub.php',
	'OUTPOST_INDIEAUTH_PLUGIN_FILE'            => 'indieauth/indieauth.php',
	'OUTPOST_POST_KINDS_PLUGIN_FILE'           => 'post-kinds-for-indieweb/post-kinds-for-indieweb.php',
	'OUTPOST_POST_FORMATS_PLUGIN_FILE'         => 'post-formats-for-block-themes/post-formats-for-block-themes.php',
	'OUTPOST_LINK_EXTENSION_XFN_PLUGIN_FILE'   => 'link-extension-for-xfn/link-extension-for-xfn.php',
	'OUTPOST_SYNDICATION_LINKS_PLUGIN_FILE'    => 'syndication-links/syndication-links.php',
	'OUTPOST_YOAST_PLUGIN_FILE'                => 'wordpress-seo/wp-seo.php',
	'OUTPOST_ACTIVITYPUB_PLUGIN_FILE'          => 'activitypub/activitypub.php',
);

foreach ( $outpost_constants as $name => $value ) {
	if ( ! defined( $name ) ) {
		define( $name, $value );
	}
}

// Production classes. The detector class is the unit SUT for Session A1.
require_once dirname( __DIR__ ) . '/includes/class-companion-detector.php';
require_once dirname( __DIR__ ) . '/includes/companions/class-companion-base.php';

WP_Mock::bootstrap();
