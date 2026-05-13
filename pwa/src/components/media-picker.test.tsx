import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render } from 'preact';
import { useState } from 'preact/hooks';
import {
	MediaPicker,
	all_entries_have_alt,
	type MediaEntry,
} from './media-picker';

let root: HTMLDivElement;
let revoked_urls: string[];
let original_create_object_url: typeof URL.createObjectURL;
let original_revoke_object_url: typeof URL.revokeObjectURL;
let blob_counter = 0;

beforeEach(() => {
	root = document.createElement('div');
	document.body.appendChild(root);
	revoked_urls = [];
	blob_counter = 0;
	original_create_object_url = URL.createObjectURL;
	original_revoke_object_url = URL.revokeObjectURL;
	URL.createObjectURL = vi.fn(() => `blob:test-${String(++blob_counter)}`);
	URL.revokeObjectURL = vi.fn((url: string) => {
		revoked_urls.push(url);
	});
});

afterEach(() => {
	render(null, root);
	root.remove();
	URL.createObjectURL = original_create_object_url;
	URL.revokeObjectURL = original_revoke_object_url;
});

async function flush(): Promise<void> {
	await new Promise((resolve) => setTimeout(resolve, 0));
}

function make_file(name: string): File {
	return new File([new Uint8Array([1, 2, 3])], name, { type: 'image/jpeg' });
}

function file_input(): HTMLInputElement {
	return root.querySelector('input[type="file"]') as HTMLInputElement;
}

/**
 * Mount a controlled-MediaPicker test wrapper. Tests drive the picker by
 * interacting with DOM elements (file input, alt textareas, buttons) and
 * read the resulting state from the wrapper's last-onChange-arguments.
 */
function mount_controlled(initial: MediaEntry[] = []): {
	getEntries: () => MediaEntry[];
} {
	let captured: MediaEntry[] = initial;
	function Wrapper(): preact.JSX.Element {
		const [entries, setEntries] = useState<MediaEntry[]>(initial);
		captured = entries;
		return (
			<MediaPicker
				entries={entries}
				onChange={(next): void => {
					captured = next;
					setEntries(next);
				}}
				idPrefix="test"
				emptyLabel="Add photos"
				nonEmptyLabel="Add more"
			/>
		);
	}
	render(<Wrapper />, root);
	return { getEntries: () => captured };
}

describe('all_entries_have_alt', () => {
	it('returns true for an empty entries array', () => {
		expect(all_entries_have_alt([])).toBe(true);
	});

	it('returns true when every entry has alt text', () => {
		const entries: MediaEntry[] = [
			{
				id: 'a',
				file: make_file('a.jpg'),
				preview_url: 'blob:a',
				alt: 'cat on a windowsill',
				decorative: false,
			},
		];
		expect(all_entries_have_alt(entries)).toBe(true);
	});

	it('returns true when entry is decorative even with empty alt', () => {
		const entries: MediaEntry[] = [
			{
				id: 'a',
				file: make_file('a.jpg'),
				preview_url: 'blob:a',
				alt: '',
				decorative: true,
			},
		];
		expect(all_entries_have_alt(entries)).toBe(true);
	});

	it('returns false when any entry has empty alt and is not decorative', () => {
		const entries: MediaEntry[] = [
			{
				id: 'a',
				file: make_file('a.jpg'),
				preview_url: 'blob:a',
				alt: 'first photo',
				decorative: false,
			},
			{
				id: 'b',
				file: make_file('b.jpg'),
				preview_url: 'blob:b',
				alt: '',
				decorative: false,
			},
		];
		expect(all_entries_have_alt(entries)).toBe(false);
	});

	it('returns false for whitespace-only alt on a non-decorative entry', () => {
		const entries: MediaEntry[] = [
			{
				id: 'a',
				file: make_file('a.jpg'),
				preview_url: 'blob:a',
				alt: '   ',
				decorative: false,
			},
		];
		expect(all_entries_have_alt(entries)).toBe(false);
	});
});

describe('MediaPicker — empty state', () => {
	it('renders the empty label and a file input', () => {
		mount_controlled();
		const label = root.querySelector('label.outpost-label');
		expect(label?.textContent).toContain('Add photos');
		expect(file_input()).toBeTruthy();
		expect(file_input().multiple).toBe(true);
		expect(file_input().accept).toContain('image/jpeg');
	});

	it('does not render the entry list when there are no entries', () => {
		mount_controlled();
		expect(root.querySelector('.outpost-photo-list')).toBeNull();
	});
});

describe('MediaPicker — file picker integration', () => {
	it('appends entries when the user picks a single file', async () => {
		const wrapper = mount_controlled();
		const input = file_input();
		Object.defineProperty(input, 'files', {
			configurable: true,
			value: [make_file('cat.jpg')],
		});
		input.dispatchEvent(new Event('change', { bubbles: true }));
		await flush();

		const entries = wrapper.getEntries();
		expect(entries).toHaveLength(1);
		expect(entries[0]!.file.name).toBe('cat.jpg');
		expect(entries[0]!.alt).toBe('');
		expect(entries[0]!.decorative).toBe(false);
		expect(entries[0]!.preview_url).toMatch(/^blob:test-/);
	});

	it('appends multiple entries on multi-file pick', async () => {
		const wrapper = mount_controlled();
		const input = file_input();
		Object.defineProperty(input, 'files', {
			configurable: true,
			value: [make_file('a.jpg'), make_file('b.jpg'), make_file('c.jpg')],
		});
		input.dispatchEvent(new Event('change', { bubbles: true }));
		await flush();

		const entries = wrapper.getEntries();
		expect(entries).toHaveLength(3);
		expect(entries.map((e) => e.file.name)).toEqual(['a.jpg', 'b.jpg', 'c.jpg']);
	});

	it('clears the input value so re-picking the same file fires onChange', async () => {
		const wrapper = mount_controlled();
		const input = file_input();
		Object.defineProperty(input, 'files', {
			configurable: true,
			value: [make_file('dog.jpg')],
		});
		input.dispatchEvent(new Event('change', { bubbles: true }));
		await flush();

		expect(input.value).toBe('');
		expect(wrapper.getEntries()).toHaveLength(1);
	});
});

describe('MediaPicker — alt text + decorative controls', () => {
	const seeded: MediaEntry[] = [
		{
			id: 'a',
			file: make_file('a.jpg'),
			preview_url: 'blob:a',
			alt: '',
			decorative: false,
		},
	];

	it('renders the (required) marker when entry is non-decorative', () => {
		mount_controlled(seeded);
		const required = root.querySelector('.outpost-required');
		expect(required?.textContent).toContain('required');
	});

	it('hides the (required) marker after the user marks decorative', async () => {
		const wrapper = mount_controlled(seeded);
		const checkbox = root.querySelector(
			'input[type="checkbox"]',
		) as HTMLInputElement;
		checkbox.checked = true;
		checkbox.dispatchEvent(new Event('change', { bubbles: true }));
		await flush();

		expect(wrapper.getEntries()[0]!.decorative).toBe(true);
		expect(root.querySelector('.outpost-required')).toBeNull();
	});

	it('updates the entry alt text on textarea input', async () => {
		const wrapper = mount_controlled(seeded);
		const textarea = root.querySelector(
			'textarea.outpost-textarea',
		) as HTMLTextAreaElement;
		textarea.value = 'cat staring out the window at falling snow';
		textarea.dispatchEvent(new Event('input', { bubbles: true }));
		await flush();

		expect(wrapper.getEntries()[0]!.alt).toBe(
			'cat staring out the window at falling snow',
		);
	});

	it('disables the alt textarea when the entry is decorative', () => {
		mount_controlled([
			{ ...seeded[0]!, decorative: true },
		]);
		const textarea = root.querySelector(
			'textarea.outpost-textarea',
		) as HTMLTextAreaElement;
		expect(textarea.disabled).toBe(true);
	});
});

describe('MediaPicker — reorder + remove', () => {
	const three_entries: MediaEntry[] = [
		{
			id: 'a',
			file: make_file('a.jpg'),
			preview_url: 'blob:a',
			alt: 'first',
			decorative: false,
		},
		{
			id: 'b',
			file: make_file('b.jpg'),
			preview_url: 'blob:b',
			alt: 'second',
			decorative: false,
		},
		{
			id: 'c',
			file: make_file('c.jpg'),
			preview_url: 'blob:c',
			alt: 'third',
			decorative: false,
		},
	];

	it('moves an entry earlier when the up button is clicked', async () => {
		const wrapper = mount_controlled(three_entries);
		const up_buttons = Array.from(
			root.querySelectorAll('button[aria-label^="Move photo"]'),
		).filter((b) => b.getAttribute('aria-label')!.includes('earlier'));
		// Click "up" on the second entry (index 1).
		(up_buttons[1] as HTMLButtonElement).click();
		await flush();

		expect(wrapper.getEntries().map((e) => e.id)).toEqual(['b', 'a', 'c']);
	});

	it('disables the up button on the first entry', () => {
		mount_controlled(three_entries);
		const up_buttons = Array.from(
			root.querySelectorAll('button[aria-label^="Move photo"]'),
		).filter((b) => b.getAttribute('aria-label')!.includes('earlier'));
		expect((up_buttons[0] as HTMLButtonElement).disabled).toBe(true);
	});

	it('disables the down button on the last entry', () => {
		mount_controlled(three_entries);
		const down_buttons = Array.from(
			root.querySelectorAll('button[aria-label^="Move photo"]'),
		).filter((b) => b.getAttribute('aria-label')!.includes('later'));
		expect(
			(down_buttons[down_buttons.length - 1] as HTMLButtonElement).disabled,
		).toBe(true);
	});

	it('hides reorder buttons entirely when only one entry exists', () => {
		mount_controlled([three_entries[0]!]);
		const up_buttons = Array.from(
			root.querySelectorAll('button[aria-label*="earlier"]'),
		);
		const down_buttons = Array.from(
			root.querySelectorAll('button[aria-label*="later"]'),
		);
		expect(up_buttons).toHaveLength(0);
		expect(down_buttons).toHaveLength(0);
	});

	it('removes an entry and revokes its blob URL when remove is clicked', async () => {
		const wrapper = mount_controlled(three_entries);
		const remove_buttons = Array.from(
			root.querySelectorAll('button[aria-label^="Remove photo"]'),
		);
		// Remove the middle entry.
		(remove_buttons[1] as HTMLButtonElement).click();
		await flush();

		expect(wrapper.getEntries().map((e) => e.id)).toEqual(['a', 'c']);
		expect(revoked_urls).toContain('blob:b');
	});
});

describe('MediaPicker — disabled state', () => {
	it('disables the file input when the disabled prop is true', () => {
		function DisabledWrapper(): preact.JSX.Element {
			return (
				<MediaPicker
					entries={[]}
					onChange={(): void => {}}
					disabled={true}
					idPrefix="test-disabled"
				/>
			);
		}
		render(<DisabledWrapper />, root);
		expect(file_input().disabled).toBe(true);
	});
});
