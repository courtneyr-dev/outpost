# Fixtures for `Outpost_Source_YouTube`

Offline fixtures for the F15 YouTube adapter's unit + integration tests.
Pattern established F8 (`tests/fixtures/sources/{source_id}/{scenario}.{ext}`).

## Fixture inventory

| File | Captured | From URL | Sanitization |
|---|---|---|---|
| `oembed-watch-success.json` | 2026-05-04 | synthetic | hand-authored, EXAMPLE placeholders for video id + channel handle |
| `oembed-shorts-success.json` | 2026-05-04 | synthetic | hand-authored, SHORTS_EXAMPLE placeholder |
| `oembed-music-success.json` | 2026-05-04 | synthetic | hand-authored, channel-style "Topic" suffix |
| `oembed-html-entities.json` | 2026-05-04 | synthetic | hand-authored; title contains `&amp;`, `&#39;`, `&quot;` |
| `oembed-utf8-multi.json` | 2026-05-04 | synthetic | hand-authored; Japanese / Korean / Russian script in title |
| `oembed-404.json` | 2026-05-04 | synthetic | hand-authored YouTube 404 error-body shape |
| `oembed-401.txt` | 2026-05-04 | synthetic | non-JSON response body for region-restricted/private video path |
| `oembed-503.txt` | 2026-05-04 | synthetic | hand-authored nginx-style 503 HTML — non-JSON to verify parser rejects |

All fixtures use synthetic placeholder video IDs (EXAMPLE, SHORTS_EXAMPLE,
MUSIC_EXAMPLE, etc.). No fixture references a real video, channel,
playlist, or handle.

## Last verified live

2026-05-04 — YouTube oEmbed at `https://www.youtube.com/oembed?url=...&format=json`
returns the documented response shape with title, thumbnail_url,
author_name, provider_name. Rerun `composer test:live` quarterly to catch
contract drift (live test follows F8's pattern; not added in F15 unless
F-later session ships PHPUnit `live` group tests for sources beyond Spotify).

## Sanitization checklist

Every fixture in this directory passes:

- [x] No personal handles, channel IDs, account names, or video IDs
- [x] No tracking parameters or session identifiers
- [x] Synthetic content only — placeholder titles + EXAMPLE-prefixed
      video IDs throughout
- [x] `composer lint:section5` clean against fixture bodies

## Notes specific to YouTube

- YouTube oEmbed accepts URLs from every share-sheet variant (watch,
  shorts, youtu.be, music.youtube.com, m.youtube.com) and returns the
  same response shape regardless of source URL form. Fixtures use the
  canonical shape; URL-pattern tests in `tests/unit/SourceYouTubeTest.php`
  exercise the host-pattern matcher across variants without re-parsing
  fixtures per variant.
- Channel URLs (`/channel/`, `/c/`, `/@`) and playlist URLs (`/playlist`)
  are NOT claimed by `Outpost_Source_YouTube`; tests in
  `SourceYouTubeNonMatchingTest.php` verify these route to
  `Outpost_Source_Unknown`.
- YouTube Music URLs route to Watch mode (not Listen) per CLAUDE.md F15
  decision — Outpost's Listen mode is for audio-only platforms.
