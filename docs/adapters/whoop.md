# WHOOP adapter

G11b ships the WHOOP OAuth provider. Reads from WHOOP's developer/v1 API for the connected user's profile, sleep, recovery, cycles, and workouts. Future PRs add the source class.

Completes the wellness OAuth cluster started in G11a (Oura) — same shape, simpler than Oura because WHOOP doesn't have a separate membership-gate quirk.

## Setup

1. Register an OAuth application at [https://developer.whoop.com](https://developer.whoop.com) with redirect URI `https://your-site.example/wp-json/outpost/v1/oauth/whoop/callback`.
2. **App approval not required for personal use.** WHOOP requires app approval before public launch, but you can use your own app to authorize your own account without launch approval. The "approval" gate only applies when other WHOOP users would authorize your app.
3. Add credentials to `wp-config.php`:

   ```php
   define( 'OUTPOST_WHOOP_CLIENT_ID', 'xxx' );
   define( 'OUTPOST_WHOOP_CLIENT_SECRET', 'yyy' );
   ```

4. Visit Settings → OAuth Connections in wp-admin and click **Connect WHOOP**.

## OAuth quirks Outpost handles

- **Standard OAuth 2.0** authorize / token / refresh dance.
- **Tokens expire hourly.** The base provider's `is_expired()` + `refresh_access_token()` handle the refresh dance lazily on the next data fetch.
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

- **Source class** for WHOOP. Different UX shape than URL-paste platforms (RWG, Ravelry) — WHOOP doesn't have shareable activity URLs. The right share path is a "fetch recent workout/sleep" picker in the composer. UX call deserves its own sketch conversation; deferred.
- **Outbound** (Outpost → WHOOP) — out of scope; WHOOP's API is read-only for the consumer-facing endpoints.
- **Webhooks** — WHOOP can webhook on new data; future PR if needed.
