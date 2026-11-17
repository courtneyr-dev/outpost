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

import { createContext } from 'preact';
import { useContext, useEffect, useState } from 'preact/hooks';

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
 * Whether the composer mode around a component is the one on screen.
 * ComposerTabs mounts every mode at once and hides all but one, so a
 * hidden mode must keep its modal drawer shut: an open drawer locks page
 * scrolling and holds focus. A mode rendered on its own counts as on screen.
 */
export const ModeOnScreen = createContext< boolean >( true );

/** Every mounted useMoreOpen, so one mode's change reaches the others. */
const more_open_listeners = new Set< ( open: boolean ) => void >();

/**
 * Drawer-open state that persists per device. Drop-in replacement for
 * `useState(false)`: returns `[open, set_open]` where `set_open` also
 * writes the preference so the next post opens in the same state.
 *
 * The value is shared by every mode, and reads as open only in the mode
 * on screen (ModeOnScreen).
 */
export function useMoreOpen(): [ boolean, ( open: boolean ) => void ] {
	const on_screen = useContext( ModeOnScreen );
	const [ open, set_open ] = useState< boolean >( read_more_open );
	useEffect( () => {
		more_open_listeners.add( set_open );
		return (): void => {
			more_open_listeners.delete( set_open );
		};
	}, [] );
	const set = ( next: boolean ): void => {
		write_more_open( next );
		set_open( next );
		for ( const listener of more_open_listeners ) {
			listener( next );
		}
	};
	return [ open && on_screen, set ];
}
