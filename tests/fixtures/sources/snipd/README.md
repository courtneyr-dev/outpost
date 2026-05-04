# Fixtures for `Outpost_Source_Snipd`

Offline fixtures for the F16 Snipd adapter's unit tests. Pattern
established F8 (`tests/fixtures/sources/{source_id}/{scenario}.{ext}`).

## Fixture inventory

| File | Captured | From URL | Sanitization |
|---|---|---|---|
| `og-snip-success.html` | 2026-05-04 | synthetic | hand-authored OG-tag-shaped Snipd snip page |
| `og-empty.html` | 2026-05-04 | synthetic | hand-authored; no OG tags emitted |
| `og-edge-cases.html` | 2026-05-04 | synthetic | hand-authored stress test for the parser (single quotes, attribute order, multiple og:image, name= variant, HTML entities, numeric character references, self-closing meta) — used by the parser-level tests, not Snipd-specific |
| `og-no-head.html` | 2026-05-04 | synthetic | hand-authored body-only HTML with no `<head>` wrapper to verify parser tolerance for SPA emission |

The edge-cases and no-head fixtures live under snipd/ because they were
the first fixtures created for the og_tags parser tests; they're
parser-level data, not Snipd-specific. Future sessions extracting
extractor-level tests may relocate them to a `_parser/` subdirectory.

## Last verified live

Not applicable. Snipd doesn't expose oEmbed; the OG-tag shape is what
`composer test` exercises offline. F-later session may add a live test
that fetches a public durable share URL and confirms the OG tags still
parse to the expected h-entry shape.

## Sanitization checklist

Every fixture in this directory passes:

- [x] No personal handles, channel IDs, account names, or share IDs
- [x] No tracking parameters or session identifiers
- [x] Synthetic content only — placeholder snip / episode / show IDs
- [x] `composer lint:section5` clean against fixture bodies

## Notes specific to Snipd

- Snipd's public share pages emit standard OG tags (`og:title`,
  `og:description`, `og:image`, `og:url`, `og:site_name`, `og:type`).
  No oEmbed endpoint and no public API.
- Profile URLs (`/user/{handle}`) are NOT claimed by
  `Outpost_Source_Snipd` — only `/snip/`, `/episode/`, and `/show/`
  paths route to Listen mode.
