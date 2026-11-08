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
			status={{ kind: 'idle' }}
			onMode={() => undefined}
			onSource={() => undefined}
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
		expect(settings?.textContent).toContain('Settings');
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
	};

	it('shows runtime connection data first and no publish action', () => {
		const root = mount(<AboutTab {...props} />);
		const headings = [...root.querySelectorAll('h3')].map((h) => h.textContent);
		expect(headings[0]).toBe('Connection');
		expect(root.textContent).toContain('example.test');
		expect(root.textContent).toContain('Connected');
		expect([...root.querySelectorAll('button')].map((b) => b.textContent)).not.toContain('Publish');
		// No profile fields: the only inputs are the share-sheet guide's
		// progress checkboxes and read-only copy fields, inside a
		// collapsed disclosure. Nothing accepts typed text.
		root.querySelectorAll<HTMLInputElement | HTMLTextAreaElement>('input, textarea').forEach((field) => {
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
			'https://courtneyr-dev.github.io/outpost/',
			'https://courtneyr-dev.github.io/outpost/getting-started/',
			'https://courtneyr-dev.github.io/outpost/troubleshooting/',
			'https://courtneyr-dev.github.io/outpost/privacy-and-data/',
			'https://github.com/courtneyr-dev/outpost',
			'https://github.com/courtneyr-dev/outpost/issues',
		]);
	});

	it('diagnostics carry no token', () => {
		const text = diagnostics_text(props, '1.0.24');
		expect(text).toContain('Outpost version: 1.0.24');
		expect(text).not.toMatch(/bearer|access_token|token:/i);
	});
});
