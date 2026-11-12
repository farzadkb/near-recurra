// Smoke test for the built wallet bundle in a simulated browser (jsdom):
// it must load without Node globals and list wallets for both networks.
//   npm run test:bundle
import { JSDOM, VirtualConsole } from "jsdom";
import { readFileSync } from "node:fs";

const bundle = readFileSync(new URL("../assets/dist/recurra-wallet.js", import.meta.url), "utf8");
let failures = 0;
for (const [network, expected] of [["testnet", ["MyNearWallet", "Meteor Wallet", "Intear Wallet"]], ["mainnet", ["MyNearWallet", "Meteor Wallet", "Intear Wallet", "HOT Wallet"]]]) {
	const errors = [];
	const vc = new VirtualConsole();
	vc.on("jsdomError", (e) => errors.push(e.message));
	const dom = new JSDOM("<!doctype html><body></body>", { url: "http://localhost:8080/", runScripts: "outside-only", pretendToBeVisual: true, virtualConsole: vc });
	const w = dom.window;
	w.fetch = fetch;
	w.TextEncoder = TextEncoder;
	w.TextDecoder = TextDecoder;
	try {
		w.eval(bundle);
		await w.RecurraWallet.init({ network });
		// The exact action the Subscribe button sends (Buffer is needed here).
		const msg = JSON.stringify({ subscribe: { plan_id: 2, owner: "alice.testnet", external_ref: "wc:x:1:abc", charge_now: true } });
		const action = w.RecurraWallet.buildAction("ft_transfer_call", { receiver_id: "vault.testnet", amount: "3000000", msg }, "100000000000000", "1");
		const fc = action.functionCall;
		const decoded = JSON.parse(new TextDecoder().decode(fc.args));
		if (fc.methodName !== "ft_transfer_call" || decoded.amount !== "3000000" || decoded.msg !== msg || String(fc.gas) !== "100000000000000" || String(fc.deposit) !== "1") {
			throw new Error("buildAction produced an unexpected action");
		}
		const names = w.RecurraWallet.wallets().map((x) => x.name);
		const missing = expected.filter((n) => !names.includes(n));
		if (missing.length || errors.length) {
			failures++;
			console.log(`FAIL ${network}: wallets [${names.join(", ")}], missing [${missing.join(", ")}], errors ${JSON.stringify(errors.slice(0, 3))}`);
		} else {
			console.log(`ok ${network}: ${names.join(", ")}; ft_transfer_call action built`);
		}
	} catch (e) {
		failures++;
		console.log(`FAIL ${network}: ${e.message}`);
	}
	w.close();
}
process.exit(failures ? 1 : 0);
