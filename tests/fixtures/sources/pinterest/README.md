# Fixtures for `Outpost_Source_Pinterest`

Offline fixtures for the F16 Pinterest adapter's unit tests.

## Fixture inventory

| File | Captured | From URL | Sanitization |
|---|---|---|---|
| `og-pin-success.html` | 2026-05-04 | synthetic | hand-authored Pinterest pin page with OG tags |

## Last verified live

Not applicable. F16 sticks to OG-only path; Pinterest API v5 requires
user OAuth (covered in Doc 1 outbound, deferred for inbound).

## Sanitization checklist

- [x] No real pin IDs, board names, or user handles
- [x] Synthetic placeholder values

## Notes specific to Pinterest

- Hosts: `pinterest.com`, `www.pinterest.com`, `pin.it` (short links).
- Path constraint: `/pin/...` on pinterest.com; pin.it short links
  match all paths since every pin.it URL redirects to a /pin/
  canonical URL.
- Board URLs (`/{user}/{board}/`) and profile URLs are NOT claimed —
  not single-pin events; they fall through to Source_Unknown.
