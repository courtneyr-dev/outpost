import { clear_token, type TokenStoreEnvironment } from './token-store';
import { clear_cache as clear_appearance_cache } from './appearance';
import { clear_drafts } from './drafts';

/**
 * Sign out of this device: forget the token, then the appearance copy and
 * unsent drafts, which belong to this account, so the next person on a
 * shared device starts from the defaults. Reloads into the sign-in screen.
 */
export async function sign_out(tokenStore: TokenStoreEnvironment): Promise<void> {
	await clear_token(tokenStore);
	clear_appearance_cache();
	clear_drafts();
	window.location.reload();
}
