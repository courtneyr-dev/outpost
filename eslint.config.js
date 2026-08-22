import js from '@eslint/js';
import globals from 'globals';
import reactPlugin from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import tseslint from 'typescript-eslint';

export default tseslint.config(
	{
		ignores: [ 'build/**', 'node_modules/**', 'vendor/**' ],
	},
	{
		files: [ 'pwa/src/**/*.{ts,tsx}' ],
		extends: [ js.configs.recommended, ...tseslint.configs.recommended ],
		plugins: {
			'react-hooks': reactHooks,
		},
		rules: {
			'react-hooks/rules-of-hooks': 'error',
			'react-hooks/exhaustive-deps': 'warn',
			'@typescript-eslint/no-explicit-any': 'error',
			'@typescript-eslint/no-unused-vars': [
				'error',
				{
					argsIgnorePattern: '^_',
					varsIgnorePattern: '^_',
					caughtErrorsIgnorePattern: '^_',
				},
			],
		},
	},
	// The block editor bundle. Without a block matching these files, flat
	// config falls back to a JSX-less parser and every component here dies
	// on "Parsing error: Unexpected token <" — which is what `lint:wp` did
	// for as long as the script has existed.
	{
		files: [ 'src/**/*.js' ],
		extends: [ js.configs.recommended ],
		plugins: {
			react: reactPlugin,
			'react-hooks': reactHooks,
		},
		languageOptions: {
			ecmaVersion: 'latest',
			sourceType: 'module',
			parserOptions: {
				ecmaFeatures: { jsx: true },
			},
			globals: {
				...globals.browser,
				wp: 'readonly',
			},
		},
		rules: {
			// Without this, every component imported purely for JSX reads
			// as an unused variable.
			'react/jsx-uses-vars': 'error',
			'react-hooks/rules-of-hooks': 'error',
			'react-hooks/exhaustive-deps': 'warn',
			'no-unused-vars': [
				'error',
				{
					argsIgnorePattern: '^_',
					varsIgnorePattern: '^_',
					caughtErrorsIgnorePattern: '^_',
				},
			],
		},
	},
	{
		files: [ 'src/**/__tests__/**/*.js' ],
		languageOptions: {
			globals: { ...globals.jest },
		},
	}
);
