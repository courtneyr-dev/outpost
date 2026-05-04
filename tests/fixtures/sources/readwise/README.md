# Fixtures for `Outpost_Source_Readwise`

Offline fixtures for the F16 Readwise adapter's unit tests.

## Fixture inventory

| File | Captured | From URL | Sanitization |
|---|---|---|---|
| `og-highlight-success.html` | 2026-05-04 | synthetic | hand-authored Readwise highlight share page with OG tags |

## Last verified live

Not applicable. F16 sticks to anonymous OG path. Authenticated
highlight-pull (BYO Readwise token) is a separate sync feature
deferred to a future session.

## Sanitization checklist

- [x] No real book titles, authors, highlight IDs, or user handles
- [x] Synthetic placeholder values throughout

## Notes specific to Readwise

- Mapping note: `og:description` carries the highlight TEXT itself
  (the quote). Adapter routes it to `e-content` rather than
  `p-summary` because the highlight IS the post body, not a summary
  of it. Tests assert this is the only `*-content` field set.
- Outpost has no separate Quote mode; Bookmark with `e-content`
  treatment is the closest fit until that mode lands.
- Path constraint: `/highlights/`, `/bookreview/` on `readwise.io`,
  `/read/` on `read.readwise.io`. Profile URLs (`@<handle>`) and
  library URLs fall through.
