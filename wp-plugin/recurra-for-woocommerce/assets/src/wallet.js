/**
 * Recurra wallet glue: a thin layer over NEAR wallet-selector, bundled by
 * esbuild into assets/dist/recurra-wallet.js (global `RecurraWallet`).
 *
 * The plugin's page scripts only use this small API, so the wallet library
 * can change without touching them. No UI framework: the wallet picker is
 * rendered by the page script from `wallets()`.
 */
import { setupWalletSelector, actionCreators } from "@near-wallet-selector/core";
import { setupMyNearWallet } from "@near-wallet-selector/my-near-wallet";
import { setupMeteorWallet } from "@near-wallet-selector/meteor-wallet";
import { setupIntearWallet } from "@near-wallet-selector/intear-wallet";
import { setupHotWallet } from "@near-wallet-selector/hot-wallet";

let selector = null;

/**
 * @param {{network: "mainnet"|"testnet", rpcUrl?: string}} opts
 */
export async function init(opts) {
	if (selector) {
		return selector;
	}
	const modules = [setupMyNearWallet(), setupMeteorWallet(), setupIntearWallet()];
	if (opts.network === "mainnet") {
		// HOT is mainnet-only.
		modules.push(setupHotWallet());
	}
	selector = await setupWalletSelector({
		network: opts.network,
		modules,
		fallbackRpcUrls: opts.rpcUrl ? [opts.rpcUrl] : undefined,
	});
	return selector;
}

/** Wallets that can be used in this browser. */
export function wallets() {
	return selector.store
		.getState()
		.modules.filter((m) => m.metadata.available && !m.metadata.deprecated)
		.map((m) => ({ id: m.id, name: m.metadata.name, iconUrl: m.metadata.iconUrl }));
}

/** Active account id or null. */
export function accountId() {
	const accounts = selector.store.getState().accounts;
	const active = accounts.find((a) => a.active) || accounts[0];
	return active ? active.accountId : null;
}

/** Calls `cb(accountId|null)` whenever the signed-in account changes. */
export function onChange(cb) {
	selector.store.observable.subscribe(() => cb(accountId()));
}

export async function signIn(walletId) {
	const wallet = await selector.wallet(walletId);
	const accounts = await wallet.signIn({});
	return (accounts && accounts[0] && accounts[0].accountId) || accountId();
}

export async function signOut() {
	if (!selector.isSignedIn()) {
		return;
	}
	const wallet = await selector.wallet();
	await wallet.signOut();
}

/**
 * Signs and sends one function call. Amounts are strings in base units
 * (yoctoNEAR / gas units) and converted to BigInt here — never floats.
 *
 * Browser wallets may redirect to `callbackUrl` instead of resolving; the
 * page then picks up `transactionHashes` from the URL. In both cases the
 * server confirms the result with an RPC view, never from this return value.
 *
 * @returns {Promise<{hash: string|null, failed: boolean}|null>}
 */
export async function call({ receiverId, methodName, args, gas, deposit, callbackUrl }) {
	const wallet = await selector.wallet();
	const params = {
		receiverId,
		actions: [actionCreators.functionCall(methodName, args, BigInt(gas), BigInt(deposit))],
	};
	if (wallet.type === "browser" && callbackUrl) {
		params.callbackUrl = callbackUrl;
	}
	const outcome = await wallet.signAndSendTransaction(params);
	if (!outcome) {
		return null;
	}
	const hash = (outcome.transaction && outcome.transaction.hash) || (outcome.transaction_outcome && outcome.transaction_outcome.id) || null;
	const failed = !!(outcome.status && typeof outcome.status === "object" && "Failure" in outcome.status);
	return { hash, failed };
}
