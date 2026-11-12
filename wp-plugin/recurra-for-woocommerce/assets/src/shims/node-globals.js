/**
 * Browser stand-ins for Node globals, injected by the esbuild build (free
 * references in dependencies are rewritten to these exports).
 * - process: @near-js reads process.env.DEFAULT_FINALITY at load time;
 *   others feature-detect Node via process.versions.node.
 * - Buffer: @near-js/transactions (functionCall args) and MyNearWallet
 *   (transaction encoding) use Buffer.from(); `buffer` is the standard
 *   browser implementation.
 * - setImmediate: used by some stream helpers.
 */
import { Buffer } from "buffer";

export { Buffer };

export const process = {
	env: {},
	versions: {},
	browser: true,
	nextTick: (fn, ...args) => queueMicrotask(() => fn(...args)),
};

export const setImmediate = (fn, ...args) => setTimeout(fn, 0, ...args);
