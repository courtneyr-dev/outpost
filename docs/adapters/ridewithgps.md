# Ride With GPS adapter

G12a ships the Ride With GPS OAuth provider: you can connect an RWG account and check it with the verify endpoint, which reads the user's profile. Nothing else in Outpost uses the connection yet. There's no RWG source class, so a shared RWG URL gets the generic preview, and there's no fetch-recent provider.

## Setup

1. Register an OAuth application at [https://ridewithgps.com/api](https://ridewithgps.com/api) with redirect URI `https://your-site.example/wp-json/outpost/v1/oauth/ridewithgps/callback`.
2. Add credentials to `wp-config.php`:

   ```php
   define( 'OUTPOST_RIDEWITHGPS_CLIENT_ID', 'xxx' );
   define( 'OUTPOST_RIDEWITHGPS_CLIENT_SECRET', 'yyy' );
   ```

3. In wp-admin, go to **Outpost → OAuth Connections** and click **Connect Ride With GPS**.

## OAuth quirks Outpost handles

- **`.json` suffix on token URL** — RWG's token endpoint is `https://ridewithgps.com/oauth/token.json` (note the suffix; without it RWG returns XML). Outpost's provider hardcodes the correct URL.
- **Long-lived tokens without refresh_token** — some RWG OAuth apps issue tokens that don't expire and don't include a `refresh_token` field. Outpost's `is_expired()` returns false when no `expires_in` is stored. Nothing in Outpost calls `refresh_access_token()`; if your code calls it on a token without a refresh_token, it returns `outpost_oauth_no_refresh_token` WP_Error rather than crashing.
- **No RFC 7009 revocation** — RWG does not currently expose a revoke endpoint. `revocation_endpoint()` returns null; `disconnect()` falls through to local credential delete.

## Privacy boundary for a route source class

RWG routes have a `privacy_code` field on the route object. Any code that reads route content **MUST NOT expose private routes** to non-owner viewers. The current verify endpoint here only reads `users/current.json` (the authenticated user's own profile), which is always accessible to the token holder.

A route source class must:

- Read the route's `privacy_code` field before exposing any route content.
- Treat any non-empty `privacy_code` as "do not redistribute."
- Show the user a "this route is private" error rather than silently dropping fields.

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

- **Source class** for RWG URLs (`ridewithgps.com/routes/{id}`, `ridewithgps.com/trips/{id}`). Not available yet; it would need the `privacy_code` handling described above.
- **Outbound** (write or upload trips to RWG). Not available; the provider doesn't request the `write` scope.
