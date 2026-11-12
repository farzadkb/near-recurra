/**
 * Browser stand-in for Node's `util`, aliased by the esbuild build. Only
 * `format` is needed (by generate-function, via @near-js/accounts).
 */
function format(fmt, ...args) {
	if (typeof fmt !== "string") {
		return [fmt, ...args].map(String).join(" ");
	}
	let i = 0;
	const out = fmt.replace(/%[sdifjoO%]/g, (m) => {
		if (m === "%%") {
			return "%";
		}
		if (i >= args.length) {
			return m;
		}
		const v = args[i++];
		switch (m) {
			case "%d":
			case "%i":
				return String(parseInt(v, 10));
			case "%f":
				return String(parseFloat(v));
			case "%j":
			case "%o":
			case "%O":
				try {
					return JSON.stringify(v);
				} catch (e) {
					return "[Circular]";
				}
			default:
				return String(v);
		}
	});
	return [out, ...args.slice(i).map(String)].join(" ");
}

module.exports = { format };
