import type { Meta, StoryObj } from '@storybook/preact-vite';
import { fn } from 'storybook/test';
import { RecipeMode } from './recipe-mode';
import { micropubOnline, source, token } from '../../../../.storybook/fixtures';

const meta = {
	title: 'Modes/RecipeMode',
	component: RecipeMode,
	parameters: source(
		'pwa/src/components/modes/recipe-mode.tsx',
		'The Recipe tab: a structured recipe with title, ingredients, instructions, yield, total time and notes.',
	),
	args: { token, micropubEnv: micropubOnline, onSignInExpired: fn() },
} satisfies Meta<typeof RecipeMode>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Empty: Story = {};
