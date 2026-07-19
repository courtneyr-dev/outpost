---
title: Playground preview
description: "Try Outpost on a disposable WordPress site in your browser with WordPress Playground — no server, hosting account, or installation required."
---

How to spin up a disposable WordPress site with Outpost preinstalled, using WordPress Playground — for trying the plugin, capturing screenshots, or (eventually) powering the WordPress.org Live Preview button.

## What the preview demonstrates

The blueprint boots a throwaway WordPress site, installs the required IndieAuth and Micropub plugins from WordPress.org, activates Outpost, logs you in as admin, and lands on the Outpost admin page (wp-admin → Outpost) — the bookmarklet generator with the composer link and phone install steps.

From there you can explore:

- The Outpost admin pages: bookmarklets, Outpost Settings, Appearance, OAuth Connections, and Settings → Outpost iOS Shortcut.
- The composer sign-in screen at `/post/` — the mobile-first PWA surface.

One limit to know about: a full posting flow needs an IndieAuth sign-in, and the disposable site can't complete one (IndieAuth verifies your own site's address, which a throwaway Playground URL isn't). The preview shows the admin surfaces and the sign-in screen, not an end-to-end post.

## Run it locally

From the repo root:

```bash
npx --yes @wp-playground/cli@latest server \
  --auto-mount /path/to/outpost \
  --blueprint .wordpress-org/blueprints/blueprint.json \
  --login --port 9412
```

Replace `/path/to/outpost` with your checkout path. `--auto-mount` mounts the checkout as the `outpost` plugin, and the blueprint's activate step turns it on. When the server reports it's ready, open `http://127.0.0.1:9412/wp-admin/admin.php?page=outpost`. Stop the server with Ctrl+C.

The plugin installs from WordPress.org during boot, so the machine needs network access.

## Blueprint files

- `.wordpress-org/blueprints/blueprint.json` — the demo blueprint described on this page. Destined for the WordPress.org directory's Live Preview (see below).
- `scripts/playground-blueprint.json` — a leaner blueprint used by the screenshot capture script (`npm run screenshots:docs`). It stays separate on purpose; don't merge the two.

## WordPress.org Live Preview (maintainer actions — preflight)

Outpost is not yet listed on WordPress.org, so everything here waits until the plugin is submitted and approved. Once it is:

1. Deploy the blueprint to the plugin's SVN repository at `assets/blueprints/blueprint.json` (the top-level `assets/` directory, alongside screenshots and banners — not inside `trunk/`).
2. In the plugin's admin area on WordPress.org, enable the Live Preview button.
3. Verify the preview boots from the directory page — in that context the directory provides the Outpost plugin itself, and the blueprint's install steps cover the IndieAuth and Micropub dependencies.
