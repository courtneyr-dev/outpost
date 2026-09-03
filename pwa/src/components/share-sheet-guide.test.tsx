import { describe, it, expect } from 'vitest';
import { render } from 'preact';
import { ShareSheetGuide } from './share-sheet-guide';

/**
 * The guide is static, but it builds three site-specific URLs from
 * location.origin (the share route, the wp-admin setup page). These assert the
 * URLs are constructed against the reader's own origin and that both platform
 * sections render, so a broken origin or a dropped section is caught.
 */
function renderGuide(): HTMLElement {
	const container = document.createElement('div');
	render(<ShareSheetGuide />, container);
	return container;
}

describe('ShareSheetGuide', () => {
	it('renders both platform sections', () => {
		const html = renderGuide().innerHTML;
		expect(html).toContain('Android');
		expect(html).toContain('Safari');
		expect(html).toContain('Show in Share Sheet');
		expect(html).toContain('Open URLs');
		expect(html).toContain('not');
	});

	it('builds the share-target URL and the wp-admin setup link against this origin', () => {
		const container = renderGuide();
		const origin = window.location.origin;

		const shareCode = Array.from(container.querySelectorAll('code')).find(
			(c) => (c.textContent ?? '').includes('/post/share-target')
		);
		expect(shareCode?.textContent).toBe(origin + '/post/share-target?url=');

		const adminLink = Array.from(container.querySelectorAll('a')).find(
			(a) => a.getAttribute('href')?.includes('outpost-ios-shortcut')
		);
		expect(adminLink?.getAttribute('href')).toBe(
			origin + '/wp-admin/options-general.php?page=outpost-ios-shortcut'
		);
		expect(adminLink?.getAttribute('target')).toBe('_blank');
		expect(adminLink?.getAttribute('rel')).toBe('noopener noreferrer');

		const docsLink = Array.from(container.querySelectorAll('a')).find((a) =>
			a
				.getAttribute('href')
				?.includes('courtneyr-dev.github.io/outpost/common-tasks')
		);
		expect(docsLink?.getAttribute('href')).toBe(
			'https://courtneyr-dev.github.io/outpost/common-tasks/#share-to-outpost-from-your-phone'
		);
	});
	it('tells both platforms what a shared photo becomes and gives iOS a photo Shortcut', () => {
		const container = renderGuide();
		const html = container.innerHTML;
		expect(html).toContain('Photo to Outpost');
		expect(html).toContain('Images');
		const photoCode = container.querySelector(
			'code.outpost-share-guide__url--photo'
		);
		expect(photoCode?.textContent?.trim()).toBe(
			window.location.origin + '/post/?mode=photo'
		);
	});

	it('tells iOS users how to move the shortcut up the share sheet', () => {
		const html = renderGuide().innerHTML;
		expect(html).toContain('Edit Actions');
		expect(html).toContain('Favorites');
	});
});
