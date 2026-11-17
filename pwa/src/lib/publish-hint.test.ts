import { describe, it, expect } from 'vitest';
import { missing_fields_hint } from './publish-hint';

describe('missing_fields_hint', () => {
	it('is empty when nothing is missing', () => {
		expect(missing_fields_hint([])).toBe('');
	});

	it('names one field', () => {
		expect(missing_fields_hint(['Body'])).toBe('Still needed: Body.');
	});

	it('joins two fields with "and"', () => {
		expect(missing_fields_hint(['Title', 'Body'])).toBe('Still needed: Title and Body.');
	});

	it('lists three or more with a serial comma', () => {
		expect(missing_fields_hint(['Recipe title', 'Ingredients', 'Instructions'])).toBe(
			'Still needed: Recipe title, Ingredients, and Instructions.',
		);
	});
});
