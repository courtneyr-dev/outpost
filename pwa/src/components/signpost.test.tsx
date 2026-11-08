import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { render } from 'preact';
import { describe, expect, it } from 'vitest';
import { Signpost } from './signpost';

const canonical = readFileSync(resolve(__dirname, '../../../assets/icons/outpost-icon.svg'), 'utf8');

function canonical_shapes(): { paths: string[]; rects: string[] } {
	const paths = [...canonical.matchAll(/<path d="([^"]+)"/g)].map((m) => m[1] ?? '');
	// Pole and foot: the rects with an x attribute (the tile and frame have none or x=14).
	const rects = [...canonical.matchAll(/<rect x="(\d+)" y="(\d+)" width="(\d+)" height="(\d+)"/g)]
		.filter((m) => m[1] !== '14')
		.map((m) => `${m[1]},${m[2]},${m[3]},${m[4]}`);
	return { paths, rects };
}

function mount(node: preact.JSX.Element): HTMLElement {
	const root = document.createElement('div');
	document.body.appendChild(root);
	render(node, root);
	return root;
}

describe('Signpost', () => {
	it('uses the canonical icon geometry unchanged', () => {
		const root = mount(<Signpost />);
		const { paths, rects } = canonical_shapes();
		expect(paths).toHaveLength(2);
		expect(rects).toEqual(['121,44,14,164', '97,200,62,12']);
		const drawn = root.querySelector('g');
		expect([...(drawn?.querySelectorAll('path') ?? [])].map((p) => p.getAttribute('d'))).toEqual(paths);
		expect(
			[...(drawn?.querySelectorAll('rect') ?? [])].map((r) =>
				['x', 'y', 'width', 'height'].map((a) => r.getAttribute(a)).join(','),
			),
		).toEqual(rects);
	});

	it('is an outline in currentColor with no tile or frame', () => {
		const svg = mount(<Signpost />).querySelector('svg');
		expect(svg?.querySelector('g')?.getAttribute('fill')).toBe('none');
		expect(svg?.querySelector('g')?.getAttribute('stroke')).toBe('currentColor');
		expect(svg?.outerHTML).not.toMatch(/#[0-9a-f]{3,6}/i);
	});

	it('gives each instance its own mask id', () => {
		const root = mount(
			<>
				<Signpost />
				<Signpost />
			</>,
		);
		const masks = [...root.querySelectorAll('mask')].map((m) => m.id);
		expect(masks).toHaveLength(2);
		expect(new Set(masks).size).toBe(2);
		root.querySelectorAll('svg').forEach((svg) => {
			const id = svg.querySelector('mask')?.id ?? '';
			expect(svg.querySelector('g rect')?.getAttribute('mask')).toBe(`url(#${id})`);
		});
	});

	it('is decorative next to a wordmark and named when standalone', () => {
		const decorative = mount(<Signpost />).querySelector('svg');
		expect(decorative?.getAttribute('aria-hidden')).toBe('true');
		expect(decorative?.getAttribute('role')).toBeNull();
		const named = mount(<Signpost label="Outpost" />).querySelector('svg');
		expect(named?.getAttribute('role')).toBe('img');
		expect(named?.getAttribute('aria-label')).toBe('Outpost');
	});
});
