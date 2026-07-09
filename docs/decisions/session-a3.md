## Session A3 — Design Constraints (deferred — Phase B took priority)

A2's PWA shell renders an empty composer envelope with no styling tokens or static assets. A3 lands the structural CSS, the `--outpost-*` token defaults, and the icon set. Honor these when starting:

1. **`styles/outpost-tokens.css` is server-rendered, not bundled.** Themes need to inspect the cascade and override; a Vite-bundled-and-hashed token file makes that fragile. Keep the token file at a stable URL.
2. **The forced `padding-bottom: env(safe-area-inset-bottom)` on the iOS bottom toolbar is the only paint default Outpost ships.** Hard Contract above. Anything else is theme territory.
3. **Service worker fetch handler stays out of A3.** A2 ships a no-op SW so the registration script succeeds; the real fetch/cache strategy lands in Phase D after the composer modes exist (otherwise we cache a shell that's about to be replaced).
