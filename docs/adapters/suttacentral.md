# SuttaCentral

Inbound capture adapter for SuttaCentral (https://suttacentral.net/) — the open Buddhist-canon library covering Pali, Sanskrit, Chinese, and Tibetan texts. Most modern translations (notably Bhikkhu Sujato's) are under CC0.

## Capture mode

- **Mode:** `quote` (`u-quotation-of`)
- **Extractor:** `og_tags`

## URL patterns claimed

- `https://suttacentral.net/...` (apex only)

Note: SuttaCentral's canonical URLs use the apex `suttacentral.net`, not `www.suttacentral.net`. The adapter claims apex only; if a future change introduces a `www.` redirect, that's a one-line addition to `host_patterns`.

URL paths are sutta-reference-based (e.g., `/dn1/en/sujato` for Digha Nikaya 1 in Sujato's English translation, `/dhp1-20` for Dhammapada verses 1-20).

## Mapping

| OG meta | h-entry property |
|---|---|
| `og:title` | `p-name` |
| `og:description` | `p-summary` |
| `og:image` | `u-photo` |
| (source URL) | `u-quotation-of` |

## Attribution

SuttaCentral texts are mostly CC0 (Bhikkhu Sujato translations) with some CC-BY translations. The recommended attribution line is:

> Source: [SuttaCentral.net](https://suttacentral.net/)

Translator-aware attribution (e.g., "translated by Bhikkhu Sujato") arrives with G10b once SuttaCentral's `/api/...` endpoints are integrated and per-translation metadata can be rendered.

## What's NOT in G10a

- API-aware capture (per-translation selection, root-text alongside translation)
- Translator metadata in attribution
- Cross-script handling (Pali / Sanskrit / Chinese / Tibetan)
- Sutta-reference normalization (e.g., DN 1 → suttacentral.net/dn1)

These arrive with G10b after SuttaCentral's API integration ships.

## Why SuttaCentral specifically

SuttaCentral is the open-source open-license counterpart to closed Buddhist-canon platforms. The licensing posture (mostly CC0) makes it ideal for IndieWeb users quoting Buddhist texts on their own sites without copyright concerns. Outpost recommends SuttaCentral over closed-platform alternatives in this category.
