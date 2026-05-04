# Fixtures for `Outpost_Source_TikTok`

Offline fixtures for the F16 TikTok adapter's unit tests.

## Fixture inventory

| File | Captured | From URL | Sanitization |
|---|---|---|---|
| `og-video-success.html` | 2026-05-04 | synthetic | hand-authored TikTok video page with OG tags; uses an RFC stock-name handle (alice) for the user segment |

## Last verified live

Not applicable. TikTok also has an oEmbed endpoint; F16 batches it as
OG-only for bulk-coverage parity. A future session may swap to oEmbed
for richer metadata.

## Sanitization checklist

- [x] No real user handles (the fixture uses an RFC stock-name
      handle from the F4 fixture-handle allowlist; test code uses an
      example-prefixed handle, which doesn't trip B5 because B5 only
      scans tests/fixtures/)
- [x] No real video IDs
- [x] Synthetic content only

## Notes specific to TikTok

- F15 §5 audit lesson: the B5 lint scans `tests/fixtures/` for
  non-allowlisted handle tokens. TikTok URLs require an
  at-sign-prefixed username segment per the URL spec, so fixtures
  use a stock-name handle from the F4 fixture-handle allowlist. F15
  YouTube fixtures dodged this by using `/channel/UC...` form
  (older-account permanent identifier); TikTok has no such alternate.
- Path constraint: `tiktok.com/@{user}/video/{id}` (regex matched);
  vm.tiktok.com short links match all non-empty paths.
- Profile URLs (`@{user}` with no `/video/...`) and discover URLs
  fall through to Source_Unknown.
