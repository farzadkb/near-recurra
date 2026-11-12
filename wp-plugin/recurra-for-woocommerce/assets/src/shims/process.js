/**
 * Browser stand-in for Node's `process`, injected by the esbuild build.
 * Dependencies read `process.env.DEFAULT_FINALITY` (@near-js) and feature-detect
 * Node via `process.versions.node` — both must work without throwing.
 */
export const process = {
	env: {},
	versions: {},
	browser: true,
	nextTick: (fn, ...args) => queueMicrotask(() => fn(...args)),
};
