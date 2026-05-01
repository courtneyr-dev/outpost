<?php
/**
 * PWA shell renderer.
 *
 * Two main rendering branches plus two artefact endpoints.
 *
 *  - render(): when {@see outpost_is_ready()} is true, emits the composer
 *    shell HTML; when false, emits the install-prompt page that names
 *    {@see Outpost_Companion_Detector::first_unsatisfied()} as the blocker
 *    and links to install/activate it.
 *
 *  - render_manifest(): emits a PWA manifest JSON with scope `/post/`.
 *
 *  - render_service_worker(): emits a JS service-worker stub. Scope is
 *    `/post/` only (CLAUDE.md Standards §128) — it never tries to control
 *    the whole site.
 *
 * The composer body itself (modes, syndication chips, voice slot) lands in
 * Phase C. This class only owns the HTML envelope and the install-prompt.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_PWA_Shell {

	/**
	 * Render either the composer shell or the install-prompt page.
	 */
	public static function render(): void {
		if ( outpost_is_ready() ) {
			self::render_shell();
			return;
		}

		self::render_install_prompt();
	}

	/**
	 * Composer shell HTML.
	 *
	 * Body is intentionally empty in A2 — Phase C lands the modes and tabs.
	 * The structural envelope, viewport meta, manifest link, and service-worker
	 * registration script ship now so staging can verify the wiring before
	 * the composer code lands.
	 */
	public static function render_shell(): void {
		self::send_html_header();
		$entry_url = Outpost_PWA_Assets::entry_url();
		$css_urls  = Outpost_PWA_Assets::entry_css_urls();
		?><!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title>Outpost</title>
	<link rel="manifest" href="/post/manifest.json">
	<meta name="theme-color" content="">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-title" content="Outpost">
	<meta name="apple-mobile-web-app-status-bar-style" content="default">
		<?php foreach ( $css_urls as $css_url ) : ?>
	<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
		<?php endforeach; ?>
</head>
<body class="outpost-composer-shell">
	<main id="outpost-root" data-outpost-route="composer"></main>
		<?php if ( null !== $entry_url ) : ?>
	<script type="module" src="<?php echo esc_url( $entry_url ); ?>"></script>
		<?php endif; ?>
	<script>
		if ('serviceWorker' in navigator) {
			navigator.serviceWorker.register('/post/sw', { scope: '/post/' });
		}
	</script>
</body>
</html>
		<?php
		self::halt();
	}

	/**
	 * Install-prompt page rendered when the dependency chain is unsatisfied.
	 *
	 * Consumes {@see Outpost_Companion_Detector::first_unsatisfied()} for the
	 * blocker file and {@see outpost_dependency_presentation()} for the
	 * label/wp.org-slug pair. Same single source of truth as admin notices —
	 * if a future filter renames a blocker on one surface, the other follows.
	 */
	public static function render_install_prompt(): void {
		$blocker = Outpost_Companion_Detector::first_unsatisfied();

		// Possible reasons render_install_prompt() was called with a satisfied
		// chain: host requirements unmet (PHP/WP version too old). Render a
		// generic fallback so the user isn't stuck on a blank page.
		if ( null === $blocker ) {
			self::render_host_unmet_prompt();
			return;
		}

		$presentation = outpost_dependency_presentation( $blocker );
		if ( null === $presentation ) {
			_doing_it_wrong(
				__METHOD__,
				'Dependency chain entry has no presentation mapping: ' . $blocker,
				'0.1.0'
			);
			self::render_host_unmet_prompt();
			return;
		}

		$status     = Outpost_Companion_Detector::status( $blocker );
		$is_install = ( 'absent' === $status );

		if ( $is_install ) {
			$action_url = wp_nonce_url(
				self_admin_url( 'update.php?action=install-plugin&plugin=' . $presentation['slug'] ),
				'install-plugin_' . $presentation['slug']
			);
			$action_label = sprintf(
				/* translators: %s: plugin name. */
				__( 'Install %s', 'outpost' ),
				$presentation['label']
			);
			$message = sprintf(
				/* translators: %s: plugin name. */
				__( 'Outpost needs the %s plugin before it can run. Install it from WordPress.org to continue.', 'outpost' ),
				$presentation['label']
			);
		} else {
			$action_url = wp_nonce_url(
				self_admin_url( 'plugins.php?action=activate&plugin=' . $blocker ),
				'activate-plugin_' . $blocker
			);
			$action_label = sprintf(
				/* translators: %s: plugin name. */
				__( 'Activate %s', 'outpost' ),
				$presentation['label']
			);
			$message = sprintf(
				/* translators: %s: plugin name. */
				__( 'Outpost needs the %s plugin to be activated before the composer can run.', 'outpost' ),
				$presentation['label']
			);
		}

		self::send_html_header();
		?><!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title><?php echo esc_html( __( 'Outpost setup', 'outpost' ) ); ?></title>
</head>
<body class="outpost-install-prompt" data-outpost-blocker="<?php echo esc_attr( $blocker ); ?>">
	<main>
		<h1><?php echo esc_html( __( 'One more step', 'outpost' ) ); ?></h1>
		<p><?php echo esc_html( $message ); ?></p>
		<p><a class="outpost-install-prompt__action" href="<?php echo esc_url( $action_url ); ?>"><?php echo esc_html( $action_label ); ?></a></p>
	</main>
</body>
</html>
		<?php
		self::halt();
	}

	/**
	 * Generic fallback when host requirements (PHP/WP version) aren't met.
	 */
	private static function render_host_unmet_prompt(): void {
		self::send_html_header();
		$message = sprintf(
			/* translators: 1: minimum WP version, 2: minimum PHP version. */
			__( 'Outpost requires WordPress %1$s or newer and PHP %2$s or newer.', 'outpost' ),
			OUTPOST_MIN_WP,
			OUTPOST_MIN_PHP
		);
		?><!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title><?php echo esc_html( __( 'Outpost setup', 'outpost' ) ); ?></title>
</head>
<body class="outpost-install-prompt outpost-install-prompt--host-unmet">
	<main>
		<h1><?php echo esc_html( __( 'Server requirements', 'outpost' ) ); ?></h1>
		<p><?php echo esc_html( $message ); ?></p>
	</main>
</body>
</html>
		<?php
		self::halt();
	}

	/**
	 * Emit the PWA manifest JSON.
	 *
	 * Real icons land with assets/icons in Phase D; the entries below point at
	 * the canonical paths and ship as 404s in A2. The manifest still validates
	 * because `icons` is an array of entries, not file existence checks.
	 */
	public static function render_manifest(): void {
		$manifest = array(
			'name'             => 'Outpost',
			'short_name'       => 'Outpost',
			'description'      => 'Mobile-first IndieWeb composer.',
			'scope'            => '/post/',
			'start_url'        => '/post/',
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'background_color' => '#ffffff',
			'theme_color'      => '#ffffff',
			'icons'            => array(
				array(
					'src'   => '/wp-content/plugins/outpost/assets/icons/icon-192.png',
					'sizes' => '192x192',
					'type'  => 'image/png',
				),
				array(
					'src'   => '/wp-content/plugins/outpost/assets/icons/icon-512.png',
					'sizes' => '512x512',
					'type'  => 'image/png',
				),
			),
		);

		self::send_json_header();
		echo wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		self::halt();
	}

	/**
	 * Emit the service-worker JavaScript stub.
	 *
	 * A2 ships a no-op SW that registers `install` and `activate` listeners
	 * so the registration script in {@see self::render_shell()} succeeds and
	 * the browser caches the SW registration. The real fetch handler, offline
	 * queue, and version skew handling land in Phase D.
	 */
	public static function render_service_worker(): void {
		self::send_js_header();
		?>
// Outpost service worker — A2 stub. Phase D lands the fetch/cache strategy.
self.addEventListener('install', (event) => {
	self.skipWaiting();
});

self.addEventListener('activate', (event) => {
	event.waitUntil(self.clients.claim());
});

// Scope is /post/ only — registered explicitly in the shell so this SW
// never tries to control the parent WordPress site.
		<?php
		self::halt();
	}

	/**
	 * Send headers for an HTML response. Skipped when headers have already been
	 * sent (e.g. WP startup printed something) so test output can still capture
	 * the body via ob_start.
	 *
	 * Cache-Control: managed-WP page caches (Varnish on GoDaddy, nginx FastCGI
	 * on others) cache anonymous responses by default. Without nocache_headers()
	 * the install-prompt page rendered to one user could be served to another.
	 * The composer shell itself is anonymous-safe (state lives in IndexedDB,
	 * not in the HTML), but no-cache here keeps the caching policy explicit
	 * and makes B0b's auth-callback handling correct without a follow-up.
	 *
	 * Manifest and SW responses keep their own cache semantics (browsers want
	 * to re-fetch them on a defined cadence) — we don't apply nocache_headers()
	 * to those.
	 */
	private static function send_html_header(): void {
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
		}
	}

	private static function send_json_header(): void {
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
		}
	}

	private static function send_js_header(): void {
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/javascript; charset=utf-8' );
		}
	}

	/**
	 * Halt PHP execution after a response has been sent.
	 *
	 * Without this, WordPress continues past `template_redirect` and renders
	 * the theme template, concatenating it onto our shell/manifest/sw output.
	 *
	 * The unit-test bootstrap defines `OUTPOST_TESTING_PWA_SHELL` so test runs
	 * skip the `exit` and the assertions on captured output still work.
	 *
	 * @codeCoverageIgnore
	 */
	private static function halt(): void {
		if ( defined( 'OUTPOST_TESTING_PWA_SHELL' ) && OUTPOST_TESTING_PWA_SHELL ) {
			return;
		}
		exit;
	}
}
