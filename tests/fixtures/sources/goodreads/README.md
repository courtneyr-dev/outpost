# Fixtures for `Outpost_Source_Goodreads`

Offline fixtures for the F16 Goodreads adapter's unit tests.

## Fixture inventory

| File | Captured | From URL | Sanitization |
|---|---|---|---|
| `og-book-success.html` | 2026-05-04 | synthetic | hand-authored Goodreads book page with OG tags including `&amp;` entity in description for entity-decoding test |

## Last verified live

Not applicable. F16 sticks to OG-only path; Goodreads's REST API was
killed Dec 2020 so OG is the only public extraction surface anyway.

## Sanitization checklist

- [x] No real book IDs, author names, ISBN, or user IDs
- [x] Synthetic placeholder values throughout

## Notes specific to Goodreads

- Path constraint: only `/book/show/...` and `/review/show/...` are
  claimed. User shelf URLs (`/user/show/`) and search URLs fall
  through to Source_Unknown.
- A future session may add an RSS-feed-based bulk-sync feature
  (`/review/list_rss/{user_id}?shelf=...`) for passive Goodreads →
  Outpost shelf sync. Out of scope for the share-target inbound flow.
