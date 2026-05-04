# Fixtures for `Outpost_Source_Twitch`

Offline fixtures for the F16 Twitch adapter's unit tests.

## Fixture inventory

| File | Captured | From URL | Sanitization |
|---|---|---|---|
| `og-vod-success.html` | 2026-05-04 | synthetic | hand-authored Twitch VOD page with OG tags |
| `og-clip-success.html` | 2026-05-04 | synthetic | hand-authored Twitch clip page with OG tags |

## Last verified live

Not applicable. F16 sticks to OG-only extraction (Helix API requires
embedded credentials, §5 risk). Live verification of OG-tag shape is
deferred to a future F-later batch session.

## Sanitization checklist

- [x] No real channel names, video IDs, clip slugs
- [x] No tracking parameters
- [x] Synthetic content only

## Notes specific to Twitch

- Twitch has Helix (`/helix/streams`, `/helix/videos`, `/helix/clips`)
  but it requires app-level client_credentials — a §5 risk for a free
  WP.org plugin.
- Future enhancement: BYO Helix credentials in Outpost settings for
  richer metadata (game name, viewer count). Documented as a caveat in
  Source_Twitch's `capabilities()` shape.
