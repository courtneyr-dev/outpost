import { addons } from 'storybook/manager-api';

addons.register('outpost/sidebar-labels', () => {
	const repair = () => {
		// The Storybook 10.6.6 override hides each section's visible name.
		for (const button of document.querySelectorAll(
			'#storybook-explorer-menu button[data-action="collapse-root"][aria-label="Collapse"]',
		)) {
			if (button.textContent?.trim()) {
				button.removeAttribute('aria-label');
			}
		}
	};

	new MutationObserver(repair).observe(document.body, {
		attributeFilter: ['aria-label'],
		childList: true,
		subtree: true,
	});
	repair();
});
