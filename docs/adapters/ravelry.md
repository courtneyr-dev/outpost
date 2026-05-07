# Ravelry adapter

G14b ships the Ravelry OAuth 2.0 provider. Reads from Ravelry's API for the connected user's patterns library, projects, and queue. Future PRs add the source class.

## Setup

1. **OAuth 2.0 only.** Outpost does not support Ravelry's deprecated OAuth 1.0a. Users with old 1.0a apps must register a new OAuth 2.0 app at [https://www.ravelry.com/pro/developer](https://www.ravelry.com/pro/developer).
2. Set the redirect URI on the new app to `https://your-site.example/wp-json/outpost/v1/oauth/ravelry/callback`.
3. Add credentials to `wp-config.php`:

   ```php
   define( 'OUTPOST_RAVELRY_CLIENT_ID', 'xxx' );
   define( 'OUTPOST_RAVELRY_CLIENT_SECRET', 'yyy' );
   ```

4. Visit Settings → OAuth Connections in wp-admin and click **Connect Ravelry**.

## Scopes — verify before production

The default scope set is:

- `offline` — required to receive a `refresh_token`
- `personal-data`
- `library-read`
- `projects-read`
- `patterns-read`

**Verify these against the current Ravelry developer dashboard before production deployment.** Ravelry's scope-naming convention has shifted across API revisions (kebab-case vs underscore-prefixed). If the live dashboard shows different syntax, override via the `outpost_oauth_provider_ravelry_scopes` filter:

```php
add_filter( 'outpost_oauth_provider_ravelry_scopes', static function () {
    return array( 'offline', 'whatever_the_current_strings_are' );
} );
```

## OAuth quirks Outpost handles

- **Standard OAuth 2.0** with refresh_token — the `offline` scope triggers refresh-token issuance per Ravelry docs.
- **No RFC 7009 revocation** — `revocation_endpoint()` returns null at the time of writing. Disconnect falls through to local credential delete. If Ravelry adds revocation support in a future API revision, override this method.
- **Tolerates both nested `{user: {...}}` and flat `{...}` response shapes** on `current_user.json` — Ravelry's API has had both at various revisions.

## Verify-connection endpoint

`GET /wp-json/outpost/v1/oauth/ravelry/verify` returns one of:

- `{ ok: true, username: "…", display_name: "…", id: NNN }` — token works, profile fetched
- `{ ok: false, reason: "no_credentials" }` — no creds stored
- `{ ok: false, reason: "auth_failed", status: NNN }` — token rejected (could be expired, scope-related, etc.)
- `{ ok: false, reason: "transport_failed" }` — network error

The verifier reads either `displayname` or `display_name` to populate the `display_name` field — Ravelry's API has used both spellings.

## Storage scope filter

Per-user storage by default. Site-wide via `add_filter('outpost_credentials_storage_scope_ravelry', fn() => 'site')` — same precedent as G3.5a Notion. Knit/crochet libraries are personal collections; per-user is almost always right.

## What's NOT in G14b

- **Source class** for Ravelry URL pattern detection (e.g., `ravelry.com/patterns/library/{id}`, `ravelry.com/projects/{user}/{slug}`) + content fetch + composer integration. Deferred to a follow-up PR.
- **Outbound** (write/update Ravelry projects from Outpost) — out of scope for the foundation; future PR if needed.
- **OAuth 1.0a** — explicitly dropped per design call.
