---
title: Screenshots
description: "Gallery of Outpost's documented screens, plus capture specifications for the screenshots the documentation still needs."
---

The screens Outpost adds to WordPress, captured from a fresh install. Every screenshot has a text equivalent in the page that documents the task, so you never need the image to follow the instructions.

Screenshots come from two sources. The repeatable capture script (`npm run screenshots:docs`, which runs against a disposable WordPress Playground — no Docker needed) generates the admin screens and the composer views, completing a real IndieAuth sign-in against the disposable site for the signed-in captures. The few that remain need a manual capture because they require connected providers or a real phone; their specifications are listed at the end of this page.

## Admin screens

![Outpost admin page with per-variant bookmarklets and phone install steps](../../assets/screenshots/admin-outpost-bookmarklets.png)

The main **Outpost** admin page: per-variant bookmarklets and the phone install steps. The dependency notice at the top appears when IndieAuth or Micropub isn't active yet. See [Common tasks → Add the bookmarklets](/outpost/common-tasks/).

![Outpost Settings page showing the API Keys tab with credential fields](../../assets/screenshots/admin-settings-api-keys.png)

**Outpost Settings → API Keys**: where syndication destination credentials go. Saved values are stored encrypted. See [Settings](/outpost/settings/).

![Outpost Appearance page with the day/night mode choice and token override fields](../../assets/screenshots/admin-appearance-settings.png)

**Outpost → Appearance**: choose the composer's day/night mode and override its design tokens. See [Settings](/outpost/settings/).

![OAuth Connections page listing providers with Connect actions](../../assets/screenshots/admin-oauth-connections.png)

**Outpost → OAuth Connections**: connect Oura, WHOOP, Polar Flow, Ravelry, or Ride With GPS. Select **Connect** next to a provider to start its authorization flow. See [Supported services](/outpost/supported-services/).

![Outpost iOS Shortcut settings page with the per-user token controls](../../assets/screenshots/admin-ios-shortcut.png)

**Settings → Outpost iOS Shortcut**: generate the per-user token that lets an iOS Shortcut post to your site. See [Common tasks](/outpost/common-tasks/).

![Composer defaults form with Default Post variant, Bridgy auto-suggest, and Auto Post-Format inference fields](../../assets/screenshots/admin-settings-composer-defaults.png)

**Outpost admin page, Settings section**: set which mode the composer opens to by default. See [Settings](/outpost/settings/).

![Posts list showing the Outpost syndication status column](../../assets/screenshots/admin-syndication-column.png)

**wp-admin → Posts** with the Outpost syndication status column: confirm where each post syndicated at a glance. See [Common tasks](/outpost/common-tasks/).

![Admin notice saying Outpost needs a required plugin, with an install link](../../assets/screenshots/admin-dependency-notice.png)

The dependency notice when Micropub is deactivated: Outpost names the missing plugin and links the fix. See [Installation](/outpost/installation/).

## Composer

![Outpost composer sign-in screen asking for your site address](../../assets/screenshots/frontend-composer-signin.png)

The composer at `/post` before sign-in: enter your site address to start the IndieAuth flow. See [Getting started](/outpost/getting-started/).

![Outpost composer on a phone showing Note mode with a text field and syndication chips](../../assets/screenshots/frontend-composer-note-mode.png)

Note mode, signed in: write a note and pick syndication targets before posting. See [Common tasks](/outpost/common-tasks/).

![Reply mode showing the pasted target URL context and syndication chips](../../assets/screenshots/frontend-composer-reply-mode.png)

Reply mode: paste a URL and Outpost shows what you're replying to. See [Common tasks](/outpost/common-tasks/).

![Photo mode with an uploaded image and the required alt text field](../../assets/screenshots/frontend-composer-photo-mode.png)

Photo mode: every photo post asks for alt text before publishing. See [Common tasks](/outpost/common-tasks/).

![Composer showing the offline connection banner and a queued draft badge](../../assets/screenshots/frontend-offline-queue.png)

The composer offline: posts made offline wait in the queue until you reconnect. See [Common tasks](/outpost/common-tasks/).

## Screenshots still needed

These captures require connected providers, a real phone, or UI that hasn't shipped yet. Each row is the full specification for capturing it (viewport 1280×800 at 2x for admin screens, a phone-sized viewport for composer screens).

| Filename | Screen and state | What to highlight | Alt text | Caption |
| --- | --- | --- | --- | --- |
| frontend-composer-media-lookup.png | Composer, Watch/Listen mode, "Look it up" results shown (needs Post Kinds + API keys) | Search results filling title/creator/art | Media lookup search results filling title, creator, and cover art | "Look it up" fills media details so you don't type them. |
| frontend-pwa-install-prompt.png | iOS Safari share sheet or Android install banner on `/post` | The Add to Home Screen action | Phone browser showing the Add to Home Screen action for the Outpost composer | Install the composer like an app from your browser's menu. |
| admin-pending-syndication-capture.png | Pending-syndication reminder flow | The paste-URL field | Prompt asking whether the post was shared, with a field to paste the URL | Record the URL after sharing manually to a platform. |
| editor-sidebar-fetch-recent.png | Block editor with the Outpost sidebar open (provider connected) | The fetch-recent picker | Block editor with the Outpost sidebar showing the fetch-recent picker | Pull a recent workout or sleep into the post you're writing. |

Why these stay manual: the media lookup fetches from live provider APIs the disposable Playground can't reach; the install prompt only renders in a real phone browser; the fetch-recent picker needs a real OAuth provider connection; and the pending-syndication capture form isn't yet mounted in the composer, so there's no running UI to photograph.
