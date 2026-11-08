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

iFixit guides are licensed CC BY-NC-SA. Outpost doesn't add attribution to your post automatically: `Outpost_Source_Ifixit::attribution_html( $source_url )` (`includes/sources/class-source-ifixit.php`) builds the markup, `<p class="outpost-ifixit-attribution">Source: iFixit (CC BY-NC-SA)</p>` with a link to the guide, but nothing in Outpost calls it. Add attribution yourself when you post iFixit content, by writing it into the post or calling the helper from your theme. The `outpost_ifixit_attribution_html` filter customizes what the helper returns:

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

Structured fields (tools, parts, time required, difficulty) aren't available yet. Outpost doesn't call iFixit's REST API and writes no `outpost_ifixit_*` post meta.

## Why iFixit specifically

iFixit's right-to-repair mission and CC-BY-NC-SA licensing posture make it a natural fit for IndieWeb users sharing repair guides on their own sites. Outpost recommends iFixit over closed-platform repair documentation in the maker category. Of the other two adapters in the original G14 maker cluster, Ravelry has an OAuth provider but no source class ([Ravelry](ravelry.md)), and Adafruit Learning has no code in Outpost.
