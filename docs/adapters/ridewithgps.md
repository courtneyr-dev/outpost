# Ride With GPS adapter

G12a ships the Ride With GPS OAuth provider. Reads from RWG's v1 API for the connected user's profile and routes. Future PRs add the source class (URL detection + content fetch + composer integration).

## Setup

1. Register an OAuth application at [https://ridewithgps.com/api](https://ridewithgps.com/api) with redirect URI `https://your-site.example/wp-json/outpost/v1/oauth/ridewithgps/callback`.
2. Add credentials to `wp-config.php`:

   ```php
   define( 'OUTPOST_RIDEWITHGPS_CLIENT_ID', 'xxx' );
   define( 'OUTPOST_RIDEWITHGPS_CLIENT_SECRET', 'yyy' );
   ```

3. Visit Settings → OAuth Connections in wp-admin and click **Connect Ride With GPS**.

## OAuth quirks Outpost handles

- **`.json` suffix on token URL** — RWG's token endpoint is `https://ridewithgps.com/oauth/token.json` (note the suffix; without it RWG returns XML). Outpost's provider hardcodes the correct URL.
- **Long-lived tokens without refresh_token** — some RWG OAuth apps issue tokens that don't expire and don't include a `refresh_token` field. Outpost's `is_expired()` returns false when no `expires_in` is stored, so the lazy-refresh path doesn't fire spuriously. If `refresh_access_token()` is called manually on a token without a refresh_token, it returns `outpost_oauth_no_refresh_token` WP_Error rather than crashing.
- **No RFC 7009 revocation** — RWG does not currently expose a revoke endpoint. `revocation_endpoint()` returns null; `disconnect()` falls through to local credential delete.

## Privacy boundary (for the future source-class implementer)

RWG routes have a `privacy_code` field on the route object. Future source-class work that reads route content **MUST NOT expose private routes** to non-owner viewers. The current verify endpoint here only reads `users/current.json` (the authenticated user's own profile), which is always accessible to the token holder.

When the source class lands:

- Read the route's `privacy_code` field before exposing any route content
- Treat any non-empty `privacy_code` as "do not redistribute"
- Surface a "this route is private" error to the user rather than silently dropping fields

## Verify-connection endpoint

`GET /wp-json/outpost/v1/oauth/ridewithgps/verify` returns one of:

- `{ ok: true, name: "…", id: NNN }` — token works, profile fetched
- `{ ok: false, reason: "no_credentials" }` — no creds stored
- `{ ok: false, reason: "auth_failed", status: NNN }` — token rejected
- `{ ok: false, reason: "transport_failed" }` — network error

The verifier tolerates both `{user: {id, name}}` and flat `{id, name}` shapes — the RWG API has had both at various revisions.

## Storage scope filter

Per-user storage by default. Site-wide via `add_filter('outpost_credentials_storage_scope_ridewithgps', fn() => 'site')` — same precedent as G3.5a Notion.

## What's NOT in G12a

- **Source class** for RWG URL pattern detection (e.g., `ridewithgps.com/routes/{id}`, `ridewithgps.com/trips/{id}`) + content fetch + composer integration. Deferred to a follow-up PR; the source class needs the privacy_code handling described above.
- **Outbound** (write/upload trips to RWG) — the `write` scope is deferred until outbound trip-sync is in scope, which is a future PR.
