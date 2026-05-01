import { defineConfig } from 'vitest/config';
import preact from '@preact/preset-vite';

/**
 * Vitest config for PWA unit tests.
 *
 * happy-dom gives us DOMParser, fetch shape, and a basic window object — all
 * we need for the IndieAuth discovery parser and the route-detection logic.
 * IndexedDB is NOT in happy-dom; the token-store tests bring their own
 * `fake-indexeddb/auto` import to polyfill it.
 *
 * crypto.subtle comes from Node 19+'s native webcrypto — available globally
 * during vitest runs without any extra setup.
 */
export default defineConfig({
	plugins: [preact()],
	test: {
		environment: 'happy-dom',
		globals: false,
		include: ['pwa/src/**/*.{test,spec}.{ts,tsx}'],
		coverage: {
			provider: 'v8',
			include: ['pwa/src/**/*.{ts,tsx}'],
			exclude: ['pwa/src/**/*.test.{ts,tsx}', 'pwa/src/**/*.spec.{ts,tsx}'],
		},
	},
});
