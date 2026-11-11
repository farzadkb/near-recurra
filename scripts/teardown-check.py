#!/usr/bin/env python3
"""Read-only pre-flight check before tearing down a Recurra Vault.

Lists every subscription and plan, the vault's token balance and the
contract's own `total_held`, and exits 0 only when the vault is safe to
unregister from the token and delete. It never signs anything.

Usage:
  python3 scripts/teardown-check.py recurra-vault.near --merchant recurra-shop.near
  python3 scripts/teardown-check.py vault-v2.recurra-farzad.testnet \
      --token musdc.recurra-farzad.testnet --rpc https://rpc.testnet.fastnear.com

See scripts/teardown.md for the full procedure.
"""

import argparse
import base64
import json
import sys
import urllib.request

USDC = "17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1"
RPC = {
    "mainnet": "https://rpc.mainnet.fastnear.com",
    "testnet": "https://rpc.testnet.fastnear.com",
}


def view(rpc, contract, method, args):
    body = {
        "jsonrpc": "2.0",
        "id": "recurra-teardown",
        "method": "query",
        "params": {
            "request_type": "call_function",
            "finality": "final",
            "account_id": contract,
            "method_name": method,
            "args_base64": base64.b64encode(json.dumps(args).encode()).decode(),
        },
    }
    req = urllib.request.Request(
        rpc,
        data=json.dumps(body).encode(),
        headers={"Content-Type": "application/json", "User-Agent": "recurra-teardown-check"},
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        out = json.load(resp)
    if "error" in out:
        raise RuntimeError(f"{contract}.{method}: {out['error']}")
    result = out["result"]
    if "error" in result:
        raise RuntimeError(f"{contract}.{method}: {result['error']}")
    return json.loads(bytes(result["result"]).decode() or "null")


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("vault")
    ap.add_argument("--token", default=USDC, help="NEP-141 token the vault holds (default: native USDC)")
    ap.add_argument("--rpc", help="RPC URL (default: FastNEAR for the vault's network)")
    ap.add_argument("--merchant", action="append", default=[], help="also list this merchant's plans (repeatable)")
    a = ap.parse_args()
    network = "testnet" if a.vault.endswith(".testnet") else "mainnet"
    rpc = a.rpc or RPC[network]
    blockers = []

    n = view(rpc, a.vault, "get_num_subscriptions", {})
    print(f"vault {a.vault} ({network}), {n} subscription(s)")
    merchants = set(a.merchant)
    for sub_id in range(1, n + 1):
        s = view(rpc, a.vault, "get_subscription", {"sub_id": sub_id})
        if s is None:
            continue
        merchants.add(s["merchant"])
        flags = []
        if s["status"] != "Cancelled":
            flags.append("NOT CANCELLED")
        if int(s["balance"]) != 0:
            flags.append("BALANCE > 0")
        if s["in_flight"] is not None:
            flags.append("CHARGE IN FLIGHT")
        print(
            f"  sub {sub_id}: {s['status']:<9} owner={s['owner']} merchant={s['merchant']} "
            f"token={s['token']} balance={s['balance']} charges={s['charges_count']}"
            + (f"  <-- {', '.join(flags)}" if flags else "")
        )
        if flags:
            blockers.append(f"sub {sub_id}: {', '.join(flags)}")

    for m in sorted(merchants):
        for plan_id, p in view(rpc, a.vault, "get_plans", {"merchant": m}):
            state = "active" if p["active"] else "inactive"
            print(f"  plan {plan_id}: {state:<8} merchant={m} {p['amount']} every {p['period_sec']}s  {p['name']!r}")

    held = view(rpc, a.vault, "get_total_held", {"token": a.token})
    bal = view(rpc, a.token, "ft_balance_of", {"account_id": a.vault})
    reg = view(rpc, a.token, "storage_balance_of", {"account_id": a.vault})
    print(f"total_held({a.token}) = {held}")
    print(f"ft_balance_of({a.vault}) = {bal}")
    print(f"storage_balance_of({a.vault}) = {json.dumps(reg)}")
    if int(held) != 0:
        blockers.append(f"total_held = {held} (customer funds still in subscriptions)")
    if int(bal) != 0:
        blockers.append(f"vault token balance = {bal}; storage_unregister would fail and delete-account would strand these tokens")

    if blockers:
        print("\nNOT SAFE to tear down:")
        for b in blockers:
            print(f"  - {b}")
        return 1
    print("\nOK: no customer funds and no token balance. Safe to storage_unregister"
          + (" (already unregistered)" if reg is None else "") + " and delete the account (see scripts/teardown.md).")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (RuntimeError, OSError) as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(2)
