# Mataroa

Inbound capture adapter for Mataroa (https://mataroa.blog/) — an open-source minimalist blogging platform. User blogs run at `{slug}.mataroa.blog`; site owners running self-hosted Mataroa instances extend recognition via filter.

## Capture mode

- **Mode:** `read` (`u-read-of`)
- **Extractor:** `og_tags`

## URL patterns claimed

By default:

- `https://{slug}.mataroa.blog/...`

Custom domains via filter:

```php
add_filter(
    'outpost_mataroa_domain_patterns',
    function ( array $patterns ): array {
        $patterns[] = 'mataroa.example.test';
        return $patterns;
    }
);
```

The apex `mataroa.blog` is NOT claimed.

## Mapping

| OG meta | h-entry property |
|---|---|
| `og:title` | `p-name` |
| `og:description` | `p-summary` |
| `og:image` | `u-photo` |
| (source URL) | `u-read-of` |

## RSS-as-primary deferred

Same note as Bear Blog: ships og_tags-only; RSS-as-primary lands when the F-phase RSS extractor stub gets a concrete implementation.

## Open-source recommendation

Mataroa is fully open-source (AGPLv3) and self-hostable. Outpost recommends Mataroa for users who want full ownership of their blogging stack — sister recommendation to Bear Blog for hosted, and Listmonk for newsletter sending.
