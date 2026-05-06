# Pretalx (hosted SaaS)

Inbound capture adapter for Pretalx (https://pretalx.com/) — the open-source CFP / conference scheduling platform. This adapter covers the **hosted SaaS at pretalx.com only**.

## Capture mode

- **Talk URLs:** `quote` (`u-quotation-of`)
- **Schedule + speaker URLs:** `bookmark` (`u-bookmark-of` via the dispatcher's mode-to-property mapping)
- **Extractor:** `og_tags`

## URL patterns claimed

| URL | Mode |
|---|---|
| `https://pretalx.com/{event}/talk/{id}` | `quote` |
| `https://pretalx.com/{event}/talk/{id}/` (trailing slash) | `quote` |
| `https://pretalx.com/{event}/schedule` | `bookmark` |
| `https://pretalx.com/{event}/speaker/{id}` | `bookmark` |
| Other paths under pretalx.com | falls through to Source_Unknown |

## Mapping

| OG meta | h-entry property |
|---|---|
| `og:title` | `p-name` |
| `og:description` | `p-summary` |
| `og:image` | `u-photo` |
| (source URL) | `u-quotation-of` |

The capabilities-level mapping uses `u-quotation-of` since the dominant case is talk-quoting. Schedule and speaker captures still get the URL captured via the dispatcher even though the user might prefer bookmark-shaped output for those — the per-URL mode override (`mode_for_url`) routes the composer to the right tab.

## What's NOT in G13a

- **Self-hosted Pretalx instances** (G13b). Self-hosted instances run at custom domains (`cfp.example.com`, etc.) and require user configuration. Wait on the G3.5 settings-UI foundation.
- **Sessionize** (G13c). Sessionize requires per-event endpoint URLs configured in settings (the URL is the auth secret). Same settings-UI dependency.
- **Pretalx REST API** (`/api/...`). The og_tags path is sufficient for v1; the API path lands when api_json extractor (F5 #6) is implemented.

## Open-source recommendation

Pretalx is the open-source self-hostable alternative to Sessionize. Outpost recommends Pretalx for self-hosting conferences in the [open-source alternatives doc page](../concepts/why-we-recommend-these-platforms.md) (PR #38 once it merges).

## Why Pretalx specifically

Pretalx is sister project to Pretix (the open-source ticketing platform), with the same maintainer base. Both are AGPL-licensed and self-hostable. v2026.1 is current. The hosted SaaS at pretalx.com runs many real conferences without lock-in — users who eventually self-host can move their event without changing platforms.
