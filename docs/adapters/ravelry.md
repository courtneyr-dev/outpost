# Ravelry adapter

G14b ships the Ravelry OAuth 2.0 provider: you can connect a Ravelry account and check it with the verify endpoint. Nothing else in Outpost uses the connection yet. There's no Ravelry source class, so a shared Ravelry URL gets the generic preview, and there's no fetch-recent provider.

## Setup

1. **OAuth 2.0 only.** Outpost does not support Ravelry's deprecated OAuth 1.0a. Users with old 1.0a apps must register a new OAuth 2.0 app at [https://www.ravelry.com/pro/developer](https://www.ravelry.com/pro/developer).
2. Set the redirect URI on the new app to `https://your-site.example/wp-json/outpost/v1/oauth/ravelry/callback`.
3. Add credentials to `wp-config.php`:

   ```php
   define( 'OUTPOST_RAVELRY_CLIENT_ID', 'xxx' );
   define( 'OUTPOST_RAVELRY_CLIENT_SECRET', 'yyy' );
   ```

4. In wp-admin, go to **Outpost → OAuth Connections** and click **Connect Ravelry**.

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

- **Standard OAuth 2.0** with refresh_token — the `offline` scope triggers refresh-token issuance per Ravelry docs. Outpost stores the refresh token but doesn't use it: `refresh_access_token()` has no caller. When the token expires, disconnect and reconnect under Outpost → OAuth Connections.
- **No RFC 7009 revocation** — `revocation_endpoint()` returns null at the time of writing. Disconnect falls through to local credential delete. Revoke the app's access in Ravelry to fully disconnect.
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

- **Source class** for Ravelry URLs (`ravelry.com/patterns/library/{id}`, `ravelry.com/projects/{user}/{slug}`). Not available yet.
- **Outbound** (write or update Ravelry projects from Outpost). Not available.
- **OAuth 1.0a** — explicitly dropped per design call.
