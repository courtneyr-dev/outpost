# Snipd

Inbound capture adapter for Snipd (https://snipd.com/) — an AI-podcast-snipping platform. The adapter handles three Snipd public-share URL forms with per-path Post Kind suggestions.

## URL patterns claimed

The adapter recognizes share URLs under `share.snipd.com`:

- `https://share.snipd.com/snip/{id}` — a single AI-extracted moment from a podcast episode
- `https://share.snipd.com/episode/{id}` — a full podcast episode reference
- `https://share.snipd.com/show/{id}` — a podcast show landing page

Profile URLs (`/user/{handle}`) and Snipd's homepage are NOT claimed; they fall through to Source_Unknown.

## Per-path Post Kind suggestion (G15a)

| URL path | Post Kind | Rationale |
|---|---|---|
| `/snip/{id}` | `quote` | The snip is a transcript excerpt — the natural mf2 fit is `quote` with the timestamped link as the citation. |
| `/episode/{id}` | `listen` | The episode itself is a single listen event; preserves F-phase behavior. |
| `/show/{id}` | `bookmark` | A show is a discovery target, not a single listen event. |
| Other Snipd paths | `bookmark` | Defensive default; matches_url() filters to the three claimed prefixes before mode_for_url runs, so this is reachable only for future path additions. |

## Extraction

Snipd's public share pages emit standard Open Graph meta tags. The adapter uses the F-phase `og_tags` extractor to pull `og:title` (snip / episode title), `og:description` (Snipd's auto-generated summary), `og:image` (episode cover), and the share URL itself.

## Mapping

| OG meta | h-entry property |
|---|---|
| `og:title` | `p-name` |
| `og:description` | `p-summary` |
| `og:image` | `u-photo` |
| (source URL) | `u-listen-of` |

## What's NOT in G15a

The original G15 prompt specified six enhancements per `posse-expansion-may-2026.md` §8. G15a ships items 1 (URL kind detection) only.

- Item 2 (OG fallback when Snipd API fails) is moot for this adapter — Snipd has no public API; OG IS the primary path. Documented here for future readers wondering why item 2 is absent.
- Items 3-6 (composite enrichment, Apple Podcasts cross-lookup, multi-clip stitching, structured-blocks artwork replacement) ship as G15b after the G4 composite primitive (PR #35) merges.
