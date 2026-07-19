---
title: Supported services
description: "Every service Outpost integrates with — URL capture sources, life-tracking connections, and POSSE syndication destinations — in one reference."
---

Outpost integrates with external services in three directions: it can **capture** content when you paste a URL, **pull recent activity** from connected life-tracking accounts, and **syndicate** your posts out to publishing platforms. This page lists every supported service. Each service links to its detailed adapter note in the GitHub repository, which records exactly what is fetched and posted.

Connect and configure services under Outpost's settings — see [Settings](/outpost/settings/) for where each connection lives, and [Common tasks](/outpost/common-tasks/) for the day-to-day workflows.

## Capture from a pasted URL

Paste a link from one of these services into the composer and Outpost recognizes it, fetches the page's metadata, and suggests a post type:

| Service | What pasting a URL does |
| --- | --- |
| [Bear Blog](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/bear-blog.md) | Suggests a Read post for the pasted article |
| [Mataroa](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/mataroa.md) | Suggests a Read post for the pasted article |
| [iFixit](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/ifixit.md) | Suggests a Bookmark for the repair guide |
| [Sefaria](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/sefaria.md) | Suggests a Quote post with the referenced Jewish text |
| [SuttaCentral](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/suttacentral.md) | Suggests a Quote post with the referenced Buddhist text |
| [Snipd](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/snipd.md) | Captures podcast snips from Snipd share links |
| [Pretalx](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/pretalx.md) | Captures conference talk details from Pretalx pages |
| [Ravelry](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/ravelry-source.md) | Captures knit/crochet pattern and project metadata (requires a connected Ravelry account) |
| [Ride With GPS](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/ridewithgps-source.md) | Captures trip and route details (requires a connected Ride With GPS account) |

Many other hosts (Spotify, YouTube, Goodreads, Mastodon, Bluesky, and about 30 more) get generic metadata capture without a dedicated adapter — [Privacy and data](/outpost/privacy-and-data/) lists them.

## Pull recent activity from life-tracking services

Connect these accounts under Outpost's OAuth connections, then pick a recent workout, sleep, or activity in the composer to log it as a post:

| Service | What you can log |
| --- | --- |
| [Oura](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/oura.md) | Recent workouts and sleep sessions from your Oura Ring |
| [Polar Flow](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/polar.md) | Recent training sessions and sleep from Polar devices |
| [WHOOP](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/whoop.md) | Recent cycles, workouts, and recovery from WHOOP |

Connecting a life-tracking service pulls health and activity data into your WordPress site — read [Privacy and data](/outpost/privacy-and-data/) before connecting.

## Syndicate posts to publishing platforms

With an API key configured, these destinations receive a copy of your WordPress post when you publish (POSSE):

| Destination | What gets published |
| --- | --- |
| [Beehiiv](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/beehiiv.md) | A copy of the post in your Beehiiv publication |
| [Buttondown](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/buttondown.md) | A copy of the post as a Buttondown email |
| [Kit](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/kit.md) | A copy of the post as a Kit (formerly ConvertKit) broadcast |
| [write.as](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/write-as.md) | A copy of the post as a write.as Markdown post |
| [Telegraph](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/telegraph.md) | A copy of the post as a Telegraph page |

Social-network syndication (Mastodon, Bluesky, and other silos) goes through [Bridgy](https://brid.gy/) rather than per-service adapters — the composer's syndication chips control it.

## Other integrations

| Service | What it does |
| --- | --- |
| [Notion](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/notion.md) | Reads shared Notion pages into the composer (inbound only) |

## Expected result

After connecting or configuring a service, its chip or picker appears in the composer. If a service you configured doesn't show up, [Troubleshooting](/outpost/troubleshooting/) covers the common causes.
