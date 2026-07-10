# Telegraph

POSSE-outbound adapter for Telegraph (https://telegra.ph/) — Telegram's anonymous publishing surface. The user's WordPress post becomes canonical; a Telegraph copy publishes alongside with the WP post URL as the `author_url` link back.

## Why Telegraph

- **No OAuth.** First publish creates an anonymous Telegraph account and stores the access token; later publishes reuse it. Zero user-facing auth flow.
- **Pseudonymous-friendly.** The default account is keyed to the WP user, but per-post overrides on `author_name` and `author_url` let users publish under different identities for different posts.
- **Plain-text URLs.** `telegra.ph/short-slug-MM-DD` URLs are clean and copy-friendly — useful when sharing to Telegram chats that don't unfurl rich previews.

## URL immutability

Telegraph pages have **immutable URLs**. Editing a page replaces its content but never its URL. This adapter stores the resulting URL + path in post-meta on first publish so subsequent publishes can call `editPage` with the correct path.

## Limited tag set

Telegraph's DOM accepts only:

- Block tags: `p`, `aside`, `blockquote`, `code`, `pre`, `figure`, `img`, `iframe` (whitelisted hosts only), `h3`, `h4`, `ul`, `ol`, `li`, `hr`
- Inline tags: `a`, `b`, `em`, `s`, `u`, `br`

Anything else gets stripped or downgraded by the converter:

- `h1` and `h2` collapse to `h3`. `h5` and `h6` collapse to `h4`.
- `iframe` only preserved when the URL host is YouTube, youtu.be, Vimeo, Twitter, or X. Other embeds drop entirely.
- Unsupported blocks (custom blocks, columns, gallery, etc.) fall back to a plain-text paragraph extracted via `wp_strip_all_tags`.

## Settings

The G9 PR ships the adapter + converter only. Settings UI is out of scope for this PR — configure for v1 via WP options:

| Option | Purpose | Default |
|---|---|---|
| `outpost_telegraph_short_name` | Shown on Telegraph as the account short name | site name (`get_bloginfo('name')`) |
| `outpost_telegraph_author_name` | Default author shown on each Telegraph page | site name |
| `outpost_telegraph_author_url` | Default link out from Telegraph (canonical link) | site home URL |

Per-post overrides via post-meta (no UI; set via WP CLI or programmatically):

| Post-meta key | Effect |
|---|---|
| `_outpost_skip_telegraph` = `1` | Skip Telegraph syndication for this post |
| `outpost_telegraph_author_name_override` | Use this author name instead of the site default |
| `outpost_telegraph_author_url_override` | Use this URL instead of the site default |

## Per-post output

After a successful first publish:

| Post-meta key | Value |
|---|---|
| `outpost_telegraph_post_url` | `https://telegra.ph/...` |
| `outpost_telegraph_page_path` | The path component (used by `editPage`) |

## Update path deferred

The G9 PR ships the **first-publish** path. Updates (calling `editPage` with the stored path on subsequent post saves) are a focused follow-up — the adapter stores everything `editPage` needs (`outpost_telegraph_page_path`).

## Encryption deferred

Per-user access tokens live in the encrypted credentials store (`Outpost_Credentials_Store`, provider `telegraph`) as of 1.0.1. Tokens written by earlier versions to plain user meta migrate automatically on first use, and the plaintext copy is deleted once the encrypted write succeeds.

## Pseudonymous use case

A user writing under different identities for different posts (e.g., professional posts under their real name, personal posts under a pen name) sets `outpost_telegraph_author_name_override` per post. The Telegraph copy reflects that identity while the WP post stays under the canonical author.

## Filter surface

```php
// Restrict which post types syndicate to Telegraph.
add_filter( 'outpost_telegraph_post_types', function () {
    return array( 'post', 'note' );
} );
```

## What's NOT in G9

- Settings page UI (configure via wp-cli for v1)
- Update / editPage path (first-publish only — adapter stores what's needed for updates)
- Posse_Adapter_Base extension — Telegraph hooks `transition_post_status` directly. The `Posse_Adapter_Base` from G5 prompt does not yet exist; once it lands, refactoring Telegraph to extend it is a small mechanical change.
