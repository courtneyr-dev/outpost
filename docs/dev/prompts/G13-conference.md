---
title: "G13 — Conference / CFP inbound"
branch: phase-g/g13-conference
base: main
depends: []
phase: G
status: ready-for-implementation
---

# G13 — Conference / CFP inbound

## Scope

Add Sessionize and Pretalx as inbound capture sources for conference schedules and speaker pages. Mobilizon already covered by F-phase ActivityPub generic adapter (verify and skip if confirmed).

## Files to create or modify

Create:

- `outpost/includes/adapters/class-sessionize-adapter.php`
- `outpost/includes/adapters/class-pretalx-adapter.php`
- One test file per adapter
- One docs page per adapter

Verify:

- Confirm F-phase ActivityPub generic adapter handles Mobilizon. If confirmed, skip Mobilizon work in this PR. If not, write to `.overnight-questions.md` and proceed with the two confirmed platforms.

## Design decisions locked

### Sessionize

1. **No OAuth.** Endpoint URL is the secret. User configures their event endpoint URL in settings (per-event).
2. **Read-only public API.** JSON, XML, iCal formats. Outpost uses JSON (`{endpoint_url}/api/v2/{endpoint_id}/View/All`).
3. **5-minute server-side cache** is Sessionize's; respect with HTTP cache headers.
4. **Inbound triggers:**
   - User pastes a session URL: `sessionize.com/{event}/session/{id}` → fetch session, render as `quote` Post Kind with speaker name as cite.
   - User pastes a speaker URL: `sessionize.com/{event}/speaker/{id}` → fetch speaker, render as `bookmark`.
5. **Post Format:** `aside` for both session and speaker captures.

### Pretalx

6. **Auth: API token** scoped per endpoint+event. Settings page has per-instance configuration: "Pretalx instance base URL" + "API token". Token entered manually (Pretalx has no OAuth flow).
7. **Public schedule endpoint** doesn't require auth and works without token; auth only needed for non-public submission data.
8. **Versioning:** Always send `Pretalx-Version: v1` (current stable). Document version-bump policy in adapter docs.
9. **Self-hosted support:** Settings allow multiple Pretalx instances (e.g. one for FOSDEM, one for PyConDE). User picks instance per inbound capture.
10. **Inbound triggers:**
    - Submission URL → fetch via `/api/events/{event}/submissions/{id}`, render as `quote` if accepted talk, `bookmark` if proposed.
    - Schedule URL → render as `bookmark` for the whole event.

### Shared

11. **Open-source alternative call-out:** Pretalx docs page recommends Pretalx for self-hosters as the open alternative to Sessionize (which is closed-source SaaS). Both are listed in the alternatives doc page (G16).
12. **Privacy:** Conference content is typically public; default to `public` post status. No per-post override needed.

## Implementation outline

- Sessionize adapter: GET request with stored endpoint URL; JSON parse; map to structured content.
- Pretalx adapter: REST client with token auth; schedule endpoint public, submissions endpoint authed.

## Tests

- Sessionize: known event + session URL returns expected speaker + abstract.
- Sessionize: invalid endpoint URL returns clean error.
- Pretalx: public schedule fetched without auth.
- Pretalx: authed submission fetched with token.
- Pretalx: missing token + non-public endpoint returns clear "auth required" error.

### wp-env stubs

- `test_sessionize_session_capture`
- `test_pretalx_schedule_public`

## Acceptance criteria

- [ ] Both adapters fetch from real public events (use FOSDEM Pretalx + a recent .NET conference Sessionize as test fixtures).
- [ ] Multi-instance Pretalx support tested.
- [ ] Tests pass.
- [ ] §5 audit lint passes.
- [ ] Pretalx docs page calls out the open-source advantage.

## PR description template

```
### Phase G — G13 — Conference / CFP inbound

Adds Sessionize and Pretalx as inbound capture sources. Mobilizon assumed covered by F-phase ActivityPub generic adapter; verified during pre-flight (see PR description for verification result).

Catalog reference: §11 G13 entry, §1 Calendar table. Detailed prompt: `outpost/docs/dev/prompts/G13-conference.md`.

### Test plan

12+ tests; 2 wp-env stubs.

### Merge order

Independent.
```

## Open items

- Mobilizon coverage verification: if F-phase ActivityPub generic adapter does NOT handle Mobilizon's specific event format, log to `.overnight-questions.md` with subject "Mobilizon needs dedicated adapter or AP generic extension". Continue with Sessionize + Pretalx in this PR.
