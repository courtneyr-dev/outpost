import { clear_token, type TokenStoreEnvironment } from './token-store';
import { clear_cache as clear_appearance_cache } from './appearance';
import { clear_drafts } from './drafts';
import { clear_pending_shares, type PendingSharesEnvironment } from './share-target';

/**
 * Sign out of this device in two steps the caller can order and check
 * (T10-S3-07 v1.1): `clear_sign_in()` removes the token and rejects when the
 * token store fails, so the caller can say the person is still signed in;
 * `finish_sign_out()` then forgets the appearance copy and unsent drafts,
 * which belong to this account, so the next person on a shared device starts
 * from the defaults, and reloads into the sign-in screen.
 *
 * Shares waiting on the device go before the token (F-46). When they can't
 * be removed, `clear_sign_in()` rejects with PendingSharesError and the token
 * stays, so no next person signs in over this account's shared content.
 */
export async function clear_sign_in(
	tokenStore: TokenStoreEnvironment,
	pendingShares?: PendingSharesEnvironment,
): Promise<void> {
	await clear_pending_shares(pendingShares);
	await clear_token(tokenStore);
}

export function finish_sign_out(): void {
	clear_appearance_cache();
	clear_drafts();
	window.location.reload();
}

/** Both steps in order, for callers with nothing to do between them. */
export async function sign_out(tokenStore: TokenStoreEnvironment): Promise<void> {
	await clear_sign_in(tokenStore);
	finish_sign_out();
}
