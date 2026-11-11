# Recurra — WooCommerce payments + recurring billing on NEAR

> Execution plan for the NEAR Hackathon (Nov 2026). Written to be handed to Claude Code as `docs/PLAN.md`.
> Working name: **Recurra** (rename freely — search/replace `recurra`).

---

## 0. Hard facts (verified 2026-11-11)

| Item                          | Value                                                                                                                                                      |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Submission deadline           | **2026-11-14 23:59 UTC = Sat 2026-11-15 03:29 Tehran**                                                                                               |
| Internal deadline (submit by) | **Fri 2026-11-14 23:00 Tehran** (4.5h margin)                                                                                                        |
| Required                      | Public repo (+ LICENSE), X demo post (video) tagging `@NEARProtocol` + `#NEARHackathon`, mainnet NEAR account for payout                               |
| Submission                    | Form at nearhackathon.com/#apply or `POST https://nearhackathon.com/api/applications` — **one attempt per repo**, fixes only via /request-changes |
| Screening                     | Claude Code + Codex check reproducibility & features first, humans decide → README must be machine-followable                                             |
| Pre-existing code             | Allowed from 2026-10-19; older own code = baseline, disclose in README                                                                                     |
| Tracks to claim               | `realworld` (primary), `business`, `security`                                                                                                        |
| Rules source                  | https://nearhackathon.com/HACKATHON.md — re-read before submitting                                                                                        |

### 1Click Swap API (NEAR Intents) — what we use

- Base: `https://1click.chaindefuser.com`
- `GET /v0/tokens` → `[{assetId, decimals, blockchain, symbol, price, contractAddress}]`
- `POST /v0/quote` → body below; response `{correlationId, timestamp, signature, quoteRequest, quote:{depositAddress, depositMemo?, amountIn, amountInFormatted, amountInUsd, minAmountIn, amountOut, amountOutFormatted, minAmountOut, deadline, timeWhenInactive, timeEstimate, refundFee?, withdrawFee?}}`
- `POST /v0/deposit/submit` `{depositAddress, txHash}` (optional speed-up)
- `GET /v0/status?depositAddress=…` → `{status, updatedAt, quoteResponse, swapDetails:{amountIn, amountOut, amountOutFormatted, nearTxHashes[], originChainTxHashes[], destinationChainTxHashes[], refundedAmount, refundReason, depositedAmount, …}}`
- Statuses: `PENDING_DEPOSIT`, `KNOWN_DEPOSIT_TX`, `PROCESSING`, `SUCCESS`, `INCOMPLETE_DEPOSIT`, `REFUNDED`, `FAILED`
- Auth optional: header `X-API-Key` (JWT is legacy). **Without key: +0.25% fee** (HACKATHON.md says 0.2%; docs say 0.25% — state 0.25% in README). Key from partners.near-intents.org.
- **No testnet.** Mainnet only, tiny amounts; `dry: true` returns a quote without deposit address.
- Swap minimums (ORIGIN_CHAIN→DESTINATION_CHAIN): **Tron origin ≥ $100, BNB Chain ≥ $1,000**, all others none (bridge may still reject: message starts `Amount is too low for bridge`).
- `depositMode: MEMO` chains (XRP, Stellar, TON…) need `depositMemo` → **exclude in MVP**.
- `customRecipientMsg` (HIGHLY EXPERIMENTAL): makes 1Click withdraw with `ft_transfer_call(msg)` instead of `ft_transfer` → funds lost if receiver mishandles. **Stretch only.**

### NEAR constants

- USDC (native, Circle) on NEAR: contract `17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1`, 6 decimals
  → 1Click assetId `nep141:17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1`
  (verify against `/v0/tokens` at startup; do not hardcode blindly)
- Any account receiving USDC must be storage-registered on the USDC contract (`storage_deposit`, ~0.00125 NEAR).

---

## 1. Product

**One-liner:** *Crypto can't do subscriptions. Recurra lets any WooCommerce store accept any token on any chain and bill customers monthly — under a spending rule the customer signs and a NEAR contract enforces.*

### Feature A — Checkout (table stakes)

Customer pays an order with any supported token/chain → merchant receives **exactly** the invoice amount in USDC on NEAR (`EXACT_OUTPUT`). No gateway account, no custodial balance, refunds automatic to customer's refund address.

### Feature B — Subscriptions (the differentiator)

Customer deposits USDC into the **Recurra Vault** contract with a rule: *"max X USDC, at most once per N days, only to merchant M, cancel anytime."* Anyone can trigger `charge()`; the contract only lets money move per the rule. Merchant's server holds **no key that can move customer funds beyond the rule**.

### Honest claims (put in README)

- ✅ "No intermediary holds the merchant's balance." ❌ Not "fully non-custodial": 1Click briefly controls the deposit address during the swap.
- ✅ Subscription funds are held by an open-source contract; limits enforced on-chain.
- ⚠️ Customer needs a NEAR account for subscriptions (any wallet; implicit accounts OK).

### Out of scope (say so)

Non-USD store currencies, MEMO chains, WooCommerce Subscriptions plugin integration (paid plugin; we ship our own minimal subscription model), fiat.

---

## 2. Architecture

```
 Customer browser                WordPress + WooCommerce (plugin)            External
 ────────────────                ────────────────────────────────            ────────
 Pay page (JS) ──poll──▶ REST /recurra/v1/orders/{id}/status ──▶ 1Click /v0/status
   │                         │  Gateway::process_payment ───────▶ 1Click /v0/quote
   │                         │  Action Scheduler poller (60s) ──▶ 1Click /v0/status
   │                         │  NEAR RPC view calls ────────────▶ NEAR RPC (vault views,
   │                         │                                     storage_balance_of)
   └─ wallet-selector ──ft_transfer_call──▶ USDC contract ──▶ Recurra Vault (Rust)
                                                                   ▲
 Charger (Node, cron) ── get_due() / charge(sub_id) ───────────────┘  (function-call key,
                                                                       limited to `charge`)
```

### Repo layout

```
recurra/
├── README.md                # judge-facing, "do X → see Y"
├── LICENSE                  # MIT
├── docs/PLAN.md             # this file
├── docs/ARCHITECTURE.md
├── contracts/
│   ├── vault/               # Rust, near-sdk 5.x, cargo-near
│   │   ├── src/lib.rs
│   │   ├── src/events.rs
│   │   └── tests/integration.rs   # near-workspaces sandbox
│   └── mock-ft/             # NEP-141 via near-contract-standards, tests only
├── wp-plugin/recurra-for-woocommerce/
│   ├── recurra-for-woocommerce.php
│   ├── includes/
│   │   ├── class-recurra-gateway.php
│   │   ├── class-oneclick-client.php
│   │   ├── class-oneclick-mock.php     # deterministic mock for judges
│   │   ├── class-near-rpc.php
│   │   ├── class-rest.php
│   │   ├── class-poller.php
│   │   ├── class-subscriptions.php
│   │   └── class-admin-health.php
│   ├── templates/pay-page.php
│   ├── templates/subscribe-page.php
│   ├── assets/src/*.ts      # wallet-selector glue
│   └── assets/dist/         # built bundle, COMMITTED (no build step for judges)
├── charger/                 # Node 20 + TypeScript
│   ├── src/index.ts
│   └── .env.example
└── docker/
    ├── docker-compose.yml   # wordpress, mariadb, wp-cli init, charger
    └── wp-init.sh           # install Woo, activate plugin, demo products, mock mode
```

---

## 3. Contract: Recurra Vault

Rust, `near-sdk` 5.x, built with `cargo near build` (reproducible build), tests with `near-workspaces`. One shared contract, multi-merchant.

### State

```rust
pub struct Contract {
    owner: AccountId,
    plans: IterableMap<u32, Plan>,
    subs: IterableMap<u64, Subscription>,
    subs_by_owner: LookupMap<AccountId, IterableSet<u64>>,
    sub_by_ref: LookupMap<String /* merchant|external_ref */, u64>,
    merchant_storage: LookupMap<AccountId, NearToken>, // prepaid storage pool
    next_plan_id: u32,
    next_sub_id: u64,
}

pub struct Plan {
    merchant: AccountId,
    token: AccountId,        // NEP-141 contract (USDC)
    amount: U128,            // per period, base units
    period_sec: u32,         // e.g. 2_592_000 (30d); allow 300 for demo plans
    name: String,            // <= 64 chars
    active: bool,            // inactive = no new subs; existing continue
}

pub enum SubStatus { Active, PastDue, Cancelled }

pub struct Subscription {
    id: u64,
    plan_id: u32,
    owner: AccountId,        // can cancel + receives refunds
    merchant: AccountId,     // SNAPSHOT from plan
    token: AccountId,        // SNAPSHOT
    amount: U128,            // SNAPSHOT — merchant can never raise price on existing subs
    period_ns: u64,          // SNAPSHOT
    balance: U128,
    next_charge_at_ns: u64,
    charges_count: u32,
    max_charges: Option<u32>,
    status: SubStatus,
    external_ref: String,    // e.g. "wc:<site-hash>:<order_id>", <= 96 chars
    created_at_ns: u64,
}
```

### Methods

| Method                                                             | Caller                                           | Behaviour                                                                                                             |
| ------------------------------------------------------------------ | ------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------- |
| `new(owner)`                                                     | deploy                                           | init                                                                                                                  |
| `create_plan(token, amount, period_sec, name) -> u32`            | merchant, **payable**                      | attached deposit credited to `merchant_storage[merchant]`; plan storage cost deducted. Panics if pool insufficient. |
| `deposit_storage()`                                              | merchant, payable                                | top up storage pool                                                                                                   |
| `set_plan_active(plan_id, active)`                               | plan.merchant                                    | toggle new signups                                                                                                    |
| `ft_on_transfer(sender_id, amount, msg) -> PromiseOrValue<U128>` | token contract                                   | see below                                                                                                             |
| `charge(sub_id) -> PromiseOrValue<bool>`                         | **anyone**                                 | see below                                                                                                             |
| `cancel(sub_id)`                                                 | sub.owner, `#[payable]` + `assert_one_yocto` | status=Cancelled, refund full balance to owner                                                                        |
| `merchant_cancel(sub_id)`                                        | sub.merchant, 1 yocto                            | same, merchant-initiated refund                                                                                       |
| `withdraw_unclaimed(sub_id)`                                     | sub.owner, 1 yocto                               | retry refund if earlier ft_transfer failed                                                                            |
| callbacks `on_charge_resolved`, `on_refund_resolved`           | `#[private]`                                   | restore state on failed transfer                                                                                      |

**`ft_on_transfer` msg (JSON):**

```json
{"subscribe":{"plan_id":1,"owner":"alice.near","external_ref":"wc:ab12:1042","charge_now":true,"max_charges":null}}
{"top_up":{"sub_id":7}}
```

Rules:

- `predecessor_account_id` **must equal** `plan.token` (subscribe) / `sub.token` (top_up), else return full amount (refund).
- `owner` defaults to `sender_id`. (Explicit owner allows funding via a relayer later.)
- Subscribe: plan active; `amount >= plan.amount` (if `charge_now`); `external_ref` unique per merchant; storage cost paid from `merchant_storage[merchant]` (measure `env::storage_usage()` delta) — if pool insufficient → return full amount.
- `charge_now: true` → first period charged immediately (same logic as `charge`), `next_charge_at = now + period`.
- Never panic after accepting tokens without returning them: validation failures → `PromiseOrValue::Value(amount)` (tokens returned to sender by the FT standard).
- Top-up on `PastDue` with balance now ≥ amount → back to `Active`.

**`charge(sub_id)`:**

1. Require `status == Active` (PastDue may also be retried) and `now >= next_charge_at_ns` and `max_charges` not reached.
2. If `balance < amount` → set `PastDue`, emit `past_due`, return `false` (no panic, state persists).
3. Effects first: `balance -= amount; charges_count += 1; next_charge_at = now + period_ns` (anchored to *now* → at most one charge per period even if the charger was offline for months).
4. `ft_transfer(merchant, amount, memo="recurra:<sub_id>:<n>")` with 1 yocto, then `on_charge_resolved(sub_id, amount, prev_next_charge_at)`.
5. Callback on failure: restore balance, count, next_charge_at; emit `charge_failed`.

### Views

`get_plan(plan_id)`, `get_plans(merchant)`, `get_subscription(sub_id)`, `get_subscriptions_by_owner(owner, from_index, limit)`, `get_subscription_by_ref(merchant, external_ref)`, `get_due(from_index, limit) -> Vec<u64>` (active/past_due with `now >= next_charge_at`), `get_merchant_storage(merchant)`.

### Events (NEP-297, `EVENT_JSON:` log)

`standard: "recurra", version: "1.0.0"`, events: `plan_created`, `subscribed`, `topped_up`, `charged`, `charge_failed`, `past_due`, `cancelled`, `refund_failed`. Each carries `sub_id`, `plan_id`, `merchant`, `owner`, `amount`, `external_ref` where relevant.

### Invariants (test every one)

1. Sum of all `sub.balance` ≤ contract's token balance.
2. A subscription's `amount`, `period_ns`, `merchant`, `token` never change after creation.
3. Charged funds go only to `sub.merchant`; refunds only to `sub.owner`.
4. At most one successful charge per `period_ns` per subscription.
5. Tokens from an unexpected token contract are always returned.
6. Failed outgoing `ft_transfer` never loses accounting (balance restored).
7. Cancelled subscription can never be charged.

### Tests

- Unit (`cargo test`): msg parsing, storage accounting, status transitions, time gating (mock `block_timestamp` via `testing_env!`).
- Integration (`near-workspaces`, sandbox, mock-ft): full lifecycle — create plan → subscribe via `ft_transfer_call` → charge too early (rejected) → fast-forward (`worker.fast_forward(blocks)`) → charge OK → balance exhausted → PastDue → top_up → Active → cancel → refund; attack cases: wrong token, merchant raising price (impossible), double-charge in same period, charge to unregistered merchant (callback restore), cancel by non-owner.

### Deploy

- Testnet: `vault.recurra-<you>.testnet` + mock-ft for manual testing.
- Mainnet: `vault.<your-account>.near`, then once: `storage_deposit` for the vault on the USDC contract (the vault must hold USDC), and for the merchant account.
- Use `cargo near deploy` (check `cargo near --help`; build is reproducible → mention in README, judges value verifiable builds).

---

## 4. WordPress plugin

PHP ≥ 8.1, WooCommerce ≥ 8, **HPOS-compatible** (declare `custom_order_tables` compatibility), all order data through `$order->update_meta_data()`. No Composer runtime deps (use `wp_remote_*`, `bcmath` with fallback to string math).

### Settings (WooCommerce → Settings → Payments → Recurra)

| Key                   | Default                              | Note                                                                 |
| --------------------- | ------------------------------------ | -------------------------------------------------------------------- |
| `merchant_account`  | —                                   | NEAR account receiving USDC (required)                               |
| `destination_asset` | USDC assetId                         | validated against `/v0/tokens`                                     |
| `api_key`           | empty                                | sent as `X-API-Key`; empty = +0.25% fee                            |
| `allowed_chains`    | `arb,base,eth,sol,op,pol,near,btc` | exclude MEMO chains; Tron/BSC only shown when order ≥ their minimum |
| `slippage_bps`      | `100`                              |                                                                      |
| `quote_ttl_min`     | `30` (`90` for btc)              | becomes `deadline`                                                 |
| `mode`              | `live`                             | `mock` = `Recurra_OneClick_Mock` (for judges / CI)               |
| `vault_contract`    | —                                   | for subscriptions                                                    |
| `rpc_url`           | `https://rpc.mainnet.near.org`     | configurable (FastNEAR etc.)                                         |

`is_available()` returns false if store currency ≠ USD or `merchant_account` empty.

### Checkout flow (Feature A)

1. `process_payment($order_id)` → set status `pending`, redirect to the **pay page** (`$order->get_checkout_payment_url(true)` with our receipt template).
2. Pay page: customer picks token (list from `/v0/tokens`, cached 10 min in transient, filtered by `allowed_chains`) and enters **refund address on that chain** (required; basic per-chain format validation: EVM `^0x[a-fA-F0-9]{40}$`, NEAR account regex, SOL base58 32–44, BTC bech32/base58).
3. `POST /wp-json/recurra/v1/orders/{id}/quote` (checks `order_key`, nonce) → server builds:

```json
{
  "dry": false,
  "swapType": "EXACT_OUTPUT",
  "slippageTolerance": 100,
  "originAsset": "<chosen assetId>",
  "depositType": "ORIGIN_CHAIN",
  "destinationAsset": "nep141:17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1",
  "amount": "<order_total_usd * 10^6, integer string>",
  "recipient": "<merchant_account>",
  "recipientType": "DESTINATION_CHAIN",
  "refundTo": "<customer refund address>",
  "refundType": "ORIGIN_CHAIN",
  "deadline": "<now + ttl, ISO>",
  "referral": "recurra",
  "quoteWaitingTimeMs": 3000
}
```

4. Store **entire quote response incl. `signature`** in order meta `_recurra_quote` (dispute evidence), plus `_recurra_deposit_address`, `_recurra_expected_out`, `_recurra_deadline`.
5. Show: deposit address + QR (inline JS QR lib in bundle), exact `amountInFormatted` + symbol + chain, countdown to deadline, explorer link `https://explorer.near-intents.org` (search by deposit address), optional "I've sent it — tx hash" field → `/v0/deposit/submit`.
6. Pay page JS polls `GET /wp-json/recurra/v1/orders/{id}/status?key=…` every 5s; server calls `/v0/status` (cached 5s per order in a transient).
7. Backup: Action Scheduler recurring job every 60s polls all orders with `_recurra_deposit_address` and non-final status until `deadline + 2h`.

### Status mapping (single function, idempotent, protected by a per-order lock)

| 1Click                                      | WooCommerce                                                                                                                                                                                                                                                                    | Notes                                     |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------- |
| `PENDING_DEPOSIT`                         | `pending`                                                                                                                                                                                                                                                                    |                                           |
| `KNOWN_DEPOSIT_TX`, `PROCESSING`        | `on-hold` + note "payment detected"                                                                                                                                                                                                                                          |                                           |
| `SUCCESS`                                 | `payment_complete($tx)` | **only if** `swapDetails.amountOut >= _recurra_expected_out` **and** `quoteResponse.quoteRequest.recipient == merchant_account` **and** `depositAddress` matches; `$tx = nearTxHashes[0]`; store `swapDetails` in meta |                                           |
| `INCOMPLETE_DEPOSIT`                      | `on-hold` + note with `depositedAmount`                                                                                                                                                                                                                                    | customer may top up before deadline       |
| `REFUNDED`                                | `failed` + note with `refundReason`, `refundedAmountFormatted`                                                                                                                                                                                                           |                                           |
| `FAILED`                                  | `failed`                                                                                                                                                                                                                                                                     |                                           |
| deadline passed & still `PENDING_DEPOSIT` | stays `pending`, pay page offers "Get new quote"                                                                                                                                                                                                                             | new quote replaces meta, old one archived |

Security: never trust client-reported status; REST endpoints require `order_key`; rate-limit quote creation per order (max 5); sanitize everything; amounts handled as strings (never floats) — convert `"12.50"` → `"12500000"` with bcmath.

### Admin health page (Recurra → Status)

Checks with green/red: 1Click reachable; API key valid (dry quote); `merchant_account` exists (RPC `view_account`); merchant registered on USDC (`storage_balance_of`); vault reachable (`get_plans`); Action Scheduler running; last poll time. Cheap to build, looks great in the demo.

### Mock mode (critical for screening)

`Recurra_OneClick_Mock` implements the same interface; deposit address = `mock-<hash>`; status advances `PENDING_DEPOSIT → KNOWN_DEPOSIT_TX → PROCESSING → SUCCESS` over ~30s (based on quote creation time), so a judge can do the full checkout in Docker with zero funds. Clearly labelled "MOCK" on the pay page.

---

## 5. Subscriptions in WordPress (Feature B)

Own minimal model — **no dependency on WooCommerce Subscriptions**.

### Merchant side

- Product edit screen → "Recurra subscription" tab: checkbox, select `plan_id` from vault (`get_plans(merchant)` view). Plan creation: admin page button "Create plan" uses wallet-selector in the admin (merchant signs `create_plan` with attached storage deposit e.g. 0.1 NEAR) — or document the `near` CLI command as fallback.
- Product price must equal plan amount (validate on save).

### Customer side (subscribe page, replaces pay page for subscription orders)

1. "Connect NEAR wallet" (wallet-selector: MyNearWallet, Meteor, HOT/HERE, Intear — whatever installs cleanly).
2. Choose prepay periods (1/3/6/12) → shows total = amount × n.
3. Sign one tx: `ft_transfer_call` on USDC contract, `receiver_id = vault`, `amount = total`, `msg = {"subscribe":{"plan_id":…,"owner":<connected account>,"external_ref":"wc:<site>:<order_id>","charge_now":true}}`, 1 yocto, 100 TGas.
   - Before that, if vault not registered for the user… not needed (vault is the receiver; customer is already a USDC holder).
4. JS posts tx hash to `POST /recurra/v1/orders/{id}/subscription` → server confirms via RPC view `get_subscription_by_ref(merchant, external_ref)` (do not trust tx hash alone) → marks order paid (first period), stores `_recurra_sub_id`.
5. "Don't have USDC on NEAR?" link → **Fund from any chain**: reuses Feature A flow with `recipient = customer's NEAR account` (`recipientType: DESTINATION_CHAIN`), then step 3. Two steps, but no experimental features.
   - *Stretch:* single step using `customRecipientMsg` with `recipient = vault` — only after tested with ≤ $1 on mainnet.

### Renewals

- Charger (section 6) calls `charge()` on the contract.
- WP Action Scheduler job (every 10 min): for each order with `_recurra_sub_id`, view `get_subscription`; if `charges_count` increased since last seen → create **renewal order** (copy line items, `payment_complete("recurra:sub<id>:<n>")`, note links to nearblocks). If `PastDue` → mark customer subscription "past due", email top-up link (top-up = `ft_transfer_call` with `{"top_up":{"sub_id":…}}`). If `Cancelled` → stop.
- My Account → "Subscriptions" tab: list subs by connected account (`get_subscriptions_by_owner`), balance, next charge date, **Cancel** button (wallet signs `cancel(sub_id)` with 1 yocto). Cancel lives in the user's wallet, not in the merchant's database — that's the point.

---

## 6. Charger

Node 20 + TypeScript, `near-api-js` (or `@near-js/*`), ~100 lines.

- Loop every 60s: `get_due(0, 50)` → for each id `charge(sub_id)`, 50 TGas; log result; exponential backoff on RPC errors.
- Signs with a **function-call access key restricted to the vault contract and method `charge`** with a small NEAR allowance. Even though `charge` is permissionless, document this: *the server key can only call `charge`, and `charge` can only do what the customer allowed.*
- Config via env: `NEAR_NETWORK`, `RPC_URL`, `VAULT_ID`, `CHARGER_ACCOUNT`, `CHARGER_PRIVATE_KEY`. `.env` git-ignored; `.env.example` committed.
- Runs as a docker-compose service and as `npm run charge-once` for the demo.

---

## 7. Docker / reproducibility

`docker compose up` →

- `db` (mariadb), `wordpress` (php8.2-apache, plugin mounted), `wpcli` init container running `wp-init.sh`:
  install & activate WooCommerce + plugin, set currency USD, create demo products ("Coffee beans $3", "Pro plan $1 / 5 min"), enable gateway in **mock mode**, create admin `admin/admin` (local only, printed in README).
- `charger` (disabled by default profile `--profile live`).
- README: `http://localhost:8080` → add product → checkout → watch mock status complete in ~30s.

Contract: `cd contracts/vault && cargo test && cargo test --test integration` runs offline (sandbox).

---

## 8. Timeline (Tehran time)

| When                                  | Work                                                                                                                                                                                                | Done when                                         |
| ------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------- |
| **Wed 14:00–17:00**            | Toolchain (rustup + wasm target, `cargo-near`, `near` CLI, Node 20, Docker). Create mainnet + testnet accounts. Get NEAR Builders AI credits not needed. Repo scaffold, MIT LICENSE. | `cargo near build` works on hello contract      |
| **Wed 17:00–Thu 02:00**        | Vault contract + unit tests + workspaces integration tests. Deploy to testnet with mock-ft, manual lifecycle via CLI.                                                                               | all invariant tests green                         |
| **Thu 10:00–18:00**            | Plugin Feature A: gateway, 1Click client + mock, pay page, REST, poller, status mapping. Docker compose.                                                                                            | mock checkout completes in Docker                 |
| **Thu 18:00–22:00**            | **Real mainnet test**: $1–2 USDC from Arbitrum/Base → merchant USDC on NEAR. Fix whatever breaks. Admin health page.                                                                        | real order auto-completed                         |
| **Thu 22:00–Fri 01:00**        | Deploy vault to mainnet; storage_deposit vault + merchant on USDC; create demo plan ($1 / 5 min).                                                                                                   | `get_plans` returns plan                        |
| **Fri 10:00–17:00**            | Feature B in WP: wallet-selector bundle, subscribe page, confirm via view, renewal job, My Account tab, cancel. Charger.                                                                            | live: subscribe → 2 renewals → cancel → refund |
| **Fri 17:00 — FEATURE FREEZE** |                                                                                                                                                                                                     |                                                   |
| **Fri 17:00–20:00**            | README, ARCHITECTURE.md, screenshots, record video, make repo public, add `hackathon-submission` tag.                                                                                             | fresh clone passes README steps                   |
| **Fri 20:00–22:00**            | Publish X post. Fill submission. **Submit.**                                                                                                                                                  | confirmation received                             |
| Fri 22:00–Sat 03:29                  | Buffer only. Do not start new features.                                                                                                                                                             |                                                   |

### Cut list (in this order if behind)

1. `customRecipientMsg` single-step funding (already stretch)
2. My Account cancel UI → keep cancel via CLI/near explorer documented
3. Admin plan creation UI → CLI command in README
4. Admin health page
5. Charger as service → `npm run charge-once` in demo
   **Never cut:** contract tests, mock mode, mainnet real-payment proof, README.

---

## 9. README (judge-facing) — structure

1. **What it is** (2 sentences) + 30-second GIF.
2. **The problem**: crypto has no pull payments → no subscriptions; crypto gateways custody merchant funds and charge more.
3. **What NEAR enables here (concrete)**: NEAR Intents 1Click = any token/chain in, exact USDC out to merchant; NEAR contract = customer-signed spending rule (amount, period, recipient) enforced on-chain; function-call keys = server key limited to `charge`.
4. **Try it in 5 minutes (mock, no funds)**: `docker compose up` → "open X → do Y → you see Z".
5. **Run contract tests**: commands + expected output.
6. **Live proof on mainnet**: table of real tx links (checkout swap, subscribe, charge #1, charge #2, refused early charge, cancel refund) on nearblocks + explorer.near-intents.org.
7. **Security model & honest limitations** (1Click trust window, NEAR account needed, USD only, MEMO chains excluded, Tron/BSC minimums).
8. **Architecture** diagram.
9. **What was built during the hackathon** + baseline disclosure (Q-001/Q-005) + libraries used.
10. Contract addresses, license.
    No marketing language.

---

## 10. Demo video (≤ 2:30) & X post

**Video script:**

1. 0:00 — "Crypto can't do subscriptions. Here's a WooCommerce store that can." (store page)
2. 0:10 — Checkout a $2 product, pay with USDC on Arbitrum → pay page shows status → order completes → merchant wallet shows exactly 2.00 USDC on NEAR.
3. 0:50 — Subscribe to "$1 / 5 min" plan, prepay 3 periods, one wallet signature.
4. 1:20 — Charger runs → renewal order appears; try charging early → contract refuses (show error).
5. 1:50 — Customer cancels from their wallet → remaining balance back to customer. Show tx links.
6. 2:15 — "Merchant server can only call `charge`; the contract decides." Repo link.

**X post draft (human publishes):**

> Crypto can't do subscriptions — there's no "pull" payment. Built Recurra at NEAR HACKATHON: a WooCommerce plugin where customers pay with any token on any chain (NEAR Intents → exact USDC to the merchant), and subscribe under a spending rule they sign: max $X, once per period, cancel anytime — enforced by a NEAR contract, not the merchant.
> Demo 👇 Repo:
> Store owners: what would stop you from turning this on?
> @NEARProtocol #NEARHackathon @NearBuilders @NearLegion

---

## 11. Submission checklist

- [ ] Repo public, LICENSE (MIT), README steps verified on a fresh clone
- [ ] Tag `hackathon-submission`; baseline disclosed
- [ ] No secrets in history (`git log -p | grep -i private` / gitleaks)
- [ ] X post live with video
- [ ] Payload ready: project name, team lead, email, team size `1`, tracks `["realworld","business","security"]`, payout account (mainnet), repo URL, demo post URL
- [ ] Submitted once; screenshot of confirmation
- [ ] Hosted demo (optional, plus) kept alive until ≥ 2026-11-18, ideally 11-25

---

## 12. Risks

| Risk                                                         | Mitigation                                                                                  |
| ------------------------------------------------------------ | ------------------------------------------------------------------------------------------- |
| 1Click/NEAR RPC may block or be unreachable from Iranian IPs | Develop & host on a foreign VPS; test from there                                            |
| Merchant/vault not registered on USDC → withdrawal fails    | Health check + setup step in README                                                         |
| Wallet-selector bundling eats time                           | Pin versions, commit `dist/`; fallback: display `near` CLI command / deep link          |
| Prize payout KYC/country rules undocumented                  | Ask in t.me/nearbuilderschat before relying on it                                           |
| Customer funds stuck by contract bug                         | Mainnet demo with ≤ $5 total; invariants tested; README states "hackathon code, unaudited" |
| `customRecipientMsg` loses funds                           | Stretch only, ≤ $1 test                                                                    |
