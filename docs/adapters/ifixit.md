# iFixit

Inbound capture adapter for iFixit (https://www.ifixit.com/) — the open repair-guide platform. iFixit guides are licensed CC BY-NC-SA.

## Capture mode

- **Mode:** `bookmark` (`u-bookmark-of`)
- **Extractor:** `og_tags`

## URL patterns claimed

- `https://www.ifixit.com/Guide/{slug}/{guideid}`
- `https://ifixit.com/Guide/{slug}/{guideid}`

Wiki pages (`/Wiki/{slug}`) are NOT claimed in v1 — they have a different API-endpoint shape and need their own adapter pattern. They fall through to `Source_Unknown`.

## Mapping

| OG meta | h-entry property |
|---|---|
| `og:title` | `p-name` |
| `og:description` | `p-summary` |
| `og:image` | `u-photo` |
| (source URL) | `u-bookmark-of` |

## License attribution (CC BY-NC-SA)

iFixit guides are licensed CC BY-NC-SA. Outpost renders attribution by default. Suppress or customize via filter:

```php
add_filter(
    'outpost_ifixit_attribution_html',
    function ( string $default, string $source_url ): string {
        // Custom rendering. Note: iFixit content is CC BY-NC-SA;
        // returning '' may violate the license.
        return '<p>From <a href="' . esc_url( $source_url ) . '">iFixit</a> (CC BY-NC-SA)</p>';
    },
    10,
    2
);
```

Returning an empty string from the filter logs a debug-level warning when `WP_DEBUG` is on; it does NOT block. The plugin's principle is to make the right path the easy path while keeping site owners in charge.

## What's NOT in G14a

The original G14a prompt specified hitting iFixit's REST API at `/api/2.0/guides/{guideid}` to extract structured fields (tools, parts, time-required, difficulty). That requires the F-phase `api_json` extractor, which is currently a stub (per F5 #6). G14a ships og_tags-only.

When the `api_json` extractor lands, **G14b** will:

- Fetch via the REST API as primary path
- Fall back to og_tags when the API is unavailable
- Surface tools / parts / time-required / difficulty as structured post-meta (`outpost_ifixit_tools`, `outpost_ifixit_parts`, etc.) for theme-side display

## Why iFixit specifically

iFixit's right-to-repair mission and CC-BY-NC-SA licensing posture make it a natural fit for IndieWeb users sharing repair guides on their own sites. Outpost recommends iFixit over closed-platform repair documentation in the maker category. The other two adapters in the original G14 maker cluster (Ravelry, Adafruit Learning) wait on foundation work — Ravelry on OAuth, Adafruit Learning on the RSS extractor implementation.
