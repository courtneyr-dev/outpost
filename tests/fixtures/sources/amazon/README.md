# Fixtures for `Outpost_Source_Amazon`

Offline fixtures for the F16 Amazon adapter's unit tests.

## Fixture inventory

| File | Captured | From URL | Sanitization |
|---|---|---|---|
| `og-product-success.html` | 2026-05-04 | synthetic | hand-authored Amazon product page with OG tags; ASIN placeholder `B00EXAMPLE` |

## Last verified live

Not applicable. F16 sticks to OG-only best-effort path. Amazon
aggressively blocks automated fetches; live-test patterns covering
bot-blocked vs successful responses are deferred.

## Sanitization checklist

- [x] No real ASINs (synthetic `B00EXAMPLE`)
- [x] No affiliate tags (the test fixture has none; the
      `strip_affiliate_params` test data uses synthetic
      `tag=affiliate-20` patterns inline in test code)
- [x] No real product titles or imagery URLs

## Notes specific to Amazon

- Multi-region host suffixes: `.com`, `.co.uk`, `.de`, `.ca`,
  `.com.au`, `.fr`, `.it`, `.es`, `.co.jp`. Apex, `www.`, and
  `smile.` subdomains all match.
- Path constraint: `/dp/{ASIN}` and `/gp/product/{ASIN}` only.
  Wishlist URLs (`/hz/wishlist/`), search URLs (`/s?...`),
  category URLs fall through.
- Affiliate tag stripping: a long list of known query parameters
  (`tag`, `linkCode`, `linkId`, `ref`, `ascsubtag`, `pd_rd_*`,
  `pf_rd_*`, etc.) are removed before the URL is recorded as
  `u-bookmark-of`. Tests assert this in
  `SourceAmazonTest::test_strip_affiliate_params_*` rather than
  needing dedicated fixtures.
