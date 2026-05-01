import { useEffect, useState } from 'preact/hooks';
import { handle_callback, AuthFlowError, type AuthFlowEnvironment } from '../lib/auth-flow';

/**
 * Auth-callback handler component.
 *
 * Mounts when the route resolves to `auth-callback`. Reads `code` and `state`
 * from the redirect query, calls handle_callback() (which validates state,
 * exchanges code, persists token, clears sessionStorage), and on success
 * redirects the browser back to /post/ where the composer placeholder mounts.
 *
 * On error: render a message + a "Start over" button that navigates back to
 * /post/ to render the login screen again.
 */

export interface AuthCallbackProps {
	clientId: string;
	redirectUri: string;
	composerUrl: string;
	env?: AuthFlowEnvironment;
}

type CallbackState =
	| { status: 'pending' }
	| { status: 'success' }
	| { status: 'error'; message: string; code: AuthFlowError['code'] | 'unknown' };

export function AuthCallback({ clientId, redirectUri, composerUrl, env }: AuthCallbackProps) {
	const [state, setState] = useState<CallbackState>({ status: 'pending' });

	useEffect(() => {
		let cancelled = false;
		(async (): Promise<void> => {
			const params = new URLSearchParams(window.location.search);
			try {
				await handle_callback(
					{
						code: params.get('code'),
						state: params.get('state'),
						error: params.get('error'),
						error_description: params.get('error_description'),
					},
					clientId,
					redirectUri,
					env,
				);
				if (cancelled) return;
				setState({ status: 'success' });
				// Strip query params from the URL before navigating so a back-button
				// press doesn't replay the (now-consumed) authorization code.
				window.location.replace(composerUrl);
			} catch (err) {
				if (cancelled) return;
				if (err instanceof AuthFlowError) {
					setState({ status: 'error', message: err.message, code: err.code });
				} else {
					setState({
						status: 'error',
						message: err instanceof Error ? err.message : String(err),
						code: 'unknown',
					});
				}
			}
		})();
		return (): void => {
			cancelled = true;
		};
	}, [clientId, redirectUri, composerUrl, env]);

	if (state.status === 'pending') {
		return (
			<section class="outpost-card" aria-live="polite">
				<h1 class="outpost-card__title">Signing you in…</h1>
				<p class="outpost-status">Verifying your authorization. This usually takes a moment.</p>
			</section>
		);
	}

	if (state.status === 'success') {
		return (
			<section class="outpost-card" aria-live="polite">
				<h1 class="outpost-card__title">Signed in</h1>
				<p class="outpost-status">Loading the composer…</p>
			</section>
		);
	}

	return (
		<section class="outpost-card" aria-live="polite">
			<h1 class="outpost-card__title">Sign-in didn't complete</h1>
			<div class="outpost-error" role="alert">
				{state.message}
			</div>
			<a class="outpost-button outpost-button--secondary" href={composerUrl}>
				Start over
			</a>
		</section>
	);
}
