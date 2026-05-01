/**
 * Outpost PWA entry point.
 *
 * Mounts at `#outpost-root` and renders one of three components based on the
 * current URL + token state:
 *
 *   - /post/auth/callback → AuthCallback (handles the IndieAuth code exchange)
 *   - /post/* with a stored token → ComposerPlaceholder (B0b confirmation UI)
 *   - /post/* without a stored token → LoginScreen
 *   - /post/share-target → fallback message (Phase E lands this)
 *
 * Route detection reads `location.pathname` because the PHP shell hard-codes
 * `data-outpost-route="composer"` for every shell-served path. The JS bundle
 * is the only thing that knows which path the user actually landed on.
 */

import { render } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import './styles/structure.css';
import { LoginScreen } from './components/login-screen';
import { AuthCallback } from './components/auth-callback';
import { ComposerPlaceholder } from './components/composer-placeholder';
import { read_token, type StoredToken, type TokenStoreEnvironment } from './lib/token-store';

type OutpostRoute = 'composer' | 'share-target' | 'auth-callback' | 'unknown';

export function detect_route(pathname: string): OutpostRoute {
	if (pathname.startsWith('/post/share-target')) {
		return 'share-target';
	}
	if (pathname.startsWith('/post/auth/callback')) {
		return 'auth-callback';
	}
	if (pathname === '/post/' || pathname === '/post') {
		return 'composer';
	}
	return 'unknown';
}

const COMPOSER_URL = '/post/';
const CALLBACK_URL = '/post/auth/callback';

interface AppProps {
	clientId: string;
	redirectUri: string;
	composerUrl: string;
	tokenStore: TokenStoreEnvironment;
}

function App({ clientId, redirectUri, composerUrl, tokenStore }: AppProps) {
	const route = detect_route(window.location.pathname);
	const [tokenState, setTokenState] = useState<
		{ status: 'loading' } | { status: 'present'; token: StoredToken } | { status: 'absent' }
	>({ status: 'loading' });

	useEffect(() => {
		// auth-callback handles its own state; don't read the existing token —
		// the callback is mid-exchange and the token field may briefly be stale.
		if (route !== 'composer') return;

		let cancelled = false;
		(async (): Promise<void> => {
			try {
				const token = await read_token(tokenStore);
				if (cancelled) return;
				setTokenState(token ? { status: 'present', token } : { status: 'absent' });
			} catch (_err) {
				if (cancelled) return;
				setTokenState({ status: 'absent' });
			}
		})();
		return (): void => {
			cancelled = true;
		};
	}, [route, tokenStore]);

	if (route === 'auth-callback') {
		return <AuthCallback clientId={clientId} redirectUri={redirectUri} composerUrl={composerUrl} />;
	}

	if (route === 'share-target') {
		return (
			<section class="outpost-card">
				<h1 class="outpost-card__title">Share target</h1>
				<p class="outpost-card__lede">
					Sharing into Outpost from another app lands in Phase E. For now, open the composer
					directly.
				</p>
				<a class="outpost-button outpost-button--secondary" href={composerUrl}>
					Open composer
				</a>
			</section>
		);
	}

	if (route === 'unknown') {
		return (
			<section class="outpost-card">
				<h1 class="outpost-card__title">Page not found</h1>
				<p class="outpost-card__lede">
					This URL isn't part of the Outpost composer. Try opening{' '}
					<a href={composerUrl}>{composerUrl}</a>.
				</p>
			</section>
		);
	}

	if (tokenState.status === 'loading') {
		return (
			<section class="outpost-card" aria-live="polite">
				<p class="outpost-status">Loading…</p>
			</section>
		);
	}

	if (tokenState.status === 'present') {
		return <ComposerPlaceholder token={tokenState.token} tokenStore={tokenStore} />;
	}

	return <LoginScreen clientId={clientId} redirectUri={redirectUri} />;
}

export function mount(root: Element, props: AppProps): void {
	root.classList.add('outpost-app');
	render(<App {...props} />, root);
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
	const root = document.getElementById('outpost-root');
	if (root) {
		mount(root, {
			clientId: window.location.origin + COMPOSER_URL,
			redirectUri: window.location.origin + CALLBACK_URL,
			composerUrl: COMPOSER_URL,
			tokenStore: {
				indexedDB: globalThis.indexedDB,
				crypto: globalThis.crypto,
			},
		});
	}
}
