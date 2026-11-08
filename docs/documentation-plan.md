# Documentation plan (superseded)

This file is a planning record from before the user docs site moved to Astro Starlight. It's kept for history only; don't follow it.

## What replaced it

- The published user docs live in `docs/src/content/docs/` and build with Astro Starlight to <https://courtneyr-dev.github.io/outpost/>.
- [MAINTAINING.md](MAINTAINING.md) is the current guide for editing, previewing, validating, and publishing those pages.
- The Documentation checklist in `.github/PULL_REQUEST_TEMPLATE.md` lists what each pull request that changes user-facing behavior must update.

## What the plan covered

Written when the plugin header said version 1.0.0, the plan scoped a ten-page user set for site owners (index, installation, getting-started, settings, common-tasks, screenshots, troubleshooting, faq, privacy-and-data, accessibility), an inventory of 17 screenshots with a repeatable capture script (`npm run screenshots:docs`), and hosting as plain Markdown on GitHub Pages through a Jekyll `_config.yml`. Starlight replaced the Jekyll setup, and MAINTAINING.md warns against re-enabling it because it published every Markdown file in `docs/`, internal notes included. The plan also listed 13 questions for the maintainer, among them the two top-level "Outpost" admin menus, whether the `/post` route slug is configurable, whether queued offline drafts are encrypted, and where EXIF stripping happens. Check the current code and the published pages before relying on any answer the plan assumed.
