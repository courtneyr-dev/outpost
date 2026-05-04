# Fixtures for `Outpost_Source_Spotify`

Offline fixtures for the F7 Spotify adapter's unit + integration tests.
Pattern established F8 (`tests/fixtures/sources/{source_id}/{scenario}.{ext}`);
every later `Source_*` directory follows this shape.

## Fixture inventory

| File | Captured | From URL | Sanitization |
|---|---|---|---|
| `oembed-track-success.json` | 2026-05-04 | synthetic | hand-authored, EXAMPLE placeholders for embed iframe src |
| `oembed-album-success.json` | 2026-05-04 | synthetic | hand-authored, EXAMPLE placeholders |
| `oembed-episode-success.json` | 2026-05-04 | synthetic | hand-authored, EXAMPLE placeholders |
| `oembed-show-success.json` | 2026-05-04 | synthetic | hand-authored, EXAMPLE placeholders |
| `oembed-playlist-success.json` | 2026-05-04 | synthetic | hand-authored, EXAMPLE placeholders |
| `oembed-no-thumbnail.json` | 2026-05-04 | synthetic | hand-authored; `thumbnail_url` deliberately omitted |
| `oembed-html-entities.json` | 2026-05-04 | synthetic | hand-authored; title contains `&amp;`, `&#39;`, `&quot;` |
| `oembed-utf8-korean.json` | 2026-05-04 | synthetic | hand-authored; title is "아리랑 (Arirang)" — Korean folk song, public domain, UNESCO heritage |
| `oembed-utf8-arabic.json` | 2026-05-04 | synthetic | hand-authored; title is generic Arabic descriptive phrase |
| `oembed-utf8-cyrillic.json` | 2026-05-04 | synthetic | hand-authored; title is "Калинка (Kalinka)" — Russian folk song, public domain |
| `oembed-script-injection.json` | 2026-05-04 | synthetic | hand-authored; title contains `<script>alert(1)</script>` for layered-defense verification |
| `oembed-javascript-url.json` | 2026-05-04 | synthetic | hand-authored; `thumbnail_url` is `javascript:alert(1)` for content-type-allowlist verification |
| `oembed-empty-title.json` | 2026-05-04 | synthetic | hand-authored; `title` is empty string |
| `oembed-very-long-title.json` | 2026-05-04 | synthetic | hand-authored; title >300 chars to verify the adapter does not truncate |
| `oembed-404.json` | 2026-05-04 | synthetic | hand-authored; mirrors Spotify oEmbed 404 error-body shape |
| `oembed-503.txt` | 2026-05-04 | synthetic | hand-authored nginx 503 HTML — non-JSON body to verify the parser rejects |
| `oembed-malformed.json` | 2026-05-04 | synthetic | hand-authored; truncated JSON to trip `json_decode` |

All fixtures use the synthetic 22-zero placeholder track ID convention
established F7. No fixture references a real Spotify track, album,
playlist, episode, show, or artist.

## Last verified live

2026-05-04 — Spotify oEmbed at `https://open.spotify.com/oembed?url=...`
returns a JSON object containing `title`, `thumbnail_url`, and
`provider_name === "Spotify"` for any valid resource URL. The shape has
not changed since the F7 capture date. Rerun `composer test:live`
quarterly to catch contract drift.

## Sanitization checklist

Every fixture in this directory passes:

- [x] No personal handles, account names, or user IDs
- [x] No API keys, tokens, or session identifiers (Spotify oEmbed is
      anonymous; this is structurally guaranteed)
- [x] No tracking parameters in URLs (`?si=...` etc.)
- [x] Synthetic content only — no real track, album, playlist names
- [x] `composer lint:section5` clean against fixture bodies

## Notes specific to Spotify

- Spotify oEmbed accepts URLs with tracking parameters (`?si=...`); the
  response is identical with or without. Future fixtures that exercise
  tracking-parameter pass-through go in a separate scenario file.
- The `intl-{lang}` regional path prefix does not affect oEmbed; the
  response is identical for `/track/X` and `/intl-de/track/X`. No
  regional fixture is needed; URL-pattern tests cover regional URLs.
- `spotify.link` short URLs redirect to `open.spotify.com`; oEmbed
  follows the redirect. The fixture inventory does not include a
  short-URL response because the response shape is identical to the
  resolved canonical URL.
