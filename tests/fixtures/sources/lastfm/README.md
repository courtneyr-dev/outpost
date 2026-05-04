# Fixtures for `Outpost_Source_LastFm`

Offline fixtures for the F16 Last.fm adapter's unit tests.

## Fixture inventory

| File | Captured | From URL | Sanitization |
|---|---|---|---|
| `og-track-success.html` | 2026-05-04 | synthetic | hand-authored Last.fm track page with OG tags |

## Last verified live

Not applicable. F16 sticks to OG-only path (Last.fm API requires
an embedded API key — §5 risk).

## Sanitization checklist

- [x] No real artist names, album names, track names, or user handles
- [x] Synthetic "Sample Artist" / "Sample Track" placeholder values

## Notes specific to Last.fm

- Adapter mapping deliberately OMITS `og:description` because Last.fm
  emits a Wikipedia-paste blob there which produces awkward composer
  output. Tests assert this absence (`p-summary` is not present in
  mapped output).
- Path constraint: only `/music/...` paths are claimed. User profile
  URLs (`/user/...`), library URLs, chart URLs fall through.
