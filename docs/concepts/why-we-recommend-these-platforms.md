# Why Outpost recommends these platforms

Outpost is a WordPress plugin for IndieWeb POSSE workflows — Publish on your Own Site, Syndicate Elsewhere. The "Own Site" half is by definition independent and self-controlled. The "Syndicate Elsewhere" half forces a values question every time we add a platform adapter: *which platforms get first-class treatment, and on what grounds?*

This page is the answer. It's a recommendation list, not an integration list: many platforms named here have no Outpost code. Where Outpost does have an adapter, this page names the file.

## The principle

**Open-source and independently sustainable platforms get first-class adapters. Closed platforms get adapters when there's no open alternative AND a meaningful user base depends on them.**

We don't refuse closed platforms — most IndieWeb users still need Mastodon-via-X-mirror or YouTube embeds for reach. But when an open alternative exists and serves the same use case, the docs name it explicitly. The recommendation isn't "stop using the closed thing"; it's "if you're choosing where to invest, here's where ownership compounds."

## Why this matters for IndieWeb users specifically

Three patterns shape Outpost's recommendations, drawn from May 2026 industry research. The research catalog (`wiki/concepts/posse-expansion-may-2026.md`) isn't in this repository.

### Pattern 1: open source > VC-owned for sustainability

Adapters built against VC-owned platforms have a 2-3 year half-life. The arc is consistent:

- **Independent platform** with a public API → community grows → users build integrations
- **Acquisition** — typically by a strategic acquirer rather than a financial one
- **API hostility** — pricing changes, terms-of-service tightening, rate-limit reductions
- **Death or paywall** — either the platform shuts down, or the API becomes commercial-only

Recent examples (all confirmed via web research May 2026):

| Platform | What happened | When |
|---|---|---|
| **RateBeer** | Shut down by AB InBev / ZX Ventures | February 1, 2025 |
| **Mountain Project** | API deprecated by onX; no new keys granted | Late 2020 |
| **Yelp Fusion** | Free tier killed; now $7.99-$14.99 per 1000 calls | Mid 2024 |
| **Foursquare / Swarm** | Public API shut down | 2024-2025 |
| **MapMyRide** (Under Armour) | API closed to non-partners | 2024 |
| **MyFitnessPal** | API closed to new developers | ~2020 |

Open-source platforms (Pretix, Pretalx, Mobilizon, Ghost, Listmonk, BookWyrm, Manyfold, OpenBeta, Brewver) don't follow that arc: the platform can't be sold to someone hostile, the API can't move behind an enterprise paywall, and a self-hosted copy keeps the data. Of these nine, only Pretalx has an Outpost adapter (`includes/sources/class-source-pretalx.php`); the other eight have no Outpost code.

### Pattern 2: "membership-gated API" is the new normal for closed platforms

A second pattern: platforms keep public-looking APIs but require an active paid membership to access the user's own data.

- **Oura V2** requires active Oura Membership for ring data on Gen3 + Ring 4 (Outpost has an Oura OAuth provider and fetch-recent picker)
- **Meetup API** requires Pro subscription (no Outpost code)
- **Apple Music** requires MusicKit + Apple Developer Program (Outpost's `Outpost_Source_AppleMusic` avoids the API and reads Open Graph tags from share pages)
- **WHOOP** requires app approval before launch (Outpost has a WHOOP OAuth provider and fetch-recent picker)

This isn't technical gating — it's commercial gating dressed up as API design. The user is paying for the underlying service anyway, so the friction is moderate. But it does mean the adapter assumes a paying user; free-tier users are de-facto excluded.

### Pattern 3: "no public API but solid OG/JSON-LD" is a complete category

Most fitness platforms (Komoot, Wikiloc, Gaia GPS, Suunto, Peloton, Zwift) and most food/drink rating sites (Vivino, BeerAdvocate, Distiller, Whiskybase, Vinous, OpenTable, Resy, Tock, The Infatuation, Beanhunter, Steepster, Coffee Review) have no API but expose canonical URLs with rich Open Graph + sometimes JSON-LD Schema.org markup.

None of the platforms in that list has a named Outpost source. A URL from any of them falls to `Outpost_Source_Unknown` ("Generic URL", `includes/sources/class-source-unknown.php`), which reads Open Graph tags with the `og_tags` extractor and asks you to pick Reply, Like, Repost, or Bookmark. The named OG sources in `includes/sources/` (Amazon, Bear Blog, Goodreads, Mataroa, Medium, and others) use the same extractor with a fixed mode.

The G4 `Outpost_Og_Inbound` primitive and its category extractors (Recipe, Event, Article, Book, Restaurant) are in the primitive library so the work can be shared across every platform that emits the same Schema.org type. `outpost.php` registers the five extractors, but no shipped source calls `Outpost_Og_Inbound::fetch()` yet; see [Adapter primitives](../adapters/_primitives.md).

## Recommended pairings

When an open alternative exists, here's where Outpost docs point users. Outpost code exists for Pretalx, Buttondown (POSSE destination), Bear Blog, Mataroa, Mastodon, Bluesky, Goodreads, and Substack (inbound sources), and OpenStreetMap (location picker). The other platforms in this table have no Outpost code.

| Closed-source / VC-owned | Open / sustainable alternative | Rationale |
|---|---|---|
| RateBeer | **Brewver** | RateBeer shut down Feb 2025 by AB InBev/ZX Ventures; Brewver is the community-led successor accepting CSV import from RateBeer exports |
| Mountain Project | **OpenBeta** | MP API deprecated 2020 by onX; OpenBeta is CC0 open data, GraphQL API |
| Eventbrite | **Mobilizon** (community events), **Pretix** (ticketed events) | Mobilizon is ActivityPub-native (Framasoft, AGPL); Pretix is open-source self-hostable |
| Sessionize | **Pretalx** | Pretalx is sister project to Pretix; same open-source maintainer base; v2026.1 current |
| Mailchimp | **Listmonk** (self-hosted), **Buttondown** (free hosted) | Listmonk is Zerodha's open-source mailing-list manager; Buttondown is a small founder-run platform with free-tier API access on all plans |
| Thingiverse | **Manyfold** (self-hosted) | Manyfold gives users full library control vs. depending on MakerBot's hosting |
| Substack | **Bear Blog** (hosted), **Mataroa** (self-hostable), **Ghost** (hosted or self-hosted) | All three respect IndieWeb conventions and have RSS-first feeds |
| Twitter/X | **Mastodon** (federated), **Bluesky** (AT Protocol) | Both are open and federated; Outpost has inbound sources for both (`class-source-mastodon.php`, `class-source-bluesky.php`) |
| Google Maps share / Apple Maps | **OpenStreetMap** + Nominatim | Outpost's location picker already uses Nominatim (`includes/class-geocode-endpoint.php`, `pwa/src/components/geocode-picker.tsx`) |
| Goodreads | **BookWyrm** (federated) | Goodreads' public API was killed Dec 2020; BookWyrm is the federated alternative |
| Pocket (retired July 2025) | **Wallabag** (self-hosted) | Wallabag is an open-source read-it-later replacement |
| Cohost (closed Sep 2024) | **Bear Blog**, **Mataroa**, **Mastodon** | No direct one-to-one replacement; community largely scattered to fediverse + small blogs |

## Where there is no recommended alternative

Some platforms have no open replacement that serves the same user base:

- **Yelp** for restaurant reviews. The network effect of crowd-sourced reviews is unmatched, and no open alternative exists at scale. Outpost has no Yelp source; a Yelp URL falls to the Generic URL source, which reads its Open Graph tags and asks you to pick a mode.
- **YouVersion / Bible.com** for Bible reading. api.bible (American Bible Society) is closed-source but offers 2500+ translations under open partnership terms, and no community alternative matches that breadth. Outpost has no scripture or api.bible integration.
- **Apple Music / Apple Podcasts** for music + podcast catalogs. Apple controls the canonical metadata for most catalog content; no open replacement.
- **Strava** for fitness tracking. Strava has a public API, isn't VC-owned in the hostile sense, and the network of users is hard to replicate. Outpost has no Strava integration; a Strava URL falls to the Generic URL source.

## How this shows up in adapter docs

Adapter docs that have a recommended alternative can include a "See also" footer linking back to this page. Today only [Pretalx](../adapters/pretalx.md) links here. Example:

> ## See also: open-source alternatives
>
> If you're choosing where to invest your writing time, see Outpost's [why we recommend these platforms](../concepts/why-we-recommend-these-platforms.md) for why we suggest Bear Blog, Mataroa, or Ghost over Substack for new self-publishers.

The closed-platform adapters work the same way they would in any plugin. The recommendation is informational, not blocking.

## Catalog reference

Source material for this page: `wiki/concepts/posse-expansion-may-2026.md` §0 (cross-cutting findings) and §9 (tier consolidation table), a research catalog that isn't in this repository. Phase G decision 3 from the May 5, 2026 locking session: "Open-source alternatives documented as normative recommendations: YES."

## Tone

Principled, not preachy. The recommendations are observations about platform sustainability and ownership compounding — not moral judgments about users who pick the convenient platform. People choose closed platforms for valid reasons (network effect, polish, focus). Outpost makes the IndieWeb-and-open path easier; it doesn't make the other path harder.
