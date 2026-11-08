---
title: Installation
description: "Install Outpost from a GitHub release ZIP, activate the required IndieAuth and Micropub plugins, and confirm the /post composer loads on your site."
---

How to install Outpost from GitHub and get its required plugins in place. Outpost isn't in the WordPress.org plugin directory yet, so you install it from its GitHub repository.

## Before you start

- WordPress 6.5 or newer and PHP 8.2 or newer. If your site doesn't meet these, Outpost shows an admin error notice and keeps its features off.
- Administrator access to your site (`wp-admin`).

## Step 1: Install the required plugins

Outpost needs two plugins from the WordPress.org directory, activated in this order:

1. **IndieAuth** — go to **Plugins** → **Add New Plugin**, search for "IndieAuth" (by Matthias Pfefferle and David Shanske), install, and activate. This is the sign-in layer; Micropub requires it.
2. **Micropub** — same screen, search for "Micropub" (by David Shanske), install, and activate. This is the publishing endpoint Outpost posts through.

If you skip either one, Outpost stays inert: administrators see a notice such as "Outpost needs the IndieAuth plugin" with an install or activate link, and visiting `/post` shows a setup prompt naming the missing plugin instead of the composer. That notice appears on the Plugins and Add Plugins screens and on Outpost's own pages — the places you would fix it — rather than following you around the rest of wp-admin.

## Step 2: Install Outpost from GitHub

The repository's `main` branch holds the current release, version 1.0.24, with its compiled files (`build/`) committed. You don't need Node, npm, or a build step: a clone or ZIP of `main` is ready to activate.

The repository's Releases page hasn't been updated for the 1.0 series; its newest entry is v0.2.0 from May 2026. Install from `main` as shown below, not from that release.

Pick one folder name and keep it. WordPress identifies a plugin by its folder, so a later update only replaces this install if the folder name matches. These steps use `outpost-mobile-publishing`, the plugin's slug.

### Option A: Clone with git

Use this if you have shell access to your server. Updating later is one `git pull`.

```bash
cd wp-content/plugins
git clone https://github.com/courtneyr-dev/outpost.git outpost-mobile-publishing
```

### Option B: Upload a ZIP

1. On the [Outpost repository page](https://github.com/courtneyr-dev/outpost), choose **Code** → **Download ZIP**. You get `outpost-main.zip`, which unpacks to a folder named `outpost-main`.
2. Unzip it, rename the folder to `outpost-mobile-publishing`, and zip that folder again.
3. In wp-admin, go to **Plugins** → **Add New Plugin** → **Upload Plugin**.
4. Choose your ZIP and select **Install Now**.

If you skip the rename, the plugin still works from `outpost-main`. Keep using that name for every later update.

## Step 3: Activate

Go to Plugins in wp-admin and click Activate on Outpost. Activation registers the `/post` rewrite rules so the composer URL works immediately.

## Confirm it's working

1. In wp-admin, look for the **Outpost** menu in the left sidebar. Open it — the page links to your composer at `/post/` and offers bookmarklets and phone-install instructions.
2. Visit `https://your-site.example/post/` in a browser. With IndieAuth and Micropub active, you get the Outpost composer. If a dependency is missing you get a setup page naming what to install — fix that and reload.

If `/post` returns a 404, deactivate and reactivate the plugin (activation re-registers the rewrite rules), or re-save your permalinks at Settings → Permalinks.

## Next step

Head to [Getting started](/outpost/getting-started/) to sign in and post your first note. When a new version comes out, follow [Update Outpost](/outpost/update/).
