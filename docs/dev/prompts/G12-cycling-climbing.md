---
title: "G12 — Cycling + climbing cluster"
branch: phase-g/g12-cycling-climbing
base: main
depends: []
phase: G
status: ready-for-implementation
---

# G12 — Cycling + climbing cluster

## Scope

Add Ride With GPS as cycling activity capture (OAuth + REST) and OpenBeta as climbing route inbound (open GraphQL, CC0 data). Both inbound primarily; RWG can sync activities outbound to a configured route library. Document OpenBeta as the open-source alternative to Mountain Project (which has API deprecated since late 2020).

## Files to create or modify

Create:

- `outpost/includes/adapters/class-ride-with-gps-adapter.php`
- `outpost/includes/adapters/class-openbeta-adapter.php`
- One test file per adapter
- One docs page per adapter

## Design decisions locked

### Ride With GPS

1. **Auth: OAuth 2.0** at `ridewithgps.com/oauth/authorize`. Settings page has "Connect Ride With GPS" button.
2. **Inbound:** User pastes RWG route or trip URL. Adapter fetches route metadata (distance, elevation, surface mix, GPX URL).
3. **Outbound:** User can opt to sync WP-published "ride report" posts as Trip notes on RWG. Default off.
4. **Post Kind suggestion:** `workout` for trips; `note` with Post Format `aside` for routes.
5. **Privacy:** Routes are public-by-default on RWG (user choice); inbound captures respect RWG's `privacy_code` field. Don't expose private routes.

### OpenBeta

6. **No auth.** Public GraphQL endpoint at `api.openbeta.io/graphql`.
7. **Inbound:** User pastes OpenBeta URL. Adapter queries climb metadata (grade, type, length, location, FA, description if CC0).
8. **Post Kind suggestion:** `note` with Post Format `aside`.
9. **License attribution:** OpenBeta data is CC0 (per their docs); attribution recommended but not required. Adapter renders attribution line by default; user can disable per post.

### Shared

10. **Privacy default:** cycling and climbing posts default to `public` (these are typically share-out activities, unlike wellness data).
11. **Open-source alternative call-out:** OpenBeta docs page has a prominent "Why we recommend OpenBeta" section explaining Mountain Project's API deprecation (per Phase G decision 3 normative recommendation).

## Implementation outline

- RWG adapter handles OAuth + REST; standard pattern.
- OpenBeta adapter is a thin GraphQL client; query templates inline.
- Both implement `Inbound_Adapter` interface.

## Tests

- RWG OAuth flow + token refresh.
- RWG private route returns appropriate error (no leak).
- OpenBeta route lookup happy path.
- OpenBeta attribution rendered by default; respects per-post disable.

### wp-env stubs

- `test_rwg_route_capture`
- `test_openbeta_climb_lookup`

## Acceptance criteria

- [ ] Both adapters fetch metadata from real-world test URLs.
- [ ] RWG privacy boundary respected.
- [ ] OpenBeta CC0 attribution rendering tested.
- [ ] Open-source alternative call-out present in OpenBeta docs.
- [ ] Tests pass.
- [ ] §5 audit lint passes.

## PR description template

```
### Phase G — G12 — Cycling + climbing cluster

Adds Ride With GPS (OAuth) and OpenBeta (open GraphQL). OpenBeta documented as the open-source alternative to Mountain Project per Phase G decision 3.

Catalog reference: §11 G12 entry, §6 Fitness table. Detailed prompt: `outpost/docs/dev/prompts/G12-cycling-climbing.md`.

### Test plan

12+ tests; 2 wp-env stubs.

### Merge order

Independent.
```

## Open items

None.
