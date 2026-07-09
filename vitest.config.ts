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
 *
 * Node 22+ ships its own `globalThis.localStorage` (default-on in newer
 * versions), which is a non-functional stub unless --localstorage-file is
 * set. Vitest's populateGlobal keeps pre-existing globals, so the stub
 * shadows happy-dom's working Storage and localStorage tests fail with
 * "clear is not a function". When we detect the stub, disable Node's
 * webstorage in the test workers so happy-dom's Storage wins. On Node 20
 * (CI) localStorage doesn't exist at the Node level, the detection is
 * false, and the flag — which Node 20 would reject — is never passed.
 */
const node_local_storage = (
	globalThis as { localStorage?: { getItem?: unknown } }
).localStorage;
const node_webstorage_stub =
	node_local_storage !== undefined &&
	typeof node_local_storage.getItem !== 'function';
const execArgv = node_webstorage_stub ? ['--no-experimental-webstorage'] : [];

export default defineConfig({
	plugins: [preact()],
	test: {
		environment: 'happy-dom',
		poolOptions: {
			forks: { execArgv },
			threads: { execArgv },
		},
		globals: false,
		include: ['pwa/src/**/*.{test,spec}.{ts,tsx}'],
		coverage: {
			provider: 'v8',
			include: ['pwa/src/**/*.{ts,tsx}'],
			exclude: ['pwa/src/**/*.test.{ts,tsx}', 'pwa/src/**/*.spec.{ts,tsx}'],
		},
	},
});
