# WordPress.org listing assets (preflight)

Outpost is not yet listed on WordPress.org — the `outpost` slug is unclaimed as of 2026-07-09. This directory stages the directory assets so the listing can launch complete once the plugin is submitted and approved.

## What's here

- `screenshot-1.png` … `screenshot-5.png` — listing screenshots, copied from `docs/assets/screenshots/`. Their order and captions match the `== Screenshots ==` section of `readme.txt` exactly:
  1. `screenshot-1.png` — composer sign-in at `/post`, mobile viewport (from `frontend-composer-signin.png`)
  2. `screenshot-2.png` — Outpost admin page: bookmarklets and phone install steps (from `admin-outpost-bookmarklets.png`)
  3. `screenshot-3.png` — OAuth Connections provider list (from `admin-oauth-connections.png`)
  4. `screenshot-4.png` — Appearance settings with contrast adjustment (from `admin-appearance-settings.png`)
  5. `screenshot-5.png` — iOS Shortcut Bridge settings (from `admin-ios-shortcut.png`)
  6. `screenshot-6.png` — Note mode signed in, syndication chips open (from `frontend-composer-note-mode.png`)
  7. `screenshot-7.png` — offline banner with a queued draft (from `frontend-offline-queue.png`)
- `blueprints/blueprint.json` — Playground blueprint for the directory's Live Preview feature. See `docs/playground.md` for how to test it locally.

Screenshots regenerate with `npm run screenshots:docs` (see `scripts/capture-docs-screenshots.cjs`); re-copy from `docs/assets/screenshots/` after regenerating and keep the readme.txt captions in sync.

## Banner and icon

`banner-1544x500.png`, `banner-772x250.png`, `icon-256x256.png`, `icon-128x128.png`, and `icon.svg` come from the Outpost & Co design system ("WordPress Listing Assets v2" — trail-sign icons and park-postcard banners, one family across all four plugins). The design project is the source of truth; replace these files from there rather than editing the PNGs directly.

## How these map to SVN on deploy

The WordPress.org directory reads listing assets from the **top-level `assets/` directory of the plugin's SVN repository** (a sibling of `trunk/` and `tags/`, not shipped inside the plugin ZIP):

- `.wordpress-org/screenshot-*.png` → `assets/screenshot-*.png`
- `.wordpress-org/banner-*.png` → `assets/banner-*.png`
- `.wordpress-org/icon-*.png` / `icon.svg` → `assets/icon-*.png` / `assets/icon.svg`
- `.wordpress-org/blueprints/blueprint.json` → `assets/blueprints/blueprint.json` (enables the Live Preview button; toggle it in the plugin's admin area on WordPress.org after deploy)

Deploy tooling such as `10up/action-wordpress-plugin-asset-update` uses `.wordpress-org/` as its default source directory for exactly this mapping. Screenshot captions come from `readme.txt`, not from the image files, so caption edits happen there.
