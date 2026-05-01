/**
 * Encrypted IndexedDB token storage.
 *
 * THREAT MODEL — be honest about what this does and doesn't protect.
 *
 *   Defeats:
 *     - DevTools IndexedDB inspector (token bytes stay AES-GCM ciphertext,
 *       never plaintext in the IDB row)
 *     - File-system reads of the browser profile by other apps
 *     - Casual extension-level snooping of raw IndexedDB values
 *
 *   Does NOT defeat:
 *     - Same-origin XSS (attacker JS can call read_token() and get plaintext)
 *     - Browser extensions with broad page-script permissions
 *     - A determined attacker who can run JavaScript in the same origin
 *
 * Key strategy: AES-GCM 256-bit, generated via crypto.subtle.generateKey
 * with `extractable: false`. The CryptoKey persists in IndexedDB through the
 * structured-clone algorithm; crypto.subtle.exportKey() refuses to dump raw
 * bytes for non-extractable keys, so a casual IDB inspection that finds the
 * key entry can't extract it. JS in the same origin CAN still use the key
 * (encrypt/decrypt operations don't require extractable=true), which is why
 * same-origin XSS still wins.
 *
 * Defense in depth — not strong cryptography against a sophisticated attacker.
 * The CSP work in Phase G is what actually reduces the XSS surface.
 */

const DB_NAME = 'outpost';
const DB_VERSION = 1;
const STORE_KEYS = 'crypto-keys';
const STORE_TOKENS = 'tokens';
const KEY_ID = 'token-encryption-key';
const TOKEN_ID = 'micropub';
const IV_LENGTH_BYTES = 12;

export interface StoredToken {
	accessToken: string;
	tokenType: string;
	scope: string;
	me: string;
	storedAt: number;
}

export interface TokenInput {
	accessToken: string;
	tokenType: string;
	scope: string;
	me: string;
}

interface EncryptedTokenRow {
	iv: Uint8Array;
	ciphertext: Uint8Array;
	meta: { tokenType: string; scope: string; me: string; storedAt: number };
}

export interface TokenStoreEnvironment {
	indexedDB: IDBFactory;
	crypto: Crypto;
}

const default_env: TokenStoreEnvironment = {
	indexedDB: globalThis.indexedDB,
	crypto: globalThis.crypto,
};

/**
 * Persist a token. Generates the encryption key on first call and reuses it
 * thereafter. Subsequent calls overwrite the previous token.
 */
export async function write_token(
	token: TokenInput,
	env: TokenStoreEnvironment = default_env,
): Promise<void> {
	const db = await open_db(env);
	try {
		const key = await get_or_create_key(db, env);
		const iv = env.crypto.getRandomValues(new Uint8Array(IV_LENGTH_BYTES));
		const plaintext = new TextEncoder().encode(token.accessToken);
		const ciphertext = await env.crypto.subtle.encrypt(
			{ name: 'AES-GCM', iv },
			key,
			plaintext,
		);
		const row: EncryptedTokenRow = {
			iv,
			ciphertext: new Uint8Array(ciphertext),
			meta: {
				tokenType: token.tokenType,
				scope: token.scope,
				me: token.me,
				storedAt: Date.now(),
			},
		};
		await tx_put(db, STORE_TOKENS, TOKEN_ID, row);
	} finally {
		db.close();
	}
}

/**
 * Read the persisted token. Returns null when nothing is stored or the
 * encryption key is missing (the latter shouldn't happen unless the IDB
 * was tampered with).
 */
export async function read_token(
	env: TokenStoreEnvironment = default_env,
): Promise<StoredToken | null> {
	const db = await open_db(env);
	try {
		const row = await tx_get<EncryptedTokenRow>(db, STORE_TOKENS, TOKEN_ID);
		if (!row) return null;

		const key = await tx_get<CryptoKey>(db, STORE_KEYS, KEY_ID);
		if (!key) return null;

		// .slice() coerces from Uint8Array<ArrayBufferLike> (which TS warns could
		// be SharedArrayBuffer) to a fresh Uint8Array<ArrayBuffer> that satisfies
		// SubtleCrypto's BufferSource constraint. The data is never actually
		// SharedArrayBuffer-backed; the slice is a type-system formality.
		const plaintext = await env.crypto.subtle.decrypt(
			{ name: 'AES-GCM', iv: row.iv.slice() },
			key,
			row.ciphertext.slice(),
		);
		const access_token = new TextDecoder().decode(plaintext);
		return {
			accessToken: access_token,
			tokenType: row.meta.tokenType,
			scope: row.meta.scope,
			me: row.meta.me,
			storedAt: row.meta.storedAt,
		};
	} finally {
		db.close();
	}
}

/**
 * Forget the token. Leaves the encryption key in place — re-login uses the
 * same key, which is fine because the IV randomises every ciphertext anyway.
 */
export async function clear_token(env: TokenStoreEnvironment = default_env): Promise<void> {
	const db = await open_db(env);
	try {
		await tx_delete(db, STORE_TOKENS, TOKEN_ID);
	} finally {
		db.close();
	}
}

function open_db(env: TokenStoreEnvironment): Promise<IDBDatabase> {
	return new Promise((resolve, reject) => {
		const request = env.indexedDB.open(DB_NAME, DB_VERSION);
		request.onupgradeneeded = (): void => {
			const db = request.result;
			if (!db.objectStoreNames.contains(STORE_KEYS)) {
				db.createObjectStore(STORE_KEYS);
			}
			if (!db.objectStoreNames.contains(STORE_TOKENS)) {
				db.createObjectStore(STORE_TOKENS);
			}
		};
		request.onsuccess = (): void => resolve(request.result);
		request.onerror = (): void => reject(request.error ?? new Error('open_db failed'));
	});
}

function tx_get<T>(db: IDBDatabase, store: string, key: string): Promise<T | undefined> {
	return new Promise((resolve, reject) => {
		const tx = db.transaction(store, 'readonly');
		const object_store = tx.objectStore(store);
		const request = object_store.get(key);
		request.onsuccess = (): void => resolve(request.result as T | undefined);
		request.onerror = (): void => reject(request.error ?? new Error('tx_get failed'));
	});
}

function tx_put(db: IDBDatabase, store: string, key: string, value: unknown): Promise<void> {
	return new Promise((resolve, reject) => {
		const tx = db.transaction(store, 'readwrite');
		const object_store = tx.objectStore(store);
		const request = object_store.put(value, key);
		request.onsuccess = (): void => resolve();
		request.onerror = (): void => reject(request.error ?? new Error('tx_put failed'));
	});
}

function tx_delete(db: IDBDatabase, store: string, key: string): Promise<void> {
	return new Promise((resolve, reject) => {
		const tx = db.transaction(store, 'readwrite');
		const object_store = tx.objectStore(store);
		const request = object_store.delete(key);
		request.onsuccess = (): void => resolve();
		request.onerror = (): void => reject(request.error ?? new Error('tx_delete failed'));
	});
}

async function get_or_create_key(
	db: IDBDatabase,
	env: TokenStoreEnvironment,
): Promise<CryptoKey> {
	const existing = await tx_get<CryptoKey>(db, STORE_KEYS, KEY_ID);
	if (existing) return existing;

	const key = await env.crypto.subtle.generateKey(
		{ name: 'AES-GCM', length: 256 },
		false, // non-extractable — exportKey() refuses to return raw bytes
		['encrypt', 'decrypt'],
	);
	await tx_put(db, STORE_KEYS, KEY_ID, key);
	return key;
}
