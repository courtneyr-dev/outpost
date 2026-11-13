import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const token_files = [
	resolve(__dirname, 'tokens.css'),
	resolve(__dirname, '../../../styles/outpost-tokens.css'),
];

describe.each(token_files)('%s', (file) => {
	it('updates the focus alias in every focus-ring color block', () => {
		const source = readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
		const blocks = [...source.matchAll(/([^{}]+)\{([^{}]*)\}/g)]
			.map((match) => match[2] ?? '')
			.filter((body) => body.includes('--outpost-focus-ring-color:'));

		expect(blocks.length).toBeGreaterThan(0);
		for (const body of blocks) {
			expect(body).toMatch(/--outpost-focus:\s*var\(--outpost-focus-ring-color\);/);
		}
	});
});
