## Decision — the PWA shell is a standalone document, not a theme template

Recorded 2026-10-30 (courtneyr.dev autopilot, batch N0, item O-3). Describes `main` at c2e7dec (1.0.23).

### Decision

The composer at `/post/` is a complete HTML document that Outpost writes itself. `Outpost_Route_Handler::dispatch` runs on `template_redirect` at priority 1 (`includes/class-route-handler.php:50`). `Outpost_PWA_Shell::render_shell()` prints the document and `halt()` stops WordPress there (`includes/class-pwa-shell.php:54-70`, `:1039`), so `wp_head`, `wp_footer` and the active theme's templates never run.

- **Assets go through the enqueue API, printed by hand.** `register_shell_assets()` registers the bundle and its CSS, and `WP_Styles::do_items()` / `WP_Scripts::do_items()` print exactly those handles, not the global queue (`:107-145`). Nothing from the theme or other plugins paints the composer.
- **Own security headers.** `send_html_header()` sends `nocache_headers()`, a Content-Security-Policy, `X-Frame-Options: DENY`, `nosniff`, a referrer policy and a permissions policy (`:867-875`). The CSP is `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; connect-src 'self' https:; font-src 'self' data:; frame-src 'none'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'; object-src 'none'` (`:880-915`). Script has no `'unsafe-inline'`, and a filter can add directives but not drop the baseline ones.
- **PWA scope is `/post/` only.** The manifest (`/post/manifest.json`, `render_manifest()`) and the service worker (`render_service_worker()`) are scoped to `/post/`; the worker never tries to control the rest of the site.

### Why

- A theme template would bring the theme's CSS, scripts and every plugin's front-end assets into the composer. The composer's layout contract is that the plugin owns layout and the theme owns paint only through the token resolver (session FY-Theming).
- A strict CSP without `'unsafe-inline'` scripts is only possible when Outpost controls every script tag in the document.
- A phone home-screen app needs a stable scope and an uncached shell. The shell answers `no-cache`/`no-store`, and the service worker handles offline.

Rejected: a block-theme template or pattern (asset bleed, no CSP control); a wp-admin page (outside the PWA scope, and admin chrome on a phone); a separate static app (would lose the enqueue API, nonces and the companion-plugin checks).

### Consequences

- **Page optimizers must leave `/post/` alone.** Anything that rewrites the document's tags after Outpost prints them breaks it. On courtneyr.dev, Perfmatters' remove-unused-CSS turned the shell's stylesheets into `media="print"` links with an inline `onload` handler. The CSP blocks that handler, so the composer's CSS never applied: dark-mode fields lost contrast, and the More options panel stopped working. The site works around it with the mu-plugin `cr-outpost-shell-perfmatters.php` (2026-10-28), which returns false from `perfmatters_remove_unused_css` on `/post/`. Outpost should opt its own shell out of known optimizers (Perfmatters, WP Rocket, Autoptimize, LiteSpeed) so sites don't need that shim; that's Wave O work on courtneyr.dev.
- Host page caches must not cache `/post/`. `nocache_headers()` covers standards-following caches; hosts that ignore `Cache-Control` need an exclusion, as `docs/CACHE-LAYERS.md` describes.
- Theme-level fixes never reach the composer, by design. Composer appearance changes belong in Outpost (the token resolver and composer CSS), not in the site's theme.
