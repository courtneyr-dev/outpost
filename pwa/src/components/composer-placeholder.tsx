import { clear_token, type StoredToken, type TokenStoreEnvironment } from '../lib/token-store';

/**
 * Composer placeholder for B0b.
 *
 * Phase C lands the actual composer modes (Note, Reply, Photo, Listen group,
 * Article). For B0b this confirms end-to-end auth worked — shows the user
 * which "me" URL signed in, which scope was granted, and offers a "Sign out"
 * action that clears the stored token and reloads the page back to the login
 * screen.
 */

export interface ComposerPlaceholderProps {
	token: StoredToken;
	tokenStore: TokenStoreEnvironment;
}

export function ComposerPlaceholder({ token, tokenStore }: ComposerPlaceholderProps) {
	const handle_signout = async (event: Event): Promise<void> => {
		event.preventDefault();
		await clear_token(tokenStore);
		window.location.reload();
	};

	return (
		<section class="outpost-card" aria-labelledby="outpost-composer-title">
			<h1 id="outpost-composer-title" class="outpost-card__title">
				You're signed in
			</h1>
			<p class="outpost-card__lede">
				Composer modes land in Phase C. For now this confirms the IndieAuth round-trip and token
				persistence both work end to end.
			</p>
			<dl class="outpost-status">
				<dt>Identity</dt>
				<dd>
					<code>{token.me || '—'}</code>
				</dd>
				<dt>Scope</dt>
				<dd>
					<code>{token.scope || '—'}</code>
				</dd>
			</dl>
			<button class="outpost-button outpost-button--secondary" type="button" onClick={handle_signout}>
				Sign out
			</button>
		</section>
	);
}
