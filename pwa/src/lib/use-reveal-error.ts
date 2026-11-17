import { useEffect } from 'preact/hooks';
import type { RefObject } from 'preact';

/** Bring a newly reported submit error into the nearest visible viewport. */
export function useRevealError(ref: RefObject<HTMLElement>, message: string | null): void {
	useEffect(() => {
		if (!message || typeof ref.current?.scrollIntoView !== 'function') return;
		ref.current.scrollIntoView({ block: 'nearest' });
	}, [message, ref]);
}
