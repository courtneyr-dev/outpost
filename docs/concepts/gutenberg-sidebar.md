# Gutenberg sidebar (G3.5c)

Outpost ships a single block-editor `PluginSidebar` that future Outpost surfaces hang panels off. The first scaffolding ships in G3.5c with a placeholder card; real components — the POSSE destination selector (G5), the fetch-recent picker (next batch), headless-send config (G7) — land in follow-up PRs as additional `<PanelBody>` sections inside this same sidebar.

## Components

- **`src/index.js`** — entry point. Calls `registerPlugin( 'outpost-sidebar', { render, icon } )`. Imports the @wordpress/icons `share` icon (already bundled, no extra dep).
- **`src/sidebar/outpost-sidebar.js`** — the `<PluginSidebar>` component. Renders a `<PluginSidebarMoreMenuItem>` (the entry in the editor's More-menu dropdown) plus the sidebar itself with one `<PanelBody>` containing the placeholder card.
- **`includes/class-outpost-sidebar-assets.php`** — PHP enqueue. Hooks `enqueue_block_editor_assets`, reads `build/index.asset.php` for dependencies + version, calls `wp_enqueue_script`. Skips with a debug-log warning when the build artifact is missing (dev environment without a local `npm run build:wp`).

## Build pipeline

@wordpress/scripts is the build tool — industry standard for WP block plugin development. It wraps webpack with WP-specific config so we don't roll our own.

| Command | Effect |
|---------|--------|
| `npm install` | Install all JS deps including @wordpress/scripts. |
| `npm run build:wp` | Production build. Emits `build/index.js`, `build/index.asset.php`, `build/index.css`. |
| `npm run start:wp` | Watch mode with HMR. Emits dev assets. |
| `npm run lint:wp` | ESLint via @wordpress/scripts config. |
| `npm run format:wp` | Prettier via @wordpress/scripts config. |
| `npm run test:wp:unit` | Jest + react-testing-library. |

The Vite/Preact PWA pipeline (`pwa/src/`) and the @wordpress/scripts pipeline (`src/`) coexist in the same repo. Each owns its own source tree and build output (`build/pwa/` for the PWA, `build/index.*` for the sidebar). The two surface areas don't share code — the PWA is a standalone Preact app at `/post/`; the sidebar is React inside the WP block editor.

## Build artifacts are committed

`build/index.js` + `build/index.asset.php` (+ optional `build/index.css`) ship in version control alongside `build/pwa/`. Same reason: the `gd-wordpress-deployer` rsync reflects the working tree, so production-shaped staging tests need the bundle on disk. CI doesn't build — it lints and tests, and the developer runs `npm run build:wp` locally before committing.

The `.gitignore` re-includes the relevant `build/index.*` paths to allow the artifacts through despite the broader `build/*` ignore. See `.gitignore` for the full list.

## Adding a new sidebar component

1. Add a new file under `src/sidebar/` (kebab-case filename, PascalCase export). Example: `src/sidebar/posse-destinations.js` exporting `PosseDestinations`.
2. Import it into `outpost-sidebar.js` and render it as a new `<PanelBody>` section inside the existing `<PluginSidebar>`. Each panel gets its own title.
3. Add a smoke test under `src/sidebar/__tests__/` mirroring the existing test (render, assert key text, done).
4. Wire any post-meta the panel reads/writes through the `core/editor` data store via `useSelect` and `useDispatch` — server-side, the meta key needs `register_post_meta` with `show_in_rest: true` (the G3.5b POSSE meta keys are the canonical example).
5. Run `npm run build:wp` to refresh `build/`. Commit the source AND the rebuilt artifacts in the same commit.

## What this PR is NOT

- The fetch-recent picker (separate PR; stacks on this branch).
- The POSSE destination selector (G5).
- Headless-send UI (G7).
- Persistence of sidebar state to post-meta (will surface as we add real components).
- Storybook / component playground (deferred indefinitely; not worth the maintenance cost for an indie plugin).
- Bundling third-party UI libraries beyond what `@wordpress/components` provides.

## Why JavaScript, not TypeScript

Outpost is a single-developer indie project. TS adds tooling overhead with limited return at this scale. F-phase has no TS. JSDoc comments serve where type hints help. If TS becomes warranted later, @wordpress/scripts supports it via a single config flag — the migration is mechanical.
