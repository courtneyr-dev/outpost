/**
 * Static checks on structure.css for rules happy-dom can't lay out
 * (mockup review P5 and P6). The real-browser captures measure the result.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, it, expect } from 'vitest';

const source = readFileSync(resolve(__dirname, 'structure.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');

/** Declarations of every top-level or nested rule whose selector list includes `selector`. */
function rules_for(selector: string, within: string = source): string[] {
	const out: string[] = [];
	const re = /([^{}]+)\{([^{}]*)\}/g;
	let m: RegExpExecArray | null;
	while ((m = re.exec(within)) !== null) {
		const selectors = (m[1] ?? '').split(',').map((s) => s.trim());
		if (selectors.includes(selector)) out.push(m[2] ?? '');
	}
	return out;
}

/** Body of each `@media` block whose prelude matches. */
function media_blocks(prelude: RegExp): string[] {
	const out: string[] = [];
	const re = /@media([^{]+)\{/g;
	let m: RegExpExecArray | null;
	while ((m = re.exec(source)) !== null) {
		if (!prelude.test(m[1] ?? '')) continue;
		let depth = 1;
		let i = re.lastIndex;
		for (; i < source.length && depth > 0; i++) {
			if (source[i] === '{') depth++;
			else if (source[i] === '}') depth--;
		}
		out.push(source.slice(re.lastIndex, i - 1));
	}
	return out;
}

describe('P6: the selected appearance segment is not the publish color', () => {
	const selected = rules_for('.outpost-mode-switch__option:has(input:checked)').join('\n');

	it('paints from the segment tokens', () => {
		expect(selected).toMatch(/background:\s*var\(--outpost-segment-bg-selected\)/);
		expect(selected).toMatch(/color:\s*var\(--outpost-segment-fg-selected\)/);
	});

	it('never borrows the orange action fills', () => {
		expect(selected).not.toMatch(/--outpost-tab-bg-active|--outpost-button-primary-bg|--outpost-chip-bg-pressed|--outpost-chip-active-bg/);
	});

	it('keeps a non-color cue: an inset ring in the indicator ink', () => {
		expect(selected).toMatch(/box-shadow:\s*inset 0 0 0 2px var\(--outpost-tab-indicator\)/);
	});
});

describe('P5: the header stays on one row from 375 px', () => {
	const blocks = media_blocks(/min-width:\s*23\.4375rem/);

	it('has a 375 px media block that stops the header wrapping', () => {
		const header = blocks.flatMap((b) => rules_for('.outpost-app-header', b)).join('\n');
		expect(header).toMatch(/flex-wrap:\s*nowrap/);
	});

	it('lets the identity shrink so a long host can wrap inside it', () => {
		const identity = blocks.flatMap((b) => rules_for('.outpost-app-header__identity', b)).join('\n');
		expect(identity).toMatch(/flex:\s*1 1 auto/);
	});

	it('keeps the controls at their full 44 px size', () => {
		const controls = blocks.flatMap((b) => rules_for('.outpost-app-header__controls', b)).join('\n');
		expect(controls).toMatch(/flex:\s*0 0 auto/);
	});
});

describe('P1–P3: picker layouts', () => {
	it('compact pickers wrap content-sized tiles instead of fixed columns', () => {
		const compact = rules_for('.outpost-variant-picker--compact').join('\n');
		expect(compact).toMatch(/display:\s*flex/);
		expect(compact).toMatch(/flex-wrap:\s*wrap/);
	});

	it('tile pickers fit five 52 px columns in the 288 px form at 360 px', () => {
		const tiles = rules_for('.outpost-variant-picker--tiles').join('\n');
		expect(tiles).toMatch(/grid-template-columns:\s*repeat\(auto-fill,\s*minmax\(3\.25rem,\s*1fr\)\)/);
	});

	it('every option keeps a 44 px minimum hit target', () => {
		expect(rules_for('.outpost-radio').join('\n')).toMatch(/min-height:\s*2\.75rem/);
		expect(rules_for('.outpost-radio--tile').join('\n')).not.toMatch(/min-height:\s*(?!2\.75rem|3)/);
	});
});

describe('P4: the publish button', () => {
	it('spans the form width', () => {
		expect(rules_for('.outpost-button--publish').join('\n')).toMatch(/inline-size:\s*100%/);
	});

	it('a disabled publish button is no longer drawn with a dashed outline', () => {
		expect(rules_for('.outpost-button[disabled]').join('\n')).not.toMatch(/dashed/);
	});
});
