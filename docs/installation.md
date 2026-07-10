# Installation

How to install Outpost from GitHub and get its required plugins in place. Outpost is not confirmed to be in the WordPress.org directory, so installation is from the GitHub repository.

## Before you start

- WordPress 6.5 or newer and PHP 8.2 or newer. If your site doesn't meet these, Outpost shows an admin error notice and keeps its features off.
- Administrator access to your site (`wp-admin`).

## Step 1: Install the required plugins

Outpost needs two plugins from the WordPress.org directory, activated in this order:

1. **IndieAuth** — go to Plugins → Add New, search for "IndieAuth" (by Matthias Pfefferle and David Shanske), install, and activate. This is the sign-in layer; Micropub requires it.
2. **Micropub** — same screen, search for "Micropub" (by David Shanske), install, and activate. This is the publishing endpoint Outpost posts through.

If you skip either one, Outpost stays inert: administrators see a notice such as "Outpost needs the IndieAuth plugin" with an install or activate link, and visiting `/post` shows a setup prompt naming the missing plugin instead of the composer.

## Step 2: Install Outpost from GitHub

The repository commits its compiled PWA bundle (`build/pwa/`), so you do not need Node, npm, or any build step — a plain clone or ZIP of the repo is ready to activate.

### Option A: Clone with git

```bash
cd wp-content/plugins
git clone https://github.com/courtneyr-dev/outpost.git
```

### Option B: Upload a ZIP

1. On the GitHub repository page, choose Code → Download ZIP (or download a release ZIP from the Releases page if one is available).
2. In wp-admin, go to Plugins → Add New → Upload Plugin.
3. Choose the ZIP and click Install Now.

Note: GitHub's "Download ZIP" produces a folder named `outpost-main`. The plugin works from that folder, but if you later switch to a git clone or release ZIP the folder name will differ and WordPress will treat it as a separate plugin. Renaming the folder to `outpost` before uploading keeps things tidy.

## Step 3: Activate

Go to Plugins in wp-admin and click Activate on Outpost. Activation registers the `/post` rewrite rules so the composer URL works immediately.

## Confirm it's working

1. In wp-admin, look for the **Outpost** menu in the left sidebar. Open it — the page links to your composer at `/post/` and offers bookmarklets and phone-install instructions.
2. Visit `https://your-site.example/post/` in a browser. With IndieAuth and Micropub active, you get the Outpost composer. If a dependency is missing you get a setup page naming what to install — fix that and reload.

If `/post` returns a 404, deactivate and reactivate the plugin (activation re-registers the rewrite rules), or re-save your permalinks at Settings → Permalinks.

## Next step

Head to [Getting started](getting-started.md) to sign in and post your first note.

---

[Documentation home](index.md) · Previous: [Home](index.md) · Next: [Getting started](getting-started.md)
