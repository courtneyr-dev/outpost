import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render } from 'preact';
import { useRef } from 'preact/hooks';
import { useRevealError } from './use-reveal-error';

let root: HTMLDivElement;
let scroll_into_view: ReturnType<typeof vi.fn>;
let original_scroll_into_view: PropertyDescriptor | undefined;

function Harness({ message, count = 0 }: { message: string | null; count?: number }) {
	const error_ref = useRef<HTMLDivElement>(null);
	useRevealError(error_ref, message);
	return (
		<div ref={error_ref} data-count={count}>
			{message}
		</div>
	);
}

beforeEach(() => {
	root = document.createElement('div');
	document.body.appendChild(root);
	scroll_into_view = vi.fn();
	original_scroll_into_view = Object.getOwnPropertyDescriptor(
		HTMLElement.prototype,
		'scrollIntoView',
	);
	Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
		configurable: true,
		value: scroll_into_view,
	});
});

afterEach(() => {
	render(null, root);
	root.remove();
	if (original_scroll_into_view) {
		Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', original_scroll_into_view);
	} else {
		delete (HTMLElement.prototype as Partial<HTMLElement>).scrollIntoView;
	}
});

async function effects(): Promise<void> {
	await vi.waitFor(() => expect(root.firstElementChild).not.toBeNull());
	await new Promise((resolve) => requestAnimationFrame(() => resolve(undefined)));
}

describe('useRevealError', () => {
	it('scrolls once for each new non-empty message', async () => {
		render(<Harness message="First error" />, root);
		await vi.waitFor(() => expect(scroll_into_view).toHaveBeenCalledTimes(1));
		expect(scroll_into_view).toHaveBeenLastCalledWith({ block: 'nearest' });

		render(<Harness message="Second error" />, root);
		await vi.waitFor(() => expect(scroll_into_view).toHaveBeenCalledTimes(2));
	});

	it('does not scroll on unrelated rerenders', async () => {
		render(<Harness message="Same error" count={1} />, root);
		await vi.waitFor(() => expect(scroll_into_view).toHaveBeenCalledTimes(1));
		render(<Harness message="Same error" count={2} />, root);
		await effects();
		expect(scroll_into_view).toHaveBeenCalledTimes(1);
	});

	it('does not scroll for null or empty messages', async () => {
		render(<Harness message={null} />, root);
		await effects();
		render(<Harness message="" />, root);
		await effects();
		expect(scroll_into_view).not.toHaveBeenCalled();
	});
});
