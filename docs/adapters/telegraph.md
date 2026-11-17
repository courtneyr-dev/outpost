# Telegraph

POSSE-outbound adapter for Telegraph (https://telegra.ph/) — Telegram's anonymous publishing surface. The user's WordPress post becomes canonical; a Telegraph copy publishes alongside with the WP post URL as the `author_url` link back.

## Why Telegraph

- **No OAuth.** First publish creates an anonymous Telegraph account and stores the access token; later publishes reuse it. Zero user-facing auth flow.
- **Pseudonymous-friendly.** The default account is keyed to the WP user, but per-post overrides on `author_name` and `author_url` let users publish under different identities for different posts.
- **Plain-text URLs.** `telegra.ph/short-slug-MM-DD` URLs are clean and copy-friendly — useful when sharing to Telegram chats that don't unfurl rich previews.

## URL immutability

Telegraph pages have **immutable URLs**. Editing a page replaces its content but never its URL. This adapter stores the resulting URL + path in post-meta on first publish. Outpost doesn't call `editPage`, so later edits to the WordPress post don't reach the Telegraph copy.

## Limited tag set

Telegraph's DOM accepts only:

- Block tags: `p`, `aside`, `blockquote`, `code`, `pre`, `figure`, `img`, `iframe` (whitelisted hosts only), `h3`, `h4`, `ul`, `ol`, `li`, `hr`
- Inline tags: `a`, `b`, `em`, `s`, `u`, `br`

Anything else gets stripped or downgraded by the converter:

- `h1` and `h2` collapse to `h3`. `h5` and `h6` collapse to `h4`.
- `iframe` only preserved when the URL host is YouTube, youtu.be, Vimeo, Twitter, or X. Other embeds drop entirely.
- Unsupported blocks (custom blocks, columns, gallery, etc.) fall back to a plain-text paragraph extracted via `wp_strip_all_tags`.

## Settings

Telegraph has no settings screen. Configure it with WordPress options, for example through WP-CLI.

**Turn it on first.** Telegraph publishes a public copy of each post to a third party, so it's off by default. Outpost registers the publish hook only when `outpost_telegraph_enabled` is truthy (`outpost.php`, on `plugins_loaded`), and `maybe_syndicate_on_publish()` checks the option again:

```bash
wp option update outpost_telegraph_enabled 1
```

| Option | Purpose | Default |
|---|---|---|
| `outpost_telegraph_enabled` | Opt-in switch. Nothing syndicates while it's false. | `false` |
| `outpost_telegraph_short_name` | Account short name, set when Outpost creates the Telegraph account | site name (`get_bloginfo('name')`) |
| `outpost_telegraph_author_name` | Author shown on each Telegraph page | `''` (no author) on pages; the account's author name falls back to the short name |
| `outpost_telegraph_author_url` | Link out from each Telegraph page | the post's permalink on pages; the account's URL falls back to the site home URL |

Outpost reads the short name, author name, and author URL into the Telegraph account only when it creates the account on first publish, so changing them later affects new pages but not the account.

Per-post overrides via post-meta (no UI; set via WP CLI or programmatically):

| Post-meta key | Effect |
|---|---|
| `_outpost_skip_telegraph` = `1` | Skip Telegraph syndication for this post |
| `outpost_telegraph_author_name_override` | Use this author name instead of the site default |
| `outpost_telegraph_author_url_override` | Use this URL instead of the site default |

## Password-protected posts

A post with a password is never syndicated. `maybe_syndicate_on_publish()` returns before any other check when `post_password` is non-empty (1.0.22), ahead of the enabled check, the post-type filter, and `_outpost_skip_telegraph`. Telegraph pages are public, so syndicating a protected post would publish its content to anyone with the link.

## Per-post output

After a successful first publish:

| Post-meta key | Value |
|---|---|
| `outpost_telegraph_post_url` | `https://telegra.ph/...` |
| `outpost_telegraph_page_path` | The path component (used by `editPage`) |

## Updates aren't available yet

Outpost publishes each post to Telegraph once. A post that already has `outpost_telegraph_post_url` is skipped on later transitions into `publish`, and edits don't call `editPage`. The adapter stores `outpost_telegraph_page_path`, which `editPage` would need.

## Token encryption

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

- Settings page UI (configure with WP-CLI or `update_option()`)
- Update / editPage path (first publish only)
- POSSE dispatcher integration. Telegraph hooks `transition_post_status` directly and doesn't extend `Outpost_POSSE_Destination_Base`, so it has no retry, no `_outpost_posse_targets` selection, and no `_outpost_posse_failures` record. See [POSSE outbound base class](../concepts/posse-base-class.md).
