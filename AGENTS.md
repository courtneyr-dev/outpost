# Outpost — executor notes

You are implementing from a frozen spec in a codex-first split (see `~/AGENTS.md` § Execution role). Claude Code owns design decisions for this repo; the authoritative project record is [CLAUDE.md](CLAUDE.md) — read at least its Project, Hard Contract, Standards, and Security Hot Spots sections before changing code, plus any "Session … Locked Decisions" section named in your spec.

## Non-negotiables (from CLAUDE.md, condensed)

- **Hard Contract:** plugin owns layout, theme owns paint. Structural CSS references colors/fonts only through `var(--outpost-*)` — component CSS carries NO hex values, not even fallbacks (the B6 lint fails CI on any hex in `pwa/src/styles/structure.css`).
- WordPress PHP/JS coding standards: tabs, Yoda conditions, `outpost_`/`Outpost_`/`OUTPOST_` prefixes, text domain `outpost`, escape at render, `$wpdb->prepare()`, nonces + capability checks.
- TypeScript strict mode, no `any`. Kebab-case filenames (`class-*.php`, `*-mode.tsx`); adapter classes match `Outpost_*_Adapter`.
- §5 audit lint (`composer lint:section5`) rejects case-study handles, credentials, non-allowlisted instance hosts, untranslated adapter strings, and non-stock fixture names — run it before reporting done.
- Any rewrite-rule, query-var, or deployable behavior change requires bumping BOTH the plugin header `Version` and `OUTPOST_VERSION` in `outpost.php` (they sit two lines apart).
- Forbidden vocabulary in code/comments/copy: delve, leverage, synergy, robust, seamless (full list in CLAUDE.md).

## Commands

```bash
composer test        # PHPUnit
composer lint        # PHPCS (WordPress-Extra)
composer analyze     # PHPStan
composer lint:section5
npm run lint         # ESLint
npx vitest run       # PWA tests
npm run build        # PWA build to /build/pwa/
```

Run the exact test command your spec names; report real output.
