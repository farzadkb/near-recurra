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
		const names = w.RecurraWallet.wallets().map((x) => x.name);
		const missing = expected.filter((n) => !names.includes(n));
		if (missing.length || errors.length) {
			failures++;
			console.log(`FAIL ${network}: wallets [${names.join(", ")}], missing [${missing.join(", ")}], errors ${JSON.stringify(errors.slice(0, 3))}`);
		} else {
			console.log(`ok ${network}: ${names.join(", ")}`);
		}
	} catch (e) {
		failures++;
		console.log(`FAIL ${network}: ${e.message}`);
	}
	w.close();
}
process.exit(failures ? 1 : 0);
