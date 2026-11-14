import { render } from 'preact';
import { describe, expect, it, vi } from 'vitest';
import { SettingsSheet, status_text } from './settings-sheet';
import { AppHeader } from './app-header';
import { AboutTab, diagnostics_text } from './modes/about-tab';

function mount(node: preact.JSX.Element): HTMLElement {
	const root = document.createElement('div');
	document.body.appendChild(root);
	render(node, root);
	return root;
}

const sheet = (overrides: Partial<Parameters<typeof SettingsSheet>[0]> = {}): HTMLElement =>
	mount(
		<SettingsSheet
			open
			onClose={() => undefined}
			mode="system"
			source="outpost"
			start="home"
			status={{ kind: 'idle' }}
			onMode={() => undefined}
			onSource={() => undefined}
			onStart={() => undefined}
			onReset={() => undefined}
			onRetry={() => undefined}
			{...overrides}
		/>,
	);

describe('SettingsSheet', () => {
	it('offers two independent, labeled radio groups with the current values selected', () => {
		const root = sheet({ mode: 'night', source: 'site' });
		const legends = [...root.querySelectorAll('legend')].map((l) => l.textContent);
		expect(legends).toEqual(['Appearance', 'Style source']);
		const modes = [...root.querySelectorAll<HTMLInputElement>('input[name="outpost-settings-mode"]')];
		const sources = [...root.querySelectorAll<HTMLInputElement>('input[name="outpost-settings-source"]')];
		expect(modes.map((i) => i.value)).toEqual(['day', 'night', 'system']);
		expect(sources.map((i) => i.value)).toEqual(['outpost', 'site']);
		expect(modes.find((i) => i.checked)?.value).toBe('night');
		expect(sources.find((i) => i.checked)?.value).toBe('site');
		// Every radio is inside its own label: the whole option is the target.
		[...modes, ...sources].forEach((i) => expect(i.closest('label')).not.toBeNull());
	});

	it('changing one group calls only its own handler', () => {
		const onMode = vi.fn();
		const onSource = vi.fn();
		const root = sheet({ onMode, onSource });
		root.querySelector<HTMLInputElement>('input[value="day"]')?.click();
		expect(onMode).toHaveBeenCalledWith('day');
		expect(onSource).not.toHaveBeenCalled();
		root.querySelector<HTMLInputElement>('input[value="site"]')?.click();
		expect(onSource).toHaveBeenCalledWith('site');
	});

	it('offers all eight start screens, Home first, with a visible label', () => {
		const root = sheet({ start: 'listen' });
		const select = root.querySelector<HTMLSelectElement>('select#outpost-settings-start');
		expect(root.querySelector('label[for="outpost-settings-start"]')?.textContent).toBe('Default start screen');
		expect([...(select?.options ?? [])].map((o) => [o.value, o.textContent])).toEqual([
			['home', 'Home'],
			['note', 'Post'],
			['reply', 'Reply'],
			['photo', 'Photo'],
			['listen', 'Doing'],
			['life', 'Life'],
			['recipe', 'Recipe'],
			['about', 'About'],
		]);
		expect(select?.value).toBe('listen');
	});

	it('choosing a start screen calls only its handler', () => {
		const onStart = vi.fn();
		const onMode = vi.fn();
		const root = sheet({ onStart, onMode });
		const select = root.querySelector<HTMLSelectElement>('select') as HTMLSelectElement;
		select.value = 'recipe';
		select.dispatchEvent(new Event('change', { bubbles: true }));
		expect(onStart).toHaveBeenCalledWith('recipe');
		expect(onMode).not.toHaveBeenCalled();
	});

	it('reset is unavailable at the defaults and calls its handler otherwise', () => {
		const reset = (root: HTMLElement): HTMLButtonElement =>
			[...root.querySelectorAll('button')].find((b) => b.textContent === 'Reset preferences') as HTMLButtonElement;
		expect(reset(sheet()).disabled).toBe(true);
		const onReset = vi.fn();
		const changed = sheet({ start: 'photo', onReset });
		reset(changed).click();
		expect(onReset).toHaveBeenCalledTimes(1);
	});

	it('keeps the status region mounted while empty', () => {
		const region = sheet().querySelector('.outpost-settings__status');
		expect(region?.getAttribute('role')).toBe('status');
		expect(region?.textContent).toBe('');
	});

	it('says plainly when a choice is device-only and why', () => {
		expect(status_text({ kind: 'device-only', reason: 'offline', stored: true })).toContain('Applied on this device');
		expect(status_text({ kind: 'device-only', reason: 'offline', stored: true })).toContain("you're offline");
		expect(status_text({ kind: 'device-only', reason: 'server', stored: false })).toContain('Applied for now');
		expect(status_text({ kind: 'saved' })).toBe('Saved to your site.');
	});
});

describe('AppHeader', () => {
	it('names the icon-only controls and reflects the shared mode', () => {
		const root = mount(
			<AppHeader siteHost="example.test" mode="night" onMode={() => undefined} onOpenSettings={() => undefined} settingsOpen={false} />,
		);
		const checked = root.querySelector<HTMLInputElement>('input[name="outpost-header-mode"]:checked');
		expect(checked?.value).toBe('night');
		expect(checked?.closest('label')?.textContent).toContain('Dark');
		const settings = root.querySelector('button[aria-haspopup="dialog"]');
		expect(settings?.textContent).toContain('Preferences');
		expect(settings?.getAttribute('aria-expanded')).toBe('false');
		expect(root.querySelector('.outpost-signpost')?.getAttribute('aria-hidden')).toBe('true');
	});
});

describe('AboutTab', () => {
	const props = {
		siteUrl: 'https://example.test',
		me: 'https://example.test/',
		scope: 'create profile',
		connection: 'connected' as const,
		companions: null,
		onRetry: () => undefined,
		onReauth: () => undefined,
		onSignOut: () => undefined,
		queueOwner: null,
		preferences: {
			mode: 'system' as const,
			source: 'outpost' as const,
			start: 'home' as const,
			status: { kind: 'idle' as const },
			onMode: () => undefined,
			onSource: () => undefined,
			onStart: () => undefined,
			onReset: () => undefined,
			onRetry: () => undefined,
		},
	};

	it('shows runtime connection data first and no publish action', () => {
		const root = mount(<AboutTab {...props} />);
		const headings = [...root.querySelectorAll('h3')].filter((h) => !h.closest('details')).map((h) => h.textContent);
		expect(headings).toEqual(['Connection', 'Preferences', 'Help and documentation', 'Project']);
		expect(root.textContent).toContain('example.test');
		expect(root.textContent).toContain('Connected');
		expect([...root.querySelectorAll('button')].map((b) => b.textContent)).not.toContain('Publish');
		// No profile fields. Outside the preference choices (radios and one
		// select), the only inputs are the share-sheet guide's progress
		// checkboxes and read-only copy fields, inside a collapsed
		// disclosure. Nothing accepts typed text.
		root.querySelectorAll<HTMLInputElement | HTMLTextAreaElement>('input, textarea').forEach((field) => {
			if (field.type === 'radio') {
				expect(field.closest('.outpost-settings')).not.toBeNull();
				return;
			}
			expect(field.closest('details')).not.toBeNull();
			expect(field.type === 'checkbox' || field.readOnly).toBe(true);
		});
	});

	it('says unknown instead of guessing before checks run', () => {
		const root = mount(<AboutTab {...props} connection="checking" />);
		expect(root.textContent).toContain('Not checked yet');
		expect(root.textContent).not.toContain('Accepted by your site');
	});

	it('offers sign-in only when the sign-in expired', () => {
		expect(mount(<AboutTab {...props} connection="signed-out" />).textContent).toContain('Sign in again');
		expect(mount(<AboutTab {...props} />).textContent).not.toContain('Sign in again');
	});

	it('links to the real help destinations', () => {
		const hrefs = [...mount(<AboutTab {...props} />).querySelectorAll('.outpost-link-list a')].map((a) => a.getAttribute('href'));
		expect(hrefs).toEqual([
			'https://courtneyr-dev.github.io/outpost/getting-started/',
			'https://courtneyr-dev.github.io/outpost/',
			'https://courtneyr-dev.github.io/outpost/common-tasks/#share-to-outpost-from-your-phone',
			'https://courtneyr-dev.github.io/outpost/troubleshooting/',
			'https://courtneyr-dev.github.io/outpost/privacy-and-data/',
			'https://github.com/courtneyr-dev/outpost',
			'https://github.com/courtneyr-dev/outpost/issues',
		]);
	});

	it('offers sign-out in Connection and says what it removes', async () => {
		const onSignOut = vi.fn();
		const root = mount(<AboutTab {...props} onSignOut={onSignOut} />);
		const button = [...root.querySelectorAll('button')].find((b) => b.textContent === 'Sign out of this device') as HTMLButtonElement;
		const help = document.getElementById(button.getAttribute('aria-describedby') ?? '')?.textContent ?? '';
		expect(help).toContain('unsent drafts');
		expect(help).toContain('queued posts');
		button.click();
		await vi.waitFor(() => expect(onSignOut).toHaveBeenCalledTimes(1));
	});

	it('its preferences do not collide with the sheet when both are in the page', () => {
		const page = document.createElement('div');
		document.body.appendChild(page);
		render(
			<>
				<AboutTab {...props} />
				<SettingsSheet
					open
					onClose={() => undefined}
					{...props.preferences}
				/>
			</>,
			page,
		);
		const names = [...page.querySelectorAll<HTMLInputElement>('input[type="radio"]')].map((i) => i.name);
		expect(names.filter((n) => n.startsWith('outpost-about-prefs-'))).toHaveLength(5);
		expect(names.filter((n) => n.startsWith('outpost-settings-'))).toHaveLength(5);
		const ids = [...page.querySelectorAll('[id]')].map((el) => el.id);
		expect(new Set(ids).size).toBe(ids.length);
		page.remove();
	});

	it('shows the version and credits from readme.txt', () => {
		const root = mount(<AboutTab {...props} />);
		expect(root.textContent).toContain('Micropub plugin by David Shanske');
		expect(root.textContent).toContain('Bridgy by Ryan Barrett');
	});

	it('diagnostics carry no token', () => {
		const text = diagnostics_text(props, '1.0.24');
		expect(text).toContain('Outpost version: 1.0.24');
		expect(text).not.toMatch(/bearer|access_token|token:/i);
	});
});
