/**
 * Creates the charger's key pair and stores it in charger/.env (git-ignored,
 * mode 600). Prints only the PUBLIC key and the command that registers it as a
 * function-call key limited to `charge` on the vault. The private key is never
 * printed. Refuses to overwrite an existing CHARGER_PRIVATE_KEY.
 *
 *   node src/keygen.ts <charger-account> <vault-id> <testnet|mainnet>
 */
import { chmodSync, existsSync, readFileSync, writeFileSync } from "node:fs";
import { KeyPair } from "near-api-js";

const [account, vault, network] = process.argv.slice(2);
if (!account || !vault || (network !== "testnet" && network !== "mainnet")) {
	console.error("usage: node src/keygen.ts <charger-account> <vault-id> <testnet|mainnet>");
	process.exit(2);
}
const path = new URL("../.env", import.meta.url).pathname;
const current = existsSync(path) ? readFileSync(path, "utf8") : "";
if (/^CHARGER_PRIVATE_KEY=.+/m.test(current)) {
	console.error(`${path} already has CHARGER_PRIVATE_KEY; not overwriting.`);
	process.exit(1);
}
const key = KeyPair.fromRandom("ed25519");
const lines = [
	`NEAR_NETWORK=${network}`,
	`VAULT_ID=${vault}`,
	`CHARGER_ACCOUNT=${account}`,
	`CHARGER_PRIVATE_KEY=${key.toString()}`,
	"INTERVAL_SEC=60",
	"",
];
writeFileSync(path, lines.join("\n"), { mode: 0o600 });
chmodSync(path, 0o600);
const pub = key.getPublicKey().toString();
console.log(`wrote ${path} (mode 600)`);
console.log(`public key: ${pub}`);
console.log("register it (signed by the charger account; the key can only call `charge` on the vault):");
console.log(
	`near account add-key ${account} grant-function-call-access --allowance '0.25 NEAR' --contract-account-id ${vault} --function-names charge use-manually-provided-public-key ${pub} network-config ${network} sign-with-...`,
);
