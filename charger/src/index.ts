/**
 * Recurra charger: every INTERVAL_SEC, asks the vault which subscriptions are
 * due (`get_due`) and calls `charge(sub_id)` for each.
 *
 * `charge` is permissionless and the contract enforces every rule (amount,
 * period, recipient), so this process holds no power over customer funds. It
 * signs with a function-call access key that can only call `charge` on the
 * vault (see README → Charger). Runs on Node >= 22.18 (native TypeScript).
 *
 *   node src/index.ts          # loop
 *   node src/index.ts --once   # one pass, then exit (demo / cron)
 */
import { readFileSync } from "node:fs";
import { Account, JsonRpcProvider, getTransactionLastResult } from "near-api-js";

type Env = Record<string, string | undefined>;

/** Loads ./.env (KEY=VALUE lines) without overriding real env vars. */
function loadDotEnv(path: string): void {
	let text = "";
	try {
		text = readFileSync(path, "utf8");
	} catch {
		return;
	}
	for (const line of text.split(/\r?\n/)) {
		const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/);
		if (m && process.env[m[1]] === undefined) {
			process.env[m[1]] = m[2].replace(/^["']|["']$/g, "");
		}
	}
}

function required(env: Env, key: string): string {
	const v = env[key];
	if (!v) {
		console.error(`missing ${key} (see charger/.env.example)`);
		process.exit(2);
	}
	return v;
}

loadDotEnv(new URL("../.env", import.meta.url).pathname);
const env = process.env;
const NETWORK = env.NEAR_NETWORK === "mainnet" ? "mainnet" : "testnet";
const RPC_URL = env.RPC_URL || (NETWORK === "mainnet" ? "https://rpc.mainnet.fastnear.com" : "https://rpc.testnet.fastnear.com");
const VAULT_ID = required(env, "VAULT_ID");
const CHARGER_ACCOUNT = required(env, "CHARGER_ACCOUNT");
const CHARGER_PRIVATE_KEY = required(env, "CHARGER_PRIVATE_KEY");
const INTERVAL_SEC = Math.max(10, Number(env.INTERVAL_SEC || 60));
const BATCH = 50;
const CHARGE_GAS = 50_000_000_000_000n; // 50 TGas: the vault refuses charges with < 30 TGas left.
const ONCE = process.argv.includes("--once");
const EXPLORER = NETWORK === "mainnet" ? "https://nearblocks.io" : "https://testnet.nearblocks.io";

const provider = new JsonRpcProvider({ url: RPC_URL });
const account = new Account(CHARGER_ACCOUNT, provider, CHARGER_PRIVATE_KEY as `ed25519:${string}`);

function log(msg: string): void {
	console.log(`${new Date().toISOString()} ${msg}`);
}

async function dueIds(): Promise<number[]> {
	const ids: number[] = [];
	for (let from = 0; ; from += BATCH) {
		const page = await provider.callFunction<number[]>({
			contractId: VAULT_ID,
			method: "get_due",
			args: { from_index: from, limit: BATCH },
		});
		ids.push(...(page ?? []));
		const total = await provider.callFunction<number>({ contractId: VAULT_ID, method: "get_num_subscriptions", args: {} });
		if (from + BATCH >= Number(total ?? 0)) {
			return ids;
		}
	}
}

function errorText(e: unknown): string {
	const s = e instanceof Error ? e.message : String(e);
	const m = s.match(/Smart contract panicked: ([^"\n]+)/);
	return m ? m[1] : s.slice(0, 200);
}

/** One pass. Returns the number of RPC-level errors (for backoff). */
async function pass(): Promise<number> {
	const ids = await dueIds();
	if (ids.length === 0) {
		log("nothing due");
		return 0;
	}
	let errors = 0;
	for (const id of ids) {
		try {
			const res = await account.callFunctionRaw({
				contractId: VAULT_ID,
				methodName: "charge",
				args: { sub_id: id },
				gas: CHARGE_GAS,
				deposit: 0n,
			});
			const hash = res.transaction.hash;
			const charged = getTransactionLastResult(res);
			// `true`: transfer sent (the callback re-credits if it fails); `false`: balance too low → PastDue.
			log(`sub ${id}: ${charged === true ? "charged" : charged === false ? "insufficient balance → PastDue" : "result " + JSON.stringify(charged)} ${EXPLORER}/txns/${hash}`);
		} catch (e) {
			const text = errorText(e);
			// Contract refusals (not due, in flight, cancelled) are normal races, not RPC failures.
			if (/not due yet|in flight|cancelled|max charges/.test(text)) {
				log(`sub ${id}: skipped (${text})`);
			} else {
				errors++;
				log(`sub ${id}: ERROR ${text}`);
			}
		}
	}
	return errors;
}

async function main(): Promise<void> {
	log(`charger ${CHARGER_ACCOUNT} → ${VAULT_ID} on ${NETWORK} (${RPC_URL})${ONCE ? ", once" : `, every ${INTERVAL_SEC}s`}`);
	let backoff = 0;
	for (;;) {
		let errors = 0;
		try {
			errors = await pass();
		} catch (e) {
			errors = 1;
			log(`RPC error: ${errorText(e)}`);
		}
		if (ONCE) {
			process.exit(errors ? 1 : 0);
		}
		backoff = errors ? Math.min(backoff ? backoff * 2 : INTERVAL_SEC, 600) : 0;
		await new Promise((r) => setTimeout(r, (backoff || INTERVAL_SEC) * 1000));
	}
}

main();
