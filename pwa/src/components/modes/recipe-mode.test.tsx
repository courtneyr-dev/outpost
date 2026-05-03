import { describe, it, expect } from 'vitest';
import { minutes_to_iso8601_duration } from './recipe-mode';

/**
 * The duration helper is exported and used at submit-time to convert a
 * friendly minute count into the ISO 8601 form Post Kinds + h-recipe-aware
 * themes expect. Edge cases are: zero/empty/invalid input, hours-only,
 * minutes-only, and mixed. The function is small but the branches are
 * easy to break in a refactor.
 */
describe('minutes_to_iso8601_duration', () => {
	it('returns empty string for zero', () => {
		expect(minutes_to_iso8601_duration(0)).toBe('');
	});

	it('returns empty string for negative input', () => {
		expect(minutes_to_iso8601_duration(-5)).toBe('');
	});

	it('returns empty string for NaN', () => {
		expect(minutes_to_iso8601_duration(NaN)).toBe('');
	});

	it('returns empty string for Infinity', () => {
		expect(minutes_to_iso8601_duration(Infinity)).toBe('');
	});

	it('formats a sub-hour value as PT<n>M', () => {
		expect(minutes_to_iso8601_duration(45)).toBe('PT45M');
	});

	it('formats exactly one hour as PT1H', () => {
		expect(minutes_to_iso8601_duration(60)).toBe('PT1H');
	});

	it('formats whole hours as PT<n>H without a minutes component', () => {
		expect(minutes_to_iso8601_duration(120)).toBe('PT2H');
	});

	it('formats mixed hours+minutes', () => {
		expect(minutes_to_iso8601_duration(90)).toBe('PT1H30M');
	});

	it('rounds fractional minutes to the nearest whole minute', () => {
		expect(minutes_to_iso8601_duration(90.4)).toBe('PT1H30M');
		expect(minutes_to_iso8601_duration(90.5)).toBe('PT1H31M');
	});
});
