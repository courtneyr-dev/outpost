# Fixtures for `Outpost_Source_<NAME>`

This directory holds offline fixtures for the `<source_id>` source's
unit and integration tests. Every Phase F `Source_*` adapter that ships
tests copies this template into its own subdirectory and fills in the
fields below.

The fixture directory convention is locked at `tests/fixtures/sources/{source_id}/{scenario}.{ext}`
(F8 Session Log). Adapters never read fixtures from anywhere else.

## Fixture inventory

| File | Captured | From URL | Sanitization |
|---|---|---|---|
| `oembed-track-success.json` | YYYY-MM-DD | (synthetic OR public-durable canonical URL) | (which fields scrubbed; "synthetic placeholder values" if the fixture is hand-written) |
| `oembed-album-success.json` | YYYY-MM-DD | ... | ... |

Add a row per fixture file. Date is when the response shape was last
captured or hand-authored against the upstream API. "Sanitization" lists
the fields stripped or replaced with placeholders before commit.

## Last verified live

YYYY-MM-DD — `composer test:live` against this source last passed on
this date. Rerun quarterly to catch upstream contract drift.

## Sanitization checklist

Run through this list before committing any fixture:

- [ ] No personal handles, account names, or user IDs
- [ ] No API keys, tokens, or session identifiers
- [ ] No tracking parameters in URLs unless the test scenario
      explicitly exercises tracking-parameter handling
- [ ] Public durable content (a major artist's hit) OR entirely
      synthetic (made-up titles using example-data conventions)
- [ ] `composer lint:section5` passes against the fixture content

## When to add a new fixture

Pair fixtures with tests. Adding a fixture without a test that consumes
it leaves an orphan that rots; adding a test that reuses an existing
fixture for a scenario it wasn't captured for couples the test to
unrelated fixture details. New scenario → new fixture.

## When to refresh a fixture

- The upstream API changes its response shape (caught by quarterly
  `composer test:live`)
- A new field becomes load-bearing for a downstream consumer
- The fixture's date is older than 12 months and hasn't been verified
