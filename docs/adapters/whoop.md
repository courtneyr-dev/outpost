# WHOOP adapter

G11b ships the WHOOP OAuth provider. Reads from WHOOP's developer/v1 API for the connected user's profile, sleep, recovery, cycles, and workouts. The fetch-recent picker uses this connection; see [WHOOP fetch-recent](whoop-fetch-recent.md).

Completes the wellness OAuth cluster started in G11a (Oura) — same shape, simpler than Oura because WHOOP doesn't have a separate membership-gate quirk.

## Setup

1. Register an OAuth application at [https://developer.whoop.com](https://developer.whoop.com) with redirect URI `https://your-site.example/wp-json/outpost/v1/oauth/whoop/callback`.
2. **App approval not required for personal use.** WHOOP requires app approval before public launch, but you can use your own app to authorize your own account without launch approval. The "approval" gate only applies when other WHOOP users would authorize your app.
3. Add credentials to `wp-config.php`:

   ```php
   define( 'OUTPOST_WHOOP_CLIENT_ID', 'xxx' );
   define( 'OUTPOST_WHOOP_CLIENT_SECRET', 'yyy' );
   ```

4. In wp-admin, go to **Outpost → OAuth Connections** and click **Connect WHOOP**.

## OAuth quirks Outpost handles

- **Standard OAuth 2.0** authorize and token exchange.
- **Tokens expire hourly.** Outpost doesn't refresh tokens automatically: `refresh_access_token()` exists in the base provider, but nothing calls it. When the token expires, disconnect and reconnect under **Outpost → OAuth Connections**.
- **No RFC 7009 revocation.** WHOOP exposes a custom `DELETE /developer/v2/user/access` endpoint instead. Outpost overrides `disconnect()` on the WHOOP provider to call this with a Bearer header before deleting local credentials. Failures fall through to local-delete only — disconnect always succeeds locally.
- **No membership gate.** Unlike Oura (G11a), WHOOP doesn't gate API access by separate active membership beyond OAuth validity. A lapsed membership surfaces standard 401 — handled by the existing token-rejection path.

## Verify-connection endpoint

`GET /wp-json/outpost/v1/oauth/whoop/verify` returns one of:

- `{ ok: true, first_name: "…", user_id: NNN }` — token works, profile fetched
- `{ ok: false, reason: "no_credentials" }` — no creds stored
- `{ ok: false, reason: "auth_failed", status: NNN }` — token rejected (expired, revoked, etc.)
- `{ ok: false, reason: "transport_failed" }` — network error

Capability gate: `manage_options` + logged-in user (matches Connect/Disconnect).

## Storage scope filter

Per-user storage by default. Site-wide via `add_filter('outpost_credentials_storage_scope_whoop', fn() => 'site')` — same precedent as G3.5a Notion. Personal WHOOP straps almost always want per-user, so the default holds.

## What's NOT in G11b

- **URL source class.** WHOOP has no shareable activity URLs, so there's no URL-paste source. The fetch-recent picker (`includes/sources/class-outpost-fetch-recent-whoop.php`) covers composing from WHOOP data.
- **Outbound** (Outpost → WHOOP) — out of scope; WHOOP's API is read-only for the consumer-facing endpoints.
- **Webhooks.** WHOOP can send webhooks on new data; Outpost doesn't receive them.
