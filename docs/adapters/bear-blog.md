# Bear Blog

Inbound capture adapter for Bear Blog (https://bearblog.dev/) — a minimalist blogging platform that runs user blogs at `{slug}.bearblog.dev` plus optional custom domains.

## Capture mode

- **Mode:** `read` (`u-read-of`)
- **Extractor:** `og_tags`
- **Default suggestion:** Read tab in the composer; the user can switch to Bookmark or Quote variants if they prefer.

## URL patterns claimed

By default:

- `https://{slug}.bearblog.dev/...`

Custom domains via filter:

```php
add_filter(
    'outpost_bear_blog_domain_patterns',
    function ( array $patterns ): array {
        $patterns[] = 'blog.example.com';            // exact host
        $patterns[] = '*.example-self-hosted.test';  // subdomain wildcard
        return $patterns;
    }
);
```

The apex `bearblog.dev` is intentionally NOT claimed — that's the company's marketing site and falls through to Source_Unknown.

## Mapping

| OG meta | h-entry property |
|---|---|
| `og:title` | `p-name` |
| `og:description` | `p-summary` |
| `og:image` | `u-photo` |
| (source URL) | `u-read-of` |

## RSS-as-primary deferred

The G6 prompt specifies RSS as the primary fetch path with OG fallback. The F-phase RSS extractor stub is not yet implemented (per F5 #6), so this PR ships og_tags-only following the F17 pattern. RSS-as-primary lands alongside the RSS extractor implementation in a future Phase G session — additive, doesn't change the response shape.

## Open-source recommendation

Bear Blog is independently maintained; the platform supports custom domains for paid users and is friendly to IndieWeb posting. Outpost recommends Bear Blog over hosted-only blogging platforms (Medium, Substack) for users who want a personal, sustainable platform without algorithmic distribution pressure.
