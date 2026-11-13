import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render } from 'preact';
import { PhotoMode } from './photo-mode';
import type { ComposerConfig } from '../../lib/composer-config';
import type { StoredToken } from '../../lib/token-store';

vi.mock('../../lib/photo', async (import_original) => {
	const actual = await import_original<typeof import('../../lib/photo')>();
	return {
		...actual,
		process_photo: vi
			.fn()
			.mockRejectedValue(new actual.PhotoError('Test photo processing failed.', 'load_failed')),
	};
});

const token: StoredToken = {
	accessToken: 'test-token',
	tokenType: 'Bearer',
	scope: 'create',
	me: 'https://example.test/',
	storedAt: 0,
};

const composer_config: ComposerConfig = {
	companions: {
		'post-kinds': 'absent',
		'post-formats': 'absent',
		xfn: 'absent',
		'syndication-links': 'absent',
		yoast: 'absent',
		activitypub: 'absent',
		'accessibility-checker': 'absent',
		'rss-chat-routing': 'absent',
	},
	postFormats: null,
	xfnRels: [],
	existingCategories: [],
	existingTags: [],
	bridgyHostMap: {},
	siteSettings: { bridgyAutoSuggest: false, defaultPostVariant: 'note' },
};

let root: HTMLDivElement;
let original_create_object_url: typeof URL.createObjectURL;
let original_revoke_object_url: typeof URL.revokeObjectURL;
let original_scroll_into_view: PropertyDescriptor | undefined;
let scroll_into_view: ReturnType<typeof vi.fn>;

beforeEach(() => {
	localStorage.clear();
	original_create_object_url = URL.createObjectURL;
	original_revoke_object_url = URL.revokeObjectURL;
	URL.createObjectURL = vi.fn(() => 'blob:test-photo');
	URL.revokeObjectURL = vi.fn();
	scroll_into_view = vi.fn();
	original_scroll_into_view = Object.getOwnPropertyDescriptor(
		HTMLElement.prototype,
		'scrollIntoView',
	);
	Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
		configurable: true,
		value: scroll_into_view,
	});
	root = document.createElement('div');
	document.body.appendChild(root);
	render(<PhotoMode token={token} composerConfig={composer_config} />, root);
});

afterEach(() => {
	render(null, root);
	root.remove();
	localStorage.clear();
	URL.createObjectURL = original_create_object_url;
	URL.revokeObjectURL = original_revoke_object_url;
	if (original_scroll_into_view) {
		Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', original_scroll_into_view);
	} else {
		delete (HTMLElement.prototype as Partial<HTMLElement>).scrollIntoView;
	}
});

async function flush(): Promise<void> {
	await new Promise((resolve) => setTimeout(resolve, 0));
}

function button_named(name: string): HTMLButtonElement {
	const button = Array.from(root.querySelectorAll('button')).find(
		(candidate) => candidate.textContent?.trim() === name,
	);
	if (!button) throw new Error(`No button named ${name}`);
	return button;
}

describe('PhotoMode More options drawer', () => {
	it('opens from More options and closes from its Close button', async () => {
		const dialog = root.querySelector('[role="dialog"]') as HTMLDivElement;
		expect(dialog.hasAttribute('hidden')).toBe(true);

		button_named('More options').click();
		await flush();
		expect(dialog.hasAttribute('hidden')).toBe(false);

		(root.querySelector('button[aria-label="Close"]') as HTMLButtonElement).click();
		await flush();
		expect(dialog.hasAttribute('hidden')).toBe(true);
	});
});

describe('PhotoMode submit errors', () => {
	it('places the alert next to Publish and reveals it when photo processing fails', async () => {
		const file_input = root.querySelector('#outpost-photo-file') as HTMLInputElement;
		Object.defineProperty(file_input, 'files', {
			configurable: true,
			value: [new File([new Uint8Array([1, 2, 3])], 'photo.jpg', { type: 'image/jpeg' })],
		});
		file_input.dispatchEvent(new Event('change', { bubbles: true }));
		await flush();

		const alt = root.querySelector('textarea[id^="outpost-photo-alt-"]') as HTMLTextAreaElement;
		alt.value = 'A test photo';
		alt.dispatchEvent(new Event('input', { bubbles: true }));
		await flush();
		(root.querySelector('button[type="submit"]') as HTMLButtonElement).click();

		await vi.waitFor(() => {
			expect(root.querySelector('[role="alert"]')?.textContent).toContain('load_failed');
		});
		const alert = root.querySelector('[role="alert"]') as HTMLDivElement;
		expect(alert.nextElementSibling?.classList.contains('outpost-status')).toBe(true);
		expect(alert.nextElementSibling?.nextElementSibling?.classList.contains('outpost-publish')).toBe(true);
		expect(scroll_into_view).toHaveBeenCalledWith({ block: 'nearest' });
		expect(scroll_into_view.mock.contexts[0]).toBe(alert);
		expect(alert.compareDocumentPosition(root.querySelector('.outpost-drawer') as Element)).toBe(
			Node.DOCUMENT_POSITION_PRECEDING,
		);
	});
});
