import type { StorybookConfig } from '@storybook/preact-vite';

// No viteFinal: builder-vite loads the repo's vite.config.ts (Preact preset,
// react → preact/compat aliases, preserveSymlinks) and drops its `build` block,
// so stories compile like the app and never write into build/pwa/.
const config: StorybookConfig = {
	stories: ['../pwa/src/**/*.stories.tsx'],
	addons: ['@storybook/addon-docs', '@storybook/addon-a11y'],
	framework: '@storybook/preact-vite',
	core: { disableTelemetry: true },
};

export default config;
