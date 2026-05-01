/**
 * IndieAuth flow orchestration.
 *
 * Two phases of one round-trip:
 *
 *   1. begin_login(me) — discover endpoints, generate PKCE verifier + state
 *      token, stash both in sessionStorage, build the authorization URL the
 *      browser navigates to.
 *
 *   2. handle_callback(query) — read code + state from the redirect, look up
 *      the saved verifier + state in sessionStorage, validate state matches
 *      (CSRF defence), exchange code for token, persist via the token store,
 *      clear sessionStorage. Returns the persisted token on success.
 *
 * Errors at any step throw — the caller decides how to surface them. The
 * sessionStorage cleanup runs in `finally` so a partial flow doesn't leave
 * orphaned state behind that would confuse a retry.
 *
 * sessionStorage was chosen deliberately:
 *   - Same-tab persistence across the redirect to the auth endpoint and back.
 *   - Cleared on tab close, so a closed-mid-flow session doesn't leave a
 *     verifier lying around in the next tab.
 *   - Not localStorage: a stale verifier from yesterday's abandoned flow
 *     shouldn't validate today's callback.
 */

import {
	discover_endpoints,
	generate_pkce,
	generate_state,
	build_authorization_url,
	exchange_code_for_token,
	type IndieAuthEnvironment,
} from './indieauth';
import { write_token, type StoredToken, type TokenStoreEnvironment } from './token-store';

const SESSION_KEY_VERIFIER = 'outpost.auth.verifier';
const SESSION_KEY_STATE = 'outpost.auth.state';
const SESSION_KEY_ME = 'outpost.auth.me';
const SESSION_KEY_TOKEN_ENDPOINT = 'outpost.auth.token_endpoint';

const DEFAULT_SCOPE = 'create update media';

export interface AuthFlowEnvironment {
	indieauth: IndieAuthEnvironment;
	tokenStore: TokenStoreEnvironment;
	sessionStorage: Storage;
}

const default_env: AuthFlowEnvironment = {
	indieauth: {
		fetch: globalThis.fetch.bind(globalThis),
		crypto: globalThis.crypto,
		random: () => globalThis.crypto.getRandomValues(new Uint8Array(32)),
	},
	tokenStore: {
		indexedDB: globalThis.indexedDB,
		crypto: globalThis.crypto,
	},
	sessionStorage:
		typeof globalThis.sessionStorage === 'undefined'
			? (undefined as unknown as Storage)
			: globalThis.sessionStorage,
};

export interface BeginLoginResult {
	authorizationUrl: string;
}

/**
 * Initiate an IndieAuth login. Returns the URL the caller should navigate
 * the browser to (typically `location.assign(result.authorizationUrl)`).
 *
 * @param me            The user's "me" URL (e.g. https://courtneyr.dev/).
 * @param client_id     The PWA's client identifier (e.g. https://courtneyr.dev/post/).
 * @param redirect_uri  Where IndieAuth will redirect back (e.g. https://courtneyr.dev/post/auth/callback).
 * @param scope         Optional scope string; defaults to "create update media".
 */
export async function begin_login(
	me: string,
	client_id: string,
	redirect_uri: string,
	scope: string = DEFAULT_SCOPE,
	env: AuthFlowEnvironment = default_env,
): Promise<BeginLoginResult> {
	const endpoints = await discover_endpoints(me, env.indieauth);
	const pkce = await generate_pkce(env.indieauth);
	const state = generate_state(env.indieauth);

	env.sessionStorage.setItem(SESSION_KEY_VERIFIER, pkce.codeVerifier);
	env.sessionStorage.setItem(SESSION_KEY_STATE, state);
	env.sessionStorage.setItem(SESSION_KEY_ME, me);
	env.sessionStorage.setItem(SESSION_KEY_TOKEN_ENDPOINT, endpoints.tokenEndpoint);

	const authorizationUrl = build_authorization_url({
		authorizationEndpoint: endpoints.authorizationEndpoint,
		clientId: client_id,
		redirectUri: redirect_uri,
		me,
		scope,
		state,
		codeChallenge: pkce.codeChallenge,
	});

	return { authorizationUrl };
}

export class AuthFlowError extends Error {
	constructor(
		message: string,
		public readonly code: 'state_mismatch' | 'missing_state' | 'exchange_failed' | 'no_code',
	) {
		super(message);
		this.name = 'AuthFlowError';
	}
}

export interface CallbackQuery {
	code?: string | null;
	state?: string | null;
	error?: string | null;
	error_description?: string | null;
}

export interface HandleCallbackResult {
	token: StoredToken;
}

/**
 * Complete an IndieAuth login that was started by `begin_login`.
 *
 * Reads `code` and `state` from the redirect query, validates state against
 * the sessionStorage value (CSRF defence), exchanges the code for a token,
 * persists the token via the token store, and clears the saved verifier.
 *
 * Throws AuthFlowError with a discriminating `code` on every failure path so
 * the UI can render a specific error.
 */
export async function handle_callback(
	query: CallbackQuery,
	client_id: string,
	redirect_uri: string,
	env: AuthFlowEnvironment = default_env,
): Promise<HandleCallbackResult> {
	const expected_state = env.sessionStorage.getItem(SESSION_KEY_STATE);
	const verifier = env.sessionStorage.getItem(SESSION_KEY_VERIFIER);
	const token_endpoint = env.sessionStorage.getItem(SESSION_KEY_TOKEN_ENDPOINT);

	try {
		if (query.error) {
			throw new AuthFlowError(
				'Authorization endpoint returned error: ' +
					query.error +
					(query.error_description ? ' — ' + query.error_description : ''),
				'exchange_failed',
			);
		}

		if (!query.code) {
			throw new AuthFlowError('Callback is missing the `code` parameter.', 'no_code');
		}

		if (!expected_state || !verifier || !token_endpoint) {
			throw new AuthFlowError(
				'No saved login state — start the login flow again.',
				'missing_state',
			);
		}

		if (query.state !== expected_state) {
			throw new AuthFlowError(
				'State token mismatch — possible CSRF or the flow was started in a different tab.',
				'state_mismatch',
			);
		}

		const response = await exchange_code_for_token(
			{
				tokenEndpoint: token_endpoint,
				code: query.code,
				clientId: client_id,
				redirectUri: redirect_uri,
				codeVerifier: verifier,
			},
			env.indieauth,
		);

		await write_token(
			{
				accessToken: response.accessToken,
				tokenType: response.tokenType,
				scope: response.scope,
				me: response.me,
			},
			env.tokenStore,
		);

		// read_token returns the StoredToken with the storedAt timestamp; we
		// reconstruct it here without an extra IDB roundtrip because the
		// caller usually wants the freshly-persisted shape immediately.
		const token: StoredToken = {
			accessToken: response.accessToken,
			tokenType: response.tokenType,
			scope: response.scope,
			me: response.me,
			storedAt: Date.now(),
		};

		return { token };
	} finally {
		// Always clear sessionStorage — partial state shouldn't survive a
		// failure and trip up the next attempt.
		env.sessionStorage.removeItem(SESSION_KEY_VERIFIER);
		env.sessionStorage.removeItem(SESSION_KEY_STATE);
		env.sessionStorage.removeItem(SESSION_KEY_ME);
		env.sessionStorage.removeItem(SESSION_KEY_TOKEN_ENDPOINT);
	}
}
