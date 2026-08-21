import { describe, it, expect } from 'vitest';
import { parse_dispatch_params, parse_share_target } from './share-target';

/**
 * F6 dispatch-param routing. The PHP share-target dispatcher 303s to
 * /post/?mode=...&url=...; these tests pin which composer tab + variant
 * each mode value lands on, including the kinds added for full Post
 * Kinds parity (Doing: exercise/craft/event/review/video/audio; Life:
 * mood/weather/sleep/trip/itinerary/question; Recipe).
 */
describe('parse_dispatch_params', () => {
	it('routes reply modes with a default variant', () => {
		const data = parse_dispatch_params('?picker=reply&default=favorite&url=https%3A%2F%2Fexample.test%2Fp');
		expect(data).toMatchObject({ tab: 'reply', replyVariant: 'favorite', url: 'https://example.test/p' });
	});

	it('routes every Doing variant to the listen tab', () => {
		const doing = [
			'listen',
			'watch',
			'read',
			'play',
			'game',
			'jam',
			'checkin',
			'eat',
			'drink',
			'exercise',
			'craft',
			'event',
			'review',
			'video',
			'audio',
		];
		for (const mode of doing) {
			const data = parse_dispatch_params(`?mode=${mode}&url=https%3A%2F%2Fexample.test%2Fx`);
			expect(data, mode).toMatchObject({ tab: 'listen', doingVariant: mode });
		}
	});

	it('routes every Life variant to the life tab', () => {
		const life = ['mood', 'weather', 'sleep', 'trip', 'itinerary', 'question'];
		for (const mode of life) {
			const data = parse_dispatch_params(`?mode=${mode}&text=hello`);
			expect(data, mode).toMatchObject({ tab: 'life', lifeVariant: mode, content: 'hello' });
		}
	});

	it('routes mode=recipe to the recipe tab', () => {
		const data = parse_dispatch_params('?mode=recipe&title=Bread&text=knead');
		expect(data).toMatchObject({ tab: 'recipe', title: 'Bread', content: 'knead' });
	});

	it('still routes note and article to the note tab', () => {
		expect(parse_dispatch_params('?mode=note&text=hi')).toMatchObject({
			tab: 'note',
			variant: 'note',
			content: 'hi',
		});
		expect(parse_dispatch_params('?mode=article&title=T&text=B')).toMatchObject({
			tab: 'note',
			variant: 'article',
			title: 'T',
		});
	});

	it('returns null with no dispatch params', () => {
		expect(parse_dispatch_params('?utm_source=x')).toBeNull();
	});
});

describe('parse_share_target (Web Share Target Level 1 fallback)', () => {
	it('routes a bare URL to the reply tab', () => {
		expect(parse_share_target('?url=https%3A%2F%2Fexample.test%2Fp')).toMatchObject({
			tab: 'reply',
			url: 'https://example.test/p',
		});
	});

	it('accepts the extended reply variants via ?variant=', () => {
		expect(parse_share_target('?url=https%3A%2F%2Fexample.test%2Fp&variant=acquisition')).toMatchObject({
			tab: 'reply',
			replyVariant: 'acquisition',
		});
	});

	it('routes title + text to an article', () => {
		expect(parse_share_target('?title=T&text=B')).toMatchObject({
			tab: 'note',
			variant: 'article',
		});
	});

	it('returns null when nothing shareable is present', () => {
		expect(parse_share_target('')).toBeNull();
	});
});
