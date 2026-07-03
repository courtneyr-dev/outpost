import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import 'fake-indexeddb/auto';
import { render } from 'preact';
import { ListenMode } from './listen-mode';
import type { StoredToken } from '../../lib/token-store';
import type { MicropubEnvironment } from '../../lib/micropub';

const mock_token: StoredToken = {
	accessToken: 'test-token',
	tokenType: 'Bearer',
	scope: 'create',
	me: 'https://example.test/',
	storedAt: 0,
};

let root: HTMLDivElement;
let posted_bodies: string[];

beforeEach(() => {
	root = document.createElement('div');
	document.body.appendChild(root);
	posted_bodies = [];
});

afterEach(() => {
	render(null, root);
	root.remove();
});

/**
 * Discovery GET returns a Link header; create POSTs are captured into
 * posted_bodies and succeed with 201 + Location.
 */
function make_env(): MicropubEnvironment {
	return {
		fetch: async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
			if (init?.method === 'POST') {
				posted_bodies.push(String(init.body ?? ''));
				return new Response('', {
					status: 201,
					headers: { Location: 'https://example.test/2026/07/02/created' },
				});
			}
			return new Response('<html></html>', {
				status: 200,
				headers: { Link: '<https://example.test/mp>; rel="micropub"' },
			});
		},
	};
}

function mount(env: MicropubEnvironment): void {
	render(<ListenMode token={mock_token} micropubEnv={env} />, root);
}

async function flush(times = 1): Promise<void> {
	for (let i = 0; i < times; i++) {
		await new Promise((resolve) => setTimeout(resolve, 0));
	}
}

function variant_radios(): HTMLInputElement[] {
	return Array.from(
		root.querySelectorAll('input[name="outpost-listen-variant"]'),
	) as HTMLInputElement[];
}

function input_for(id: string): HTMLInputElement {
	return root.querySelector('#' + id) as HTMLInputElement;
}

function set_value(el: HTMLInputElement, value: string): void {
	el.value = value;
	el.dispatchEvent(new Event('input', { bubbles: true }));
}

function submit(): void {
	root
		.querySelector('form')
		?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
}

// VARIANT_ORDER: listen(0) watch(1) read(2) play(3) game(4) jam(5)
//                checkin(6) eat(7) drink(8) exercise(9)

describe('ListenMode variant-scoped field state', () => {
	it('a stale rating from Listen does not block or leak into a Drink post', async () => {
		// Regression for the 2026-07-02 Tier 2 pass (T2.26 → T2.27): a
		// rating of 9 entered on Listen kept validating after switching to
		// Drink — which has no rating field — surfacing a phantom
		// "Rating must be a number between 1 and 5." error on submit.
		const env = make_env();
		mount(env);

		// Listen: valid URL + out-of-range rating → validation error.
		set_value(input_for('outpost-listen-target'), 'https://example.test/track');
		set_value(input_for('outpost-listen-rating'), '9');
		await flush(); // let Preact commit the input state before submitting
		submit();
		await flush(2);
		expect(root.textContent).toContain('Rating must be a number between 1 and 5.');
		expect(posted_bodies.length).toBe(0);

		// Switch to Drink and post with only the drink name filled.
		variant_radios()[8]?.click();
		await flush();
		set_value(input_for('outpost-listen-person'), 'Coffee');
		await flush();
		submit();
		await flush(4);

		expect(root.textContent).not.toContain('Rating must be a number');
		expect(posted_bodies.length).toBe(1);
		expect(posted_bodies[0]).toContain('drink-of=Coffee');
		expect(posted_bodies[0]).not.toContain('rating=');
	});

	it('a stale video URL from Drink does not submit on Listen', async () => {
		// The video input only renders for hasGeocode variants; its state
		// must not ride along when posting from a variant without it.
		const env = make_env();
		mount(env);

		variant_radios()[8]?.click(); // drink — has the video field
		await flush();
		set_value(input_for('outpost-listen-video-url'), 'https://example.test/clip.mp4');
		await flush();

		variant_radios()[0]?.click(); // back to listen — no video field
		await flush();
		set_value(input_for('outpost-listen-target'), 'https://example.test/track');
		await flush();
		submit();
		await flush(4);

		expect(posted_bodies.length).toBe(1);
		expect(posted_bodies[0]).toContain('listen-of=');
		expect(posted_bodies[0]).not.toContain('video=');
	});
});
