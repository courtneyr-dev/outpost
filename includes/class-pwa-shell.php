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
		// Append `?ver=OUTPOST_VERSION` to bundle CSS + JS URLs as a
		// cache-bust against Cloudflare/managed-WP edge caches that may
		// Vite content-hashes the JS + CSS filenames already, so each build
		// gets a unique URL the browser/CDN can cache forever. The previous
		// `?ver=OUTPOST_VERSION` query was redundant — and worse, every
		// version bump (Outpost has shipped 58+ in active development) was
		// re-fetching the same content. Drop the version query for hashed
		// bundle assets. The token-css link below still uses ?ver= because
		// `outpost-tokens.css` is server-rendered with a stable filename.
		$entry_url_versioned = $entry_url;
		// Outpost shell IS the entire HTML document, not an enrichment of WP's
		// page template — template_redirect priority 1 + self::halt() bypasses
		// wp_head/wp_footer entirely. wp_enqueue_style/script can't reach this
		// rendering path. The <link rel="stylesheet"> and <script type="module">
		// tags below are intentional inline outputs.
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet, WordPress.WP.EnqueuedResources.NonEnqueuedScript
		?><!doctype html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title>Outpost</title>
	<link rel="manifest" href="/post/manifest.json">
	<link rel="icon" type="image/svg+xml" href="<?php echo esc_url( OUTPOST_PLUGIN_URL . 'assets/icons/outpost-icon.svg' ); ?>">
	<link rel="apple-touch-icon" href="<?php echo esc_url( OUTPOST_PLUGIN_URL . 'assets/icons/outpost-icon.svg' ); ?>">
		<?php if ( null !== $entry_url_versioned ) : ?>
	<link rel="modulepreload" href="<?php echo esc_url( $entry_url_versioned ); ?>">
		<?php endif; ?>
	<meta name="theme-color" content="#241c4a">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-title" content="Outpost">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<style>
		/* Critical layout — reserves space before the bundled CSS loads,
			prevents CLS when JS mounts the .outpost-app class onto #outpost-root.
			Layout primitives only (no paint) per the Hard Contract.
			padding-top reserves room for the iOS status bar in standalone
			mode (apple-mobile-web-app-status-bar-style: black-translucent
			puts content under the bar). */
		body { margin: 0; min-height: 100dvh; min-height: 100vh; padding-top: env(safe-area-inset-top); }
		#outpost-root { display: block; min-height: 100dvh; min-height: 100vh; }
	</style>
	<link rel="stylesheet" href="<?php echo esc_url( OUTPOST_PLUGIN_URL . 'styles/outpost-tokens.css?ver=' . OUTPOST_VERSION ); ?>">
		<?php
		// CSS files are content-hashed by Vite — no version-query
		// needed. See $entry_url_versioned comment above.
		foreach ( $css_urls as $css_url ) :
			?>
	<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
		<?php endforeach; ?>
</head>
<body class="outpost-composer-shell">
	<main id="outpost-root" data-outpost-route="composer"></main>
		<?php if ( null !== $entry_url_versioned ) : ?>
	<script type="module" src="<?php echo esc_url( $entry_url_versioned ); ?>"></script>
		<?php endif; ?>
		<?php
		// SW registration moved into the bundled JS (pwa/src/index.tsx). The
		// inline script approach used a per-request CSP nonce that breaks under
		// edge caching — Cloudflare caches the HTML body but CSP regenerates
		// per request, so the cached nonce diverges from the per-request CSP.
		?>
</body>
</html>
		<?php
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet, WordPress.WP.EnqueuedResources.NonEnqueuedScript
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
				esc_html( 'Dependency chain entry has no presentation mapping: ' . $blocker ),
				'0.1.0'
			);
			self::render_host_unmet_prompt();
			return;
		}

		$status     = Outpost_Companion_Detector::status( $blocker );
		$is_install = ( 'absent' === $status );

		if ( $is_install ) {
			$action_url   = wp_nonce_url(
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
			$action_url   = wp_nonce_url(
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
		?>
		<!doctype html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title><?php echo esc_html( __( 'Outpost setup', 'outpost' ) ); ?></title>
	<style>
		body.outpost-install-prompt {
			max-width: 36rem;
			margin: 2rem auto;
			padding: 1.5rem;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			line-height: 1.55;
			color: #023047;
		}
		.outpost-onboarding {
			background: #e6f1f7;
			border: 1px solid #126782;
			border-radius: 0.5rem;
			padding: 1.25rem;
			margin-block-end: 1.5rem;
		}
		.outpost-onboarding h1 {
			margin: 0 0 0.5rem;
			font-size: 1.4rem;
		}
		.outpost-onboarding p {
			margin: 0.5rem 0;
		}
		.outpost-install-prompt__action {
			display: inline-block;
			padding: 0.625rem 1.25rem;
			background: #241c4a;
			color: #ffffff;
			border-radius: 0.5rem;
			text-decoration: none;
			font-weight: 600;
		}
		.outpost-install-prompt__skip {
			color: #647baf;
			text-decoration: underline;
			margin-inline-start: 1rem;
		}
	</style>
</head>
<body class="outpost-install-prompt" data-outpost-blocker="<?php echo esc_attr( $blocker ); ?>">
	<main>
		<section class="outpost-onboarding" aria-labelledby="outpost-onboarding-title">
			<h1 id="outpost-onboarding-title"><?php echo esc_html( __( 'Welcome to Outpost', 'outpost' ) ); ?></h1>
			<p>
				<?php
				echo esc_html__(
					'Outpost is a mobile-first composer for posting to your own WordPress site, built on the IndieWeb specs (Micropub, IndieAuth, microformats2). It only writes posts — not Pages or custom post types — and it leaves the visual paint to your active theme.',
					'outpost'
				);
				?>
			</p>
			<p>
				<strong><?php echo esc_html__( 'POSSE', 'outpost' ); ?></strong>
				<?php
				echo esc_html__(
					' — Publish (on your) Own Site, Syndicate Elsewhere. Your domain stays the canonical source; copies go to Mastodon, Twitter / X, Bluesky, or wherever your audience is.',
					'outpost'
				);
				?>
			</p>
		</section>

		<h2><?php echo esc_html( __( 'One more step', 'outpost' ) ); ?></h2>
		<p><?php echo esc_html( $message ); ?></p>
		<p>
			<a class="outpost-install-prompt__action" href="<?php echo esc_url( $action_url ); ?>"><?php echo esc_html( $action_label ); ?></a>
		</p>
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
		?>
		<!doctype html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', get_locale() ) ); ?>">
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
			// 'any' (not 'portrait') — locking installed PWAs to portrait
			// violates WCAG 1.3.4 (Orientation) by preventing landscape use
			// on mounted phones / tablets / accessibility-rotated devices.
			'orientation'      => 'any',
			'background_color' => '#ffffff',
			'theme_color'      => '#241c4a',
			'icons'            => array(
				array(
					'src'     => '/wp-content/plugins/outpost/assets/icons/outpost-icon.svg',
					'sizes'   => 'any',
					'type'    => 'image/svg+xml',
					'purpose' => 'any',
				),
				array(
					'src'     => '/wp-content/plugins/outpost/assets/icons/outpost-icon-maskable.svg',
					'sizes'   => 'any',
					'type'    => 'image/svg+xml',
					'purpose' => 'maskable',
				),
			),
			// Web Share Target API (Phase E0). When the user shares text + URL
			// from another app, the browser navigates to action with params
			// merged into the query string. Our /post/share-target route
			// stashes the data and forwards to the composer with the right
			// mode pre-filled.
			'share_target'     => array(
				'action'  => '/post/share-target',
				'method'  => 'GET',
				'enctype' => 'application/x-www-form-urlencoded',
				'params'  => array(
					'title' => 'title',
					'text'  => 'text',
					'url'   => 'url',
				),
			),
		);

		self::send_json_header();
		echo wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		self::halt();
	}

	/**
	 * Emit the service-worker JavaScript.
	 *
	 * Phase D0. Caches the PWA shell + bundled assets so the composer loads
	 * on a cold device with no connection. Cache name includes
	 * OUTPOST_VERSION so plugin updates land cleanly — the activate handler
	 * deletes any cache that doesn't match the current version.
	 *
	 * Strategies:
	 *
	 *   - Shell HTML (/post/, /post/, /post/auth/callback, /post/share-target)
	 *     → network-first, cache fallback. Updates ride in fast; offline
	 *     loads from cache.
	 *   - Bundled JS / CSS / map (under build/pwa/assets/)
	 *     → cache-first. Filenames are content-hashed by Vite, so any new
	 *     bundle is a new URL — old cached entries get cleaned by the
	 *     version-bump activation pass.
	 *   - Token CSS (styles/outpost-tokens.css) and SVG icons
	 *     (assets/icons/...) → cache-first.
	 *   - Manifest (/post/manifest.json) → network-first, cache fallback.
	 *   - REST endpoints (/wp-json/outpost/v1/...) → bypass (never cache;
	 *     composer-config is per-user, preview is per-request).
	 *   - All other same-origin fetches → bypass (let the browser's HTTP
	 *     cache handle).
	 *
	 * The SW controls /post/* clients (scope locked to /post/ per A0 #6),
	 * but a controlled client's same-origin fetches go through the SW
	 * regardless of URL — so plugin assets at /wp-content/.../build/pwa/...
	 * are interceptable even though they live outside /post/.
	 *
	 * The composer's offline post-queue (D1) registers separately on the
	 * `message` channel from the page; the SW exposes a small JSON
	 * protocol for the page to drive cache management (e.g. force a
	 * shell refresh after sign-in).
	 */
	public static function render_service_worker(): void {
		self::send_js_header();
		$version    = OUTPOST_VERSION;
		$plugin_url = OUTPOST_PLUGIN_URL;
		?>
// Outpost service worker — Phase D0. Plugin version: <?php echo esc_js( $version ); ?>.

const CACHE_VERSION = '<?php echo esc_js( $version ); ?>';
const CACHE_NAME = 'outpost-' + CACHE_VERSION;
const PLUGIN_URL = '<?php echo esc_js( $plugin_url ); ?>';
const SHELL_URL = '/post/';

// Resources to seed the cache with on install. The bundled JS/CSS get
// fetched lazily on first composer open and cached by the fetch handler —
// we don't precache them by URL because their hashed filenames change with
// every plugin build.
const PRECACHE_URLS = [
	SHELL_URL,
	'/post/manifest.json',
	PLUGIN_URL + 'styles/outpost-tokens.css',
	PLUGIN_URL + 'assets/icons/outpost-icon.svg',
	PLUGIN_URL + 'assets/icons/outpost-icon-maskable.svg',
];

self.addEventListener('install', (event) => {
	event.waitUntil(
		caches.open(CACHE_NAME)
			.then((cache) => cache.addAll(PRECACHE_URLS).catch(() => {
				// Best-effort precache. If a single URL 404s on first install
				// (e.g. token CSS not yet deployed) we don't want the whole
				// install to fail — the fetch handler will fill the cache
				// lazily anyway.
			}))
			.then(() => self.skipWaiting())
	);
});

self.addEventListener('activate', (event) => {
	event.waitUntil(
		caches.keys()
			.then((keys) => Promise.all(
				keys
					.filter((key) => key.startsWith('outpost-') && key !== CACHE_NAME)
					.map((key) => caches.delete(key))
			))
			.then(() => self.clients.claim())
	);
});

// URL classifiers — split here so the fetch handler stays readable.
function is_shell_request(url) {
	if (url.origin !== self.location.origin) return false;
	const p = url.pathname;
	return p === '/post' || p === '/post/' ||
		p === '/post/auth/callback' || p === '/post/auth/callback/' ||
		p === '/post/share-target' || p === '/post/share-target/';
}

function is_manifest_request(url) {
	return url.origin === self.location.origin && url.pathname === '/post/manifest.json';
}

function is_static_asset(url) {
	if (url.origin !== self.location.origin) return false;
	if (!url.pathname.startsWith(new URL(PLUGIN_URL).pathname)) return false;
	const p = url.pathname;
	return p.includes('/build/pwa/assets/') ||
		p.includes('/styles/') ||
		p.includes('/assets/icons/');
}

function is_outpost_rest(url) {
	return url.origin === self.location.origin &&
		url.pathname.startsWith('/wp-json/outpost/v1/');
}

self.addEventListener('fetch', (event) => {
	const request = event.request;

	// Non-GET (POSTs to Micropub, our preview endpoint) is always passthrough.
	if (request.method !== 'GET') {
		return;
	}

	const url = new URL(request.url);

	// Outpost REST endpoints — never cache. composer-config is per-user
	// (responses already carry Cache-Control: no-store); preview is per
	// request. Let the browser's HTTP cache decide.
	if (is_outpost_rest(url)) {
		return;
	}

	if (is_shell_request(url)) {
		event.respondWith(network_first(request, SHELL_URL));
		return;
	}

	if (is_manifest_request(url)) {
		event.respondWith(network_first(request, request.url));
		return;
	}

	if (is_static_asset(url)) {
		event.respondWith(cache_first(request));
		return;
	}

	// Everything else (Micropub endpoint discovery on the user's "me" URL,
	// theme assets, etc.) — passthrough.
});

async function network_first(request, cache_key) {
	try {
		const response = await fetch(request);
		if (response && response.ok) {
			// Honor Cache-Control: no-store. The shell HTML and
			// auth-callback page set no-store specifically so install-
			// prompt-for-user-A doesn't leak to user-B on shared
			// devices, and so an auth-callback URL with a consumed
			// `code` doesn't replay on back-button navigation.
			const cache_control = response.headers.get('cache-control') || '';
			if (!/no-store/i.test(cache_control)) {
				const copy = response.clone();
				const cache = await caches.open(CACHE_NAME);
				await cache.put(cache_key, copy);
			}
		}
		return response;
	} catch (_err) {
		const cached = await caches.match(cache_key);
		if (cached) return cached;
		// Last-ditch: serve the shell so the SPA at least renders the
		// "you're offline" fallback (D1 surfaces the offline queue UI).
		const shell = await caches.match(SHELL_URL);
		if (shell) return shell;
		return new Response('Outpost is offline.', {
			status: 503,
			headers: { 'Content-Type': 'text/plain' },
		});
	}
}

async function cache_first(request) {
	const cached = await caches.match(request);
	if (cached) return cached;
	try {
		const response = await fetch(request);
		if (response && response.ok) {
			const copy = response.clone();
			const cache = await caches.open(CACHE_NAME);
			await cache.put(request, copy);
		}
		return response;
	} catch (_err) {
		return new Response('Asset unavailable offline.', {
			status: 503,
			headers: { 'Content-Type': 'text/plain' },
		});
	}
}
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
			header( 'Content-Security-Policy: ' . self::content_security_policy() );
			header( 'X-Frame-Options: DENY' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Referrer-Policy: strict-origin-when-cross-origin' );
			header( 'Permissions-Policy: ' . self::permissions_policy() );
		}
	}

	/**
	 * Content-Security-Policy for the composer shell (Phase G).
	 *
	 * The composer is a Preact SPA that fetches the user's "me" URL,
	 * the user's Micropub endpoint, and the configured media endpoint —
	 * any of which can be cross-origin. So `connect-src` must allow
	 * https: broadly. Image src likewise needs https: + data: + blob:
	 * for photo previews. All other directives stay tight.
	 *
	 * No 'unsafe-eval' — Vite's esbuild output is plain ES modules.
	 * No 'unsafe-inline' on script-src — the SW registration script in
	 * render_shell() is the one inline script; it gets a per-request
	 * nonce that we attach to its tag.
	 *
	 * Filterable via `outpost_csp` for sites that need to extend
	 * (e.g. allow a specific analytics origin, embed a CDN).
	 */
	private static function content_security_policy(): string {
		$csp = array(
			"default-src 'self'",
			// No inline scripts — SW registration lives in the bundled JS
			// (pwa/src/index.tsx). 'self' covers the bundled script tag.
			"script-src 'self'",
			"style-src 'self' 'unsafe-inline'",
			"img-src 'self' data: blob: https:",
			"connect-src 'self' https:",
			"font-src 'self' data:",
			"frame-src 'none'",
			"frame-ancestors 'none'",
			"form-action 'self'",
			"base-uri 'self'",
			"object-src 'none'",
			'upgrade-insecure-requests',
		);
		/**
		 * Filter the Content-Security-Policy directive list for the
		 * Outpost composer shell. Filter callers can append (or replace)
		 * directives. **The baseline directives below are enforced even
		 * if a filter omits them** — losing `default-src 'self'`,
		 * `frame-ancestors 'none'`, `object-src 'none'`, or
		 * `base-uri 'self'` would open clickjacking + plugin-injection
		 * vectors that no Outpost feature legitimately needs.
		 *
		 * @param string[] $csp Default directive list.
		 */
		$csp = apply_filters( 'outpost_csp', $csp );
		if ( ! is_array( $csp ) ) {
			$csp = array();
		}

		// Enforce a security baseline. Each required prefix represents
		// a directive we won't allow filters to drop or weaken to a
		// permissive value. If a filter dropped one, re-add the safe
		// default; if a filter weakened one (e.g., `default-src *`),
		// restoring the safe default takes precedence.
		$required = array(
			"default-src 'self'",
			"frame-ancestors 'none'",
			"object-src 'none'",
			"base-uri 'self'",
		);
		foreach ( $required as $directive ) {
			$prefix   = strtok( $directive, ' ' );
			$has_safe = false;
			$rebuilt  = array();
			foreach ( $csp as $existing ) {
				if ( ! is_string( $existing ) ) {
					continue;
				}
				if ( 0 === strpos( $existing, $prefix . ' ' ) || $existing === $prefix ) {
					if ( $existing === $directive ) {
						$has_safe  = true;
						$rebuilt[] = $existing;
					}
					// Drop weakened variants of the required directive;
					// the safe default gets appended below.
					continue;
				}
				$rebuilt[] = $existing;
			}
			if ( ! $has_safe ) {
				$rebuilt[] = $directive;
			}
			$csp = $rebuilt;
		}

		return implode( '; ', $csp );
	}

	/**
	 * Permissions-Policy for the composer (Phase G).
	 *
	 * Allow microphone (D4 voice input — same-origin only). Disable
	 * features the composer never uses so a compromised script
	 * dependency can't quietly turn them on.
	 */
	private static function permissions_policy(): string {
		return implode(
			', ',
			array(
				'microphone=(self)',
				'camera=(self)',
				'geolocation=()',
				'payment=()',
				'usb=()',
				'midi=()',
				'magnetometer=()',
				'gyroscope=()',
				'accelerometer=()',
			)
		);
	}

	/**
	 * Per-request script nonce. Generated lazily and cached for the
	 * remainder of the request so the CSP header and the shell's inline
	 * script tag agree.
	 */
	public static function script_nonce(): string {
		static $nonce = null;
		if ( null === $nonce ) {
			$nonce = wp_generate_uuid4();
		}
		return $nonce;
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
