import { defineConfig } from 'vite';
import preact from '@preact/preset-vite';
import { resolve } from 'node:path';

/**
 * Vite config for the Outpost PWA bundle.
 *
 * The PHP shell (`Outpost_PWA_Shell::render_shell()`) loads the entry script;
 * we run Vite in **manifest mode** so the shell can read `build/pwa/.vite/manifest.json`
 * and resolve the cache-busted filename without hard-coding hashes.
 *
 * Source lives at `pwa/src/`; build output goes to `build/pwa/` (per CLAUDE.md
 * File Layout). Vite's `root` stays at the project root so tests, configs, and
 * the build command share one working directory.
 */
export default defineConfig({
	root: __dirname,
	plugins: [preact()],
	build: {
		outDir: 'build/pwa',
		emptyOutDir: true,
		manifest: true,
		rollupOptions: {
			input: {
				index: resolve(__dirname, 'pwa/src/index.tsx'),
			},
			output: {
				entryFileNames: 'assets/[name]-[hash].js',
				chunkFileNames: 'assets/[name]-[hash].js',
				assetFileNames: 'assets/[name]-[hash][extname]',
			},
		},
		// Modern target — iOS 16.4+ for Web Share Target, plus the PWA install
		// flow on Android Chrome 113+. Both support ES2022 natively.
		target: 'es2022',
		sourcemap: true,
	},
	server: {
		port: 5173,
		strictPort: true,
	},
	resolve: {
		alias: {
			// Preact compat is unused (we author against the Preact API directly),
			// but the alias keeps any dependency that imports `react` working.
			react: 'preact/compat',
			'react-dom': 'preact/compat',
		},
	},
});
