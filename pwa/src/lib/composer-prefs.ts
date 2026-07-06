/**
 * Composer preferences — small localStorage helpers for per-device
 * composer UI state that should survive page loads.
 *
 * Currently one flag:
 *
 *   - outpost.composer.more-open — remembers whether the "More options"
 *                                  drawer is open. Once a user opens it,
 *                                  it stays open on future posts so the
 *                                  fields they reach for (categories,
 *                                  tags, syndication) are one glance away.
 *
 * localStorage is the right tier: per-device, persistent across sessions,
 * and this is a UI preference — not the auth token, which lives in
 * encrypted IndexedDB per the Hard Contract.
 */

import { useState } from 'preact/hooks';

const KEY_MORE_OPEN = 'outpost.composer.more-open';

function safe_get( key: string ): string | null {
	try {
		return globalThis.localStorage?.getItem( key ) ?? null;
	} catch ( _err ) {
		// localStorage can throw in private-browsing or sandboxed iframes —
		// treat as unavailable.
		return null;
	}
}

function safe_set( key: string, value: string ): void {
	try {
		globalThis.localStorage?.setItem( key, value );
	} catch ( _err ) {
		// no-op
	}
}

export function read_more_open(): boolean {
	return safe_get( KEY_MORE_OPEN ) === '1';
}

export function write_more_open( open: boolean ): void {
	safe_set( KEY_MORE_OPEN, open ? '1' : '0' );
}

/**
 * Drawer-open state that persists per device. Drop-in replacement for
 * `useState(false)`: returns `[open, set_open]` where `set_open` also
 * writes the preference so the next post opens in the same state.
 */
export function useMoreOpen(): [ boolean, ( open: boolean ) => void ] {
	const [ open, set_open ] = useState< boolean >( read_more_open );
	const set = ( next: boolean ): void => {
		set_open( next );
		write_more_open( next );
	};
	return [ open, set ];
}
