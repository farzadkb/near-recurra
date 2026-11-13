#!/usr/bin/env bash
# Recurra demo balances (mainnet, READ-ONLY: public RPC view calls, no keys).
#
#   scripts/balances.sh                 # balances + change since the previous run
#   scripts/balances.sh 1               # … plus subscription #1 on the vault
#   scripts/balances.sh 1 --watch 10    # refresh every 10 s (change since the watch started)
#   scripts/balances.sh --reset         # forget the stored snapshot
#
# Accounts can be overridden with env vars: VAULT, MERCHANT, CUSTOMER, CUSTOMER_EVM,
# NEAR_RPC, BASE_RPC. NO_COLOR=1 disables colours. The last snapshot is kept in
# scripts/.balances-snapshot.json (git-ignored).
set -euo pipefail
exec python3 -I - "$(cd "$(dirname "$0")" && pwd)" "$@" <<'PY'
import base64, json, os, sys, time, urllib.request
from datetime import datetime, timezone
from zoneinfo import ZoneInfo

HERE = sys.argv[1]
ARGS = sys.argv[2:]
USDC_NEAR = "17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1"
USDC_BASE = "0x833589fcd6edb6e08f4c7c32d4f71b54bda02913"
VAULT = os.environ.get("VAULT", "recurra-vault.near")
MERCHANT = os.environ.get("MERCHANT", "recurra-shop.near")
CUSTOMER = os.environ.get("CUSTOMER", "customer_main.near")
CUSTOMER_EVM = os.environ.get("CUSTOMER_EVM", "0x39Fb3fe39fc1e0cEBBcd201c583605b64E30C60f")
NEAR_RPC = os.environ.get("NEAR_RPC", "https://rpc.mainnet.fastnear.com")
BASE_RPC = os.environ.get("BASE_RPC", "https://mainnet.base.org")
SNAPSHOT = os.path.join(HERE, ".balances-snapshot.json")
TEHRAN = ZoneInfo("Asia/Tehran")
WIDTH = 78

COLOR = sys.stdout.isatty() and not os.environ.get("NO_COLOR")
def c(code, s):
    return f"\033[{code}m{s}\033[0m" if COLOR else s
green = lambda s: c("32", s)
red = lambda s: c("31", s)
dim = lambda s: c("2", s)
bold = lambda s: c("1", s)


def usage(msg=""):
    if msg:
        print(msg, file=sys.stderr)
    print("usage: scripts/balances.sh [sub_id] [--watch N] [--reset]", file=sys.stderr)
    sys.exit(2)


sub_id, watch, reset = None, 0, False
i = 0
while i < len(ARGS):
    a = ARGS[i]
    if a == "--reset":
        reset = True
    elif a == "--watch":
        i += 1
        if i >= len(ARGS) or not ARGS[i].isdigit() or int(ARGS[i]) < 2:
            usage("--watch needs a number of seconds (>= 2)")
        watch = int(ARGS[i])
    elif a.isdigit():
        sub_id = int(a)
    elif a in ("-h", "--help"):
        usage()
    else:
        usage(f"unknown argument: {a}")
    i += 1


def post(url, body):
    req = urllib.request.Request(url, data=json.dumps(body).encode(),
                                 headers={"Content-Type": "application/json", "User-Agent": "recurra-balances"})
    with urllib.request.urlopen(req, timeout=20) as r:
        out = json.load(r)
    if "error" in out:
        raise RuntimeError(str(out["error"])[:120])
    return out["result"]


def near_view(contract, method, args):
    res = post(NEAR_RPC, {"jsonrpc": "2.0", "id": "b", "method": "query", "params": {
        "request_type": "call_function", "finality": "final", "account_id": contract,
        "method_name": method, "args_base64": base64.b64encode(json.dumps(args).encode()).decode()}})
    if "error" in res:
        raise RuntimeError(str(res["error"])[:120])
    raw = bytes(res["result"]).decode()
    return json.loads(raw) if raw else None


def base_usdc(addr):
    data = "0x70a08231" + addr.lower().replace("0x", "").rjust(64, "0")
    return str(int(post(BASE_RPC, {"jsonrpc": "2.0", "id": 1, "method": "eth_call",
                                   "params": [{"to": USDC_BASE, "data": data}, "latest"]}), 16))


def fmt(base, sign=False):
    """Base units (6 decimals) → '1.50', '0.500103'; 2–6 decimals, integer math only."""
    n = int(base)
    neg = n < 0
    n = abs(n)
    whole, frac = divmod(n, 10**6)
    f = f"{frac:06d}".rstrip("0")
    f = (f + "00")[:max(2, len(f))]
    s = f"{whole:,}.{f}"
    if sign:
        s = ("−" if neg else "+") + s
    elif neg:
        s = "−" + s
    return s


def short(acct, n=28):
    if acct.startswith("0x") and len(acct) == 42:
        return acct[:8] + "…" + acct[-6:]
    return acct if len(acct) <= n else acct[:12] + "…" + acct[-(n - 13):]


def when(ts):
    u = datetime.fromtimestamp(ts, timezone.utc)
    return u.strftime("%Y-%m-%d %H:%M:%S UTC"), u.astimezone(TEHRAN).strftime("%H:%M:%S Tehran")


def until(ts):
    d = int(ts - time.time())
    if d <= 0:
        return "due now"
    h, rem = divmod(d, 3600)
    m, s = divmod(rem, 60)
    return f"in {h}h {m}m" if h else f"in {m}m {s:02d}s"


def load_snapshot():
    try:
        with open(SNAPSHOT) as f:
            return json.load(f)
    except (OSError, ValueError):
        return None


def save_snapshot(values):
    tmp = SNAPSHOT + ".tmp"
    with open(tmp, "w") as f:
        json.dump({"at": int(time.time()), "values": values}, f)
    os.replace(tmp, SNAPSHOT)


ROWS = [
    ("vault", "Vault", VAULT, "NEAR", lambda: near_view(USDC_NEAR, "ft_balance_of", {"account_id": VAULT})),
    ("merchant", "Merchant", MERCHANT, "NEAR", lambda: near_view(USDC_NEAR, "ft_balance_of", {"account_id": MERCHANT})),
    ("customer", "Customer", CUSTOMER, "NEAR", lambda: near_view(USDC_NEAR, "ft_balance_of", {"account_id": CUSTOMER})),
    ("customer_evm", "Customer", CUSTOMER_EVM, "Base", lambda: base_usdc(CUSTOMER_EVM)),
    ("total_held", "In subs", "vault total_held", "NEAR", lambda: near_view(VAULT, "get_total_held", {"token": USDC_NEAR})),
]


def render(baseline):
    now = time.time()
    values = {}
    u, t = when(now)
    line = "─" * WIDTH
    out = [bold("Recurra balances · mainnet · USDC") + " " * (WIDTH - 33 - len(u)) + u,
           " " * (WIDTH - len(t)) + dim(t)]
    if baseline:
        bu, bt = when(baseline["at"])
        note = f"change since {bu[11:]} ({bt})"
        out.append(" " * (WIDTH - len(note)) + dim(note))
    out.append(line)
    out.append(dim(f"{'':<10} {'Account':<28} {'Chain':<5} {'Balance':>14} {'Change':>16}"))
    for key, label, acct, chain, fn in ROWS:
        if key == "total_held":
            out.append(dim("·" * WIDTH))
        try:
            v = str(int(fn() or 0))
            values[key] = v
            bal = fmt(v)
            prev = (baseline or {}).get("values", {}).get(key)
            if prev is None:
                ch = ""
            else:
                d = int(v) - int(prev)
                ch = green(f"{fmt(d, True):>16}") if d > 0 else red(f"{fmt(d, True):>16}") if d < 0 else dim(f"{'·':>16}")
        except Exception as e:  # noqa: BLE001 — show the row, keep going
            bal, ch = "n/a", red(f"{'rpc error':>16}")
            print(dim(f"  ({acct}: {e})"), file=sys.stderr)
        out.append(f"{label:<10} {short(acct, 28):<28} {chain:<5} {bal:>14} {ch}")
    if sub_id is not None:
        out.append(line)
        try:
            s = near_view(VAULT, "get_subscription", {"sub_id": sub_id})
        except Exception as e:  # noqa: BLE001
            s, err = None, str(e)
        else:
            err = "not found"
        if not s:
            out.append(f"Subscription #{sub_id}: {red(err)}")
        else:
            st = s["status"]
            stc = green(st) if st == "Active" else red(st) if st == "PastDue" else dim(st)
            maxc = s.get("max_charges")
            out.append(f"{bold('Subscription #' + str(sub_id))}  {stc}   balance {fmt(s['balance'])} USDC"
                       f"   charges {s['charges_count']}{'/' + str(maxc) if maxc else ''}"
                       f"   {fmt(s['amount'])} per {int(s['period_ns']) // 10**9}s")
            if st != "Cancelled":
                ts = int(s["next_charge_at_ns"]) // 10**9
                nu, nt = when(ts)
                out.append(f"  next charge  {nu} · {nt} ({until(ts)})")
            if s.get("in_flight"):
                out.append(f"  {red('charge #' + str(s['in_flight']['charge_number']) + ' in flight')}")
            out.append(f"  owner {s['owner']}  →  merchant {s['merchant']}")
    out.append(line)
    return "\n".join(out), values


if reset:
    try:
        os.remove(SNAPSHOT)
        print("snapshot cleared")
    except FileNotFoundError:
        print("no snapshot")
    if sub_id is None and not watch:
        sys.exit(0)

baseline = load_snapshot()
try:
    while True:
        text, values = render(baseline)
        if watch:
            print("\033[2J\033[H" if sys.stdout.isatty() else "", end="")
        print(text)
        if values:
            save_snapshot(values)
        if not watch:
            break
        if baseline is None and values:
            baseline = {"at": int(time.time()), "values": values}
        print(dim(f"refreshing every {watch}s · Ctrl+C to stop"))
        time.sleep(watch)
except KeyboardInterrupt:
    print()
PY
