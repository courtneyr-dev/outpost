import { useState } from 'preact/hooks';
import { begin_login, type AuthFlowEnvironment } from '../lib/auth-flow';

/**
 * Login screen component.
 *
 * Single form: a "me" URL field defaulting to the current origin (because
 * Outpost typically runs on the user's own domain), and a single submit
 * button that initiates the IndieAuth flow. Same-tab redirect, no popup.
 *
 * On submit:
 *   1. discover the user's IndieAuth endpoints
 *   2. generate PKCE + state, save to sessionStorage
 *   3. build the authorization URL
 *   4. navigate the browser to it
 *
 * Errors during discovery (most common: non-IndieWeb-shaped homepage) get
 * surfaced inline so the user can correct and retry without leaving the page.
 */

export interface LoginScreenProps {
	/** PWA's client identifier — typically `${origin}/post/`. */
	clientId: string;
	/** Callback URL the auth endpoint redirects back to. */
	redirectUri: string;
	/** Pre-filled value for the "me" URL field. Defaults to clientId's origin. */
	defaultMe?: string;
	/** Optional environment override for tests. */
	env?: AuthFlowEnvironment;
}

export function LoginScreen({ clientId, redirectUri, defaultMe, env }: LoginScreenProps) {
	const initial_me = defaultMe ?? new URL(clientId).origin + '/';
	const [me, setMe] = useState(initial_me);
	const [error, setError] = useState<string | null>(null);
	const [pending, setPending] = useState(false);

	const handle_submit = async (event: Event): Promise<void> => {
		event.preventDefault();
		if (pending) return;
		setError(null);
		setPending(true);
		try {
			const result = await begin_login(me, clientId, redirectUri, undefined, env);
			window.location.assign(result.authorizationUrl);
		} catch (err) {
			setPending(false);
			setError(err instanceof Error ? err.message : String(err));
		}
	};

	return (
		<section class="outpost-card" aria-labelledby="outpost-login-title">
			<h1 id="outpost-login-title" class="outpost-card__title">
				Sign in to Outpost
			</h1>
			<p class="outpost-card__lede">
				Outpost posts to your site through Micropub. Sign in with your domain to authorize this
				device.
			</p>
			<form class="outpost-form-row" onSubmit={handle_submit} novalidate>
				<label class="outpost-label" for="outpost-me-input">
					Your site
				</label>
				<input
					id="outpost-me-input"
					class="outpost-input"
					type="url"
					name="me"
					value={me}
					onInput={(event): void => setMe((event.target as HTMLInputElement).value)}
					required
					inputMode="url"
					autoComplete="url"
					autoCapitalize="none"
					spellcheck={false}
				/>
				{error !== null && (
					<div class="outpost-error" role="alert">
						{error}
					</div>
				)}
				<button class="outpost-button" type="submit" disabled={pending}>
					{pending ? 'Signing in…' : 'Sign in'}
				</button>
			</form>
		</section>
	);
}
