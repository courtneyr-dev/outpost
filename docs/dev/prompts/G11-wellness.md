---
title: "G11 — Wellness API cluster"
branch: phase-g/g11-wellness
base: main
depends: []
phase: G
status: ready-for-implementation
---

# G11 — Wellness API cluster

## Scope

Add WHOOP, Oura Ring, and Polar Flow as inbound activity capture sources. Each uses OAuth 2.0; each has membership/subscription gating that surfaces as 402 or specific 4xx codes. Implement generic 402 handling per Phase G decision 4 (default mode).

## Files to create or modify

Create:

- `outpost/includes/adapters/wellness/class-wellness-adapter-base.php` — shared OAuth 2.0 + 402 handling
- `outpost/includes/adapters/wellness/class-whoop-adapter.php`
- `outpost/includes/adapters/wellness/class-oura-adapter.php`
- `outpost/includes/adapters/wellness/class-polar-flow-adapter.php`
- One test file per adapter
- One docs page per adapter

## Design decisions locked

1. **All three use OAuth 2.0.** WHOOP at `developer.whoop.com`, Oura V2 (V1 removed Jan 22 2024), Polar AccessLink. Settings page has three "Connect" buttons; each runs standard OAuth code-exchange flow and stores access + refresh tokens.
2. **Refresh tokens:** WHOOP refresh hourly; Oura tokens last longer (per Oura V2 docs); Polar refresh per AccessLink docs. Each adapter handles its own refresh; shared base provides a `refresh_if_expired()` helper.
3. **Membership-gate handling (Phase G decision 4 default):**
   - Oura returns 401 with specific error string when Membership lapsed for Gen3/Ring4. Adapter detects this string, surfaces a dismissible admin notice: "Oura Ring data requires an active Oura Membership. Manage at ouraring.com/account."
   - WHOOP membership-required errors surface similarly: "WHOOP membership required. Manage at whoop.com/account."
   - Polar has no comparable gate; standard auth errors handled normally.
   - Generic notice text intentionally; per-platform UX polish deferred to Phase H.
4. **Inbound capture:** User pastes a WHOOP/Oura/Polar URL or activity ID into the composer. Adapter fetches the activity (workout, sleep, recovery, daily ring data), returns structured content for posting.
5. **Post Kind suggestion:**
   - Workout / activity → `workout` Post Kind (if Post Kinds plugin has it; else `note` with activity-specific Post Format).
   - Sleep summary → `note` with custom Post Format `aside`.
   - Recovery / readiness → `note` with custom Post Format `aside`.
6. **Privacy:** Default post status for wellness-derived posts is `private`. User can override per-post. Document this default prominently in each docs page.
7. **No outbound.** v1 is read-only. Phase H may add WHOOP workout posting if user demand surfaces.

## Implementation outline

- Each adapter extends `Wellness_Adapter_Base`.
- Each implements: `oauth_authorization_url()`, `oauth_token_endpoint()`, `fetch_activity( $id )`, `format_activity_content( $activity )`.
- Shared base handles: OAuth state + code exchange, token refresh, 402/membership-gate detection and notice surfacing.

## Tests

- OAuth code exchange happy path.
- Token refresh on 401 (one retry per request).
- Membership-gate detection: Oura `auth.expired_oura_membership` error string triggers notice.
- Activity fetch: workout endpoint returns structured data for each platform.
- Post status default: wellness-derived posts default to `private`.

### wp-env stubs

- `test_whoop_oauth_refresh`
- `test_oura_membership_gate_notice`
- `test_polar_workout_capture`

## Acceptance criteria

- [ ] OAuth flow works for all three.
- [ ] Token refresh transparent to adapter consumers.
- [ ] Membership-gate notices fire on documented error conditions.
- [ ] Activity → post content rendering tested.
- [ ] Tests pass.
- [ ] §5 audit lint passes.
- [ ] Docs pages written; privacy default called out.

## PR description template

```
### Phase G — G11 — Wellness API cluster

Adds WHOOP, Oura, Polar Flow as inbound capture sources. OAuth 2.0 across the board. Generic membership-gate handling (Phase G decision 4 default).

Wellness-derived posts default to `private` status; user override per post.

Catalog reference: §11 G11 entry, §6 Fitness table. Detailed prompt: `outpost/docs/dev/prompts/G11-wellness.md`.

### Test plan

20+ tests across three platforms + shared base. 3 wp-env stubs.

### Merge order

Independent.
```

## Open items

None for v1. Phase H candidates: workout outbound (post a planned workout to WHOOP), per-platform UX polish on membership-gate notices (decision 4 polish work).
