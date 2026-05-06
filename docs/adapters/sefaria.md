# Sefaria

Inbound capture adapter for Sefaria (https://www.sefaria.org/) — the open Jewish-text library covering Tanakh, Talmud, Mishnah, commentaries, and more under CC0 / CC-BY / CC-BY-SA depending on the work.

## Capture mode

- **Mode:** `quote` (`u-quotation-of`)
- **Extractor:** `og_tags`

The mode reflects the typical use case: the user is sharing a verse or passage they want to quote on their own site.

## URL patterns claimed

- `https://www.sefaria.org/...`
- `https://sefaria.org/...`

Sefaria's URL paths are reference-based (e.g., `/Genesis.1.1`, `/Bereishit_Rabbah.1.1`). The adapter claims any path under those hosts.

## Mapping

| OG meta | h-entry property |
|---|---|
| `og:title` | `p-name` |
| `og:description` | `p-summary` |
| `og:image` | `u-photo` |
| (source URL) | `u-quotation-of` |

## Attribution

Sefaria texts have varied licenses (CC0, CC-BY, CC-BY-SA) depending on the work. The og_tags adapter cannot detect which license applies to a given URL. The recommended attribution line is:

> Source: [Sefaria.org](https://www.sefaria.org/)

License-aware attribution arrives with G10b once Sefaria's `/api/v3/texts/{ref}` endpoint is integrated and the response's per-text license metadata can be rendered correctly.

## RTL handling

Sefaria's `og:description` often contains both Hebrew (RTL) and English (LTR). For G10a, the description is rendered as-is — the user's theme's typography handles bidirectional text. Conservative bidi wrapping based on Unicode-block scanning is a G10b enhancement.

## What's NOT in G10a

- API-aware capture (citation parsing, per-translation selection)
- License detection per text
- Hebrew / English bidirectional rendering hints
- Cross-translation switching

All of these arrive with G10b after the API integration, citation parser, and license-aware attribution helpers ship.

## Why Sefaria specifically

Sefaria is a small open-source nonprofit with a comprehensive API and a CC-friendly licensing posture. Outpost recommends Sefaria for users sharing Jewish-text references over closed bible-aggregator platforms — it's the strongest open-source replacement in the scripture category.
