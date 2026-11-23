import type { Decorator, Preview } from '@storybook/preact-vite';
import '../pwa/src/styles/structure.css';
import { WCAG_TAGS } from './a11y-rules.js';

// The PHP shell prints these classes on <body>; tokens.css resolves the
// Outpost Default palette from them.
const SCHEME_CLASS = { light: 'outpost-mode-day', dark: 'outpost-mode-night' } as const;
type Scheme = keyof typeof SCHEME_CLASS;

const withShell: Decorator = (Story, { globals, viewMode }) => {
	const scheme: Scheme = globals['scheme'] === 'dark' ? 'dark' : 'light';
	document.body.className = `outpost-composer-shell ${SCHEME_CLASS[scheme]} outpost-source-outpost`;
	return (
		<div class="outpost-app" style={viewMode === 'docs' ? { minHeight: 'auto' } : undefined}>
			<Story />
		</div>
	);
};

const OUTPOST_DATABASES = ['outpost', 'outpost-queue', 'outpost-share-inbox'];

const clearOutpostState = (): void => {
	for (const storage of [localStorage, sessionStorage]) {
		Object.keys(storage)
			.filter((key) => key.startsWith('outpost'))
			.forEach((key) => storage.removeItem(key));
	}
	OUTPOST_DATABASES.forEach((name) => indexedDB.deleteDatabase(name));
};

const preview: Preview = {
	tags: ['autodocs'],
	decorators: [withShell],
	beforeEach: clearOutpostState,
	globalTypes: {
		scheme: {
			description: 'Outpost Default color scheme',
			toolbar: {
				title: 'Scheme',
				icon: 'mirror',
				items: [
					{ value: 'light', title: 'Light (day)' },
					{ value: 'dark', title: 'Dark (night)' },
				],
				dynamicTitle: true,
			},
		},
	},
	initialGlobals: {
		scheme: 'light',
		viewport: { value: 'phone', isRotated: false },
	},
	parameters: {
		layout: 'fullscreen',
		viewport: {
			options: {
				phone: { name: 'Phone (390 px)', styles: { width: '390px', height: '844px' }, type: 'mobile' },
				desktop: { name: 'Desktop (1280 px)', styles: { width: '1280px', height: '800px' }, type: 'desktop' },
			},
		},
		a11y: {
			test: 'error',
			options: { runOnly: { type: 'tag', values: WCAG_TAGS } },
		},
	},
};

export default preview;
