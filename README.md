# Recurra

WooCommerce payments and recurring billing on NEAR. Work in progress for the NEAR Hackathon (Nov 2026) — see [docs/PLAN.md](docs/PLAN.md).

> Hackathon code, unaudited.

## Prerequisites

| Tool | Version used | Install |
| --- | --- | --- |
| Rust | 1.95.0 (pinned by `contracts/vault/rust-toolchain.toml`) | https://rustup.rs |
| wasm target | `wasm32-unknown-unknown` | installed automatically by the toolchain file |
| cargo-near | 0.23.0 | `curl --proto '=https' --tlsv1.2 -LsSf https://github.com/near/cargo-near/releases/latest/download/cargo-near-installer.sh \| sh` |
| near CLI (near-cli-rs) | 0.31.1 | `curl --proto '=https' --tlsv1.2 -LsSf https://github.com/near/near-cli-rs/releases/latest/download/near-cli-rs-installer.sh \| sh` |
| Node.js | ≥ 20 | https://nodejs.org |
| Docker + Compose | 29.x / v2 (used: Ubuntu `docker.io` + `docker-compose-v2`) | https://docs.docker.com/get-docker/ |

## Try the checkout in 5 minutes (mock mode, no funds)

Needs only Docker. Nothing is installed on your machine; the stack publishes port 8080 only.

```sh
docker compose -f docker/docker-compose.yml up -d
docker compose -f docker/docker-compose.yml logs -f wpcli   # wait for: [recurra] ready: http://localhost:8080
```

→ about 1 minute on first run (downloads WooCommerce 11.2.2). Then, in a browser:

1. Open http://localhost:8080/shop → add **Coffee beans** ($3) → Checkout. Fill any address, pick **Pay with crypto (any chain)** → Place order.
2. The pay page shows a **MOCK MODE** banner. Pick **USDC — Arbitrum**, enter any EVM refund address (e.g. `0xabababababababababababababababababababab`) → **Get payment address**.
3. You see the amount (3.015 USDC), a `mock-…` deposit address, a QR code and a countdown. The status changes on its own: *Waiting for your deposit* → *Payment detected* → *converting* → you are redirected to the order-received page in ~30 s.
4. Admin: http://localhost:8080/wp-admin (`admin` / `admin`, local only) → WooCommerce → Orders → the order is **Processing**; its notes show *payment detected*, *swap complete* and the (mock) NEAR tx.

Automated version of the same flow (block checkout via the WooCommerce Store API → pay page → quote → status):

```sh
docker compose -f docker/docker-compose.yml run --rm wpcli php /usr/local/bin/e2e-mock.php
```

→ `E2E OK (success, pay-page polling): order #N pending → on-hold → processing in ~32s`. Variants:

| Command suffix | Checks | Expected last line |
| --- | --- | --- |
| `-e E2E_POLL=0` (before `wpcli`) | customer closed the tab; only the 60 s backup poller (Action Scheduler) moves the order | `E2E OK (success, backup poller only): … pending → processing` |
| `-e E2E_SCENARIO=refunded` | 1Click refunds the customer | `… pending → on-hold → failed` |
| `-e E2E_SCENARIO=incomplete` | deposit below the quote | `… pending → on-hold` |
| `-e E2E_SCENARIO=double` | quote replaced after our 30-min deadline, customer pays **both** addresses | order paid once; an `EXTRA PAYMENT` admin note flags the second payment for manual refund (~100 s) |

Reset everything: `docker compose -f docker/docker-compose.yml down -v`.

Plugin unit tests (no WordPress needed): `php wp-plugin/recurra-for-woocommerce/tests/unit.php` → `75 assertions, 0 failures`.

## Try subscriptions on testnet (Feature B, ~15 minutes, no real money)

The Docker store also sells **Pro plan (testnet)**, $1 every 5 minutes, backed by the public testnet vault [`vault-v2.recurra-farzad.testnet`](https://testnet.nearblocks.io/address/vault-v2.recurra-farzad.testnet) (plan 2, token `musdc.recurra-farzad.testnet` = mock USDC with a public faucet, merchant `merchant.recurra-farzad.testnet`). The subscribe page shows a **TESTNET** banner. You need the stack from the section above, the near CLI and Node ≥ 22.18.

**1. Create a testnet account** (free, funded by the testnet faucet service; the key is saved in `~/.near-credentials/testnet/`):

```sh
near account create-account sponsor-by-faucet-service <you>.testnet autogenerate-new-keypair save-to-legacy-keychain network-config testnet create
```

→ `New account <you>.testnet created successfully.` To use it in a browser wallet, import the `private_key` from `~/.near-credentials/testnet/<you>.testnet.json` into a wallet that supports testnet (e.g. Meteor Wallet), or create a separate account directly in a testnet browser wallet (e.g. https://testnet.mynearwallet.com) — any account works for subscribing.

**2. Get test mUSDC** — on the subscribe page (step 3) click **Get 100 test tokens (testnet faucet)**, or:

```sh
near contract call-function as-transaction musdc.recurra-farzad.testnet faucet json-args '{}' prepaid-gas '30.0 Tgas' attached-deposit '0 NEAR' sign-as <you>.testnet network-config testnet sign-with-legacy-keychain send
```

→ `ft_mint … "amount":"100000000","memo":"faucet"` (100 mUSDC; max 100 per call, one claim per account per hour).

**3. Subscribe in Docker.** Log in at http://localhost:8080/wp-admin as `admin`/`admin` (or register a customer) so My Account works, then:

1. http://localhost:8080/shop → **Pro plan (testnet)** → Checkout (alone in the cart; only "Pay with crypto" is offered) → Place order.
2. The **subscribe page** states the rule: the vault can only pay the merchant $1 per 5 min; cancel any time. Connect your wallet (MyNearWallet, Meteor or Intear), pick 1/2/3/4/6/12 periods and sign **one** `ft_transfer_call`.
3. The server reads the subscription back from the chain (`get_subscription_by_ref`) and only then marks the order **Processing** (a tx hash from the browser is never trusted) → order-received page. Charge #1 went to the merchant immediately.

**4. Charge the next period — with any testnet account** (`charge` is permissionless; the contract only moves money per the rule). After 5 minutes either run the charger with your account:

```sh
cd charger && npm ci
printf 'NEAR_NETWORK=testnet\nVAULT_ID=vault-v2.recurra-farzad.testnet\nCHARGER_ACCOUNT=<you>.testnet\nCHARGER_PRIVATE_KEY=<private_key from ~/.near-credentials/testnet/<you>.testnet.json>\n' > .env && chmod 600 .env
npm run charge-once     # → "sub N: charged https://testnet.nearblocks.io/txns/…"  (or "nothing due" before the 5 minutes are up)
```

or call it directly (the subscription id is in the order notes and in My Account):

```sh
near contract call-function as-transaction vault-v2.recurra-farzad.testnet charge json-args '{"sub_id":N}' prepaid-gas '50.0 Tgas' attached-deposit '0 NEAR' sign-as <you>.testnet network-config testnet sign-with-legacy-keychain send
```

→ within a minute the store creates a **renewal order** (WooCommerce → Orders: Processing, $1.00, transaction id `recurra:sub<N>:2`, linked to the first order). Charging again before the period ends fails with `not due yet` — the contract, not the store, enforces the schedule.

**5. Cancel → refund.** **My Account → Subscriptions** shows balance, next charge, charges, **Top up** and **Cancel**. Cancel is signed by your wallet; the contract refunds the remaining balance to you and the store marks the subscription *Cancelled*. Check your token balance:

```sh
near contract call-function as-read-only musdc.recurra-farzad.testnet ft_balance_of json-args '{"account_id":"<you>.testnet"}' network-config testnet now
```

### Be the merchant

Create your own plan on the testnet vault and watch the charges arrive in **your** account.

```sh
# Your account must be registered on mUSDC to receive charges (claiming the faucet once does it).
near contract call-function as-transaction vault-v2.recurra-farzad.testnet create_plan json-args '{"token":"musdc.recurra-farzad.testnet","amount":"1000000","period_sec":120,"name":"My plan"}' prepaid-gas '30.0 Tgas' attached-deposit '0.05 NEAR' sign-as <you>.testnet network-config testnet sign-with-legacy-keychain send
near contract call-function as-read-only vault-v2.recurra-farzad.testnet get_plans json-args '{"merchant":"<you>.testnet"}' network-config testnet now
```

→ the returned plan id (the 0.05 NEAR prepays the vault storage for the plan and its subscriptions). Then in the Docker admin:

1. WooCommerce → Settings → Payments → **Pay with crypto** → *Merchant NEAR account* = `<you>.testnet` → Save (mock mode accepts testnet accounts; the demo Pro plan product now belongs to a different merchant and is refused at checkout).
2. Products → Add new: Simple, **Virtual**, Regular price **1.00** → tab **Recurra subscription** → *Vault plan* = your plan → Publish. The plugin checks the plan against the chain (merchant, active, price = plan amount) and refuses the link otherwise.
3. Subscribe to it from a **second** account (steps 1–3 above), charge it (step 4) → each charge moves 1 mUSDC from the vault to `<you>.testnet` and creates a renewal order:

```sh
near contract call-function as-read-only musdc.recurra-farzad.testnet ft_balance_of json-args '{"account_id":"<you>.testnet"}' network-config testnet now
```

### Automated testnet run (maintainers)

Needs the near CLI keychain of the testnet customer (`customer.recurra-farzad.testnet`); ~3 min because plan 1 has a 60 s period. Charge #2 runs the charger (`E2E_CHARGER_ENV` selects its env file, e.g. one for any judge account), charge #3 uses the near CLI one-liner signed by `E2E_CLI_CHARGER` (default: the customer).

```sh
php docker/e2e-subscription.php
```

→ `E2E OK (testnet subscription): order #N paid → 2 renewal orders → cancelled → refund 2000000 in ~180s`, followed by the subscribe / charge / cancel tx links. A recorded run (2026-11-11, subscription #3, prepaid 5 × 1 mUSDC):

| Step | Store | Tx |
| --- | --- | --- |
| subscribe (`ft_transfer_call`, charge #1) | order #18 → Processing | [2bGuJ8…](https://testnet.nearblocks.io/txns/2bGuJ8WGDGumGanr1AiYc8a5NXwQmUmyJH72oJXhFz2U) |
| charger: charge #2 | renewal order #20 | [3BtZqs…](https://testnet.nearblocks.io/txns/3BtZqsREgwhrBjfkUebCswhJHfBKHXfHGm1M4Bi4q86e) |
| charger: charge #3 | renewal order #21 | [2qR4Cn…](https://testnet.nearblocks.io/txns/2qR4CnDrX9sRudo88qnju4w9jM8NatuVys5Gtw37cavm) |
| customer `cancel` | subscription *Cancelled*; 2 mUSDC refunded to the customer | [CVxGvQ…](https://testnet.nearblocks.io/txns/CVxGvQv7ReASttmSYSWMCEQY3brFR3bNnyZ4kCmiWGSr) |

### Charger

`charger/` (Node ≥ 22.18, runs TypeScript directly, `near-api-js` 7.4.0) calls `get_due` and `charge(sub_id)` every 60 s. `charge` is permissionless and the contract enforces every rule, so any account works (step 4 above). For a long-running deployment, sign with a **function-call key that can only call `charge` on the vault** — it cannot move funds anywhere else. `CHARGER_ENV_FILE=.env.mainnet` selects another env file (all `.env*` files are git-ignored).

```sh
cd charger && npm ci
node src/keygen.ts <charger-account> <vault-id> testnet   # writes .env (mode 600), prints only the public key + the add-key command
# run the printed `near account add-key … grant-function-call-access … --function-names charge …` command
npm run charge-once        # one pass → "sub N: charged https://testnet.nearblocks.io/txns/…" or "nothing due"
npm start                  # loop
```

Or as a Docker service: `docker compose -f docker/docker-compose.yml --profile live up -d charger`.

Testnet charger: `charger.recurra-farzad.testnet`, key `ed25519:HaUM9gfyZx7xtp7VScC4CmkfgkhYqAULoFmn99p9THGL` = *only `charge` on `vault-v2.recurra-farzad.testnet`, allowance 0.25 NEAR*:

```sh
near account list-keys charger.recurra-farzad.testnet network-config testnet now
```

### Rebuild the wallet bundle (optional)

`assets/dist/recurra-wallet.js` is committed, so the plugin needs no build step. To rebuild it from `assets/src/wallet.js` (wallet-selector 10.1.4, esbuild 0.28.2):

```sh
cd wp-plugin/recurra-for-woocommerce && npm ci && npm run build
```

## Live proof on mainnet

Real checkout (plugin in live mode, real 1Click swap): customer pays USDC on Base, merchant `recurra-shop.near` receives native USDC on NEAR.

| Date (UTC) | Order | Paid with | Base tx (customer → 1Click deposit address) | Merchant received | NEAR tx |
| --- | --- | --- | --- | --- | --- |
| 2026-11-11 | $0.50 | 0.500103 USDC on Base | [0xaf0418…1f93](https://basescan.org/tx/0xaf0418d5f81a3dd9579bf92a032244d41cd4cb3f39ecfbf1c90e6fb2b29d1f93) | exactly 0.50 USDC (`500000`) | [H41R6h…eWSpP](https://nearblocks.io/txns/H41R6h5PK3eVNuSvVc9kb21WiiAAspdjVMs45B3eWSpP) |

Deposit address `0x776b1135Da69B5cbf6547CE70746C61a22Df75D1` — search it on [explorer.near-intents.org](https://explorer.near-intents.org). Check the merchant balance yourself:

```sh
near contract call-function as-read-only 17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1 ft_balance_of json-args '{"account_id":"recurra-shop.near"}' network-config mainnet now
```

→ at least `"500000"` (0.50 USDC; later demo payments add to it).

### Mainnet vault

[`recurra-vault.near`](https://nearblocks.io/address/recurra-vault.near) runs the reproducible build of commit `ad9de6e` (wasm sha256 `f886bee28e2dd786e2ec80af3e9548ace0a6acf177da3fbc3732fa8c69651e2a`, on-chain code hash `Hj9HAoWYCpxbru4RSePSVa29ofwmWdB4Vnhnfk6ygHsX`).

| Step | Signer | Tx |
| --- | --- | --- |
| deploy + `new(owner = recurra-vault.near)` | `recurra-vault.near` | [71dKyT…](https://nearblocks.io/txns/71dKyTKDsvDAz8V1YPEk2RxYNjninvwW73B9oUsW5hPK) |
| `storage_deposit` on USDC for the vault | `recurra-vault.near` | [FWtUY1…](https://nearblocks.io/txns/FWtUY1uvne77ikmLaXUf4vJFdc3zcgStBuXDsGLF5v6N) |
| `create_plan` → plan 1 "Demo plan", 1 USDC / 300 s | `recurra-shop.near` | [9DD3MN…](https://nearblocks.io/txns/9DD3MNm5x18GY6pTMX2nxNA2GpamDiTQPs5CfeoMrff) |

Verify (read-only):

```sh
near contract call-function as-read-only recurra-vault.near get_owner json-args '{}' network-config mainnet now                   # "recurra-vault.near"
near contract call-function as-read-only recurra-vault.near contract_source_metadata json-args '{}' network-config mainnet now    # link …/tree/ad9de6e9…
near contract call-function as-read-only recurra-vault.near get_plans json-args '{"merchant":"recurra-shop.near"}' network-config mainnet now
near contract call-function as-read-only 17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1 storage_balance_of json-args '{"account_id":"recurra-vault.near"}' network-config mainnet now
```

→ owner `"recurra-vault.near"`; plan `1` with `"amount": "1000000"`, `"period_sec": 300`, `"active": true`; vault registered on USDC (`"total": "1250000000000000000000"`).

## Build the contracts

```sh
cd contracts/vault   && cargo near build non-reproducible-wasm && cd ../..
cd contracts/mock-ft && cargo near build non-reproducible-wasm && cd ../..
```

→ you see `✓ Contract successfully built!` and the wasm at `contracts/vault/target/near/recurra_vault.wasm` (~226 KB) and `contracts/mock-ft/target/near/mock_ft.wasm` (test token only).

## Run contract tests

```sh
cd contracts/vault
cargo test --lib                 # unit tests
cargo test --test integration    # sandbox integration tests (builds vault + mock-ft wasm)
```

→ `test result: ok. 49 passed` (unit) and `test result: ok. 6 passed` (integration: `full_lifecycle`, `attack_wrong_token_and_forged_transfer`, `attack_merchant_cannot_raise_price`, `attack_failed_transfers_restore_state`, `merchant_cancel_refunds_owner`, `gas_reservation_and_low_gas_refusal`). The first run downloads the `near-sandbox` binary, so it needs network once; integration tests take ~1 min including the build.

The test token has its own unit tests (faucet cap, per-account cooldown, testnet-only guard, owner-only mint): `cd contracts/mock-ft && cargo test --lib` → `test result: ok. 7 passed`.

Each invariant from [docs/PLAN.md §3](docs/PLAN.md) has dedicated tests:

| # | Invariant | Unit test(s) (`src/tests.rs`) | Integration test(s) |
| --- | --- | --- | --- |
| 1 | Sum of balances ≤ token balance | `inv1_total_held_equals_sum_of_balances_through_lifecycle` | `assert_inv1` after every step of every test |
| 2 | Sub terms never change | `inv2_subscription_terms_never_change` | `attack_merchant_cannot_raise_price` |
| 3 | Charges → merchant only, refunds → owner only | `subscribe_charge_now_charges_first_period_to_merchant`, `cancel_refunds_full_balance_to_owner` | `full_lifecycle`, `merchant_cancel_refunds_owner` |
| 4 | ≤ 1 charge per period | `inv4_at_most_one_charge_per_period`, `concurrent_charge_is_refused`, `next_charge_anchored_to_now_after_downtime`, `stale_flag_blocks_until_threshold_then_clears_without_recredit`, `late_success_callback_after_stale_clear_changes_nothing` | `full_lifecycle` (early + double charge) |
| 5 | Unexpected token returned | `inv5_wrong_token_is_returned` | `attack_wrong_token_and_forged_transfer` |
| 6 | Failed transfer restores accounting | `inv6_failed_charge_restores_accounting`, `inv6_failed_refund_restores_and_withdraw_unclaimed_retries`, `late_failure_callback_after_stale_clear_recredits_once`, `cancel_during_in_flight_charge_*`, `charge_with_insufficient_gas_is_refused_before_state_change`, `callback_gets_fixed_gas_reservation` | `attack_failed_transfers_restore_state`, `gas_reservation_and_low_gas_refusal` |
| 7 | Cancelled never charged | `inv7_cancelled_cannot_be_charged`, `inv7_cancelled_cannot_be_topped_up` | `full_lifecycle` |

## Testnet deployment

| Contract | Account |
| --- | --- |
| Recurra Vault (current) | [`vault-v2.recurra-farzad.testnet`](https://testnet.nearblocks.io/address/vault-v2.recurra-farzad.testnet) — reproducible build, wasm sha256 `1ff5dc13b0e32e0f6e1bf3e0773cad3448798e8979590d399b0570779fcc9e6e` (commit `4d60119`) |
| Mock USDC (6 decimals, test only; public `faucet`: ≤ 100 mUSDC per call, 1 claim per account per hour) | [`musdc.recurra-farzad.testnet`](https://testnet.nearblocks.io/address/musdc.recurra-farzad.testnet) — upgraded in place with the faucet ([A7eXDv…](https://testnet.nearblocks.io/txns/A7eXDvM9CHahr9ZQp9MZgFrRTHkgavtro8XepmYXmmZa)) |

`vault.recurra-farzad.testnet` holds the earlier, non-reproducible build (before the in-flight/gas hardening) and is kept only for history.

Verify the deployed code matches this repo:

```sh
cd contracts/vault
cargo near build reproducible-wasm     # needs Docker
sha256sum target/near/recurra_vault.wasm
```

→ `1ff5dc13b0e32e0f6e1bf3e0773cad3448798e8979590d399b0570779fcc9e6e` at commit `4d60119`; the same commit and Docker image digest are returned on-chain by `contract_source_metadata` (NEP-330).

Manual lifecycle on `vault-v2` (plan: 1 mUSDC / 60 s; customer prepaid 3 mUSDC):

| Step | Result | Tx |
| --- | --- | --- |
| `create_plan` | plan 1 | [HMbJz6…](https://testnet.nearblocks.io/txns/HMbJz6dBnUJ5qTrCxbhR9vFDK3U39so6jYPDtvstZj2D) |
| subscribe via `ft_transfer_call` (`charge_now`) | sub 1, charge #1 → merchant | [DtfhaC…](https://testnet.nearblocks.io/txns/DtfhaCBEozpw2RK2U9xqPLjQuiqcTwRw1YJcAKvXaFt9) |
| `charge` with 25 TGas | rejected before any state change: `insufficient gas` | [DgGU5k…](https://testnet.nearblocks.io/txns/DgGU5kLnfZuZdBfQbFdhjcUmCNZDp4vsvgAMsjL3aBEJ) |
| `charge` before period ends | rejected: `not due yet` | [55jRMn…](https://testnet.nearblocks.io/txns/55jRMneSzj4ekM8mDeniZQPVEM1UFXe4Y1sRY7ViMjCf) |
| `charge` after 60 s (by a third party) | charge #2 → merchant | [7uid4U…](https://testnet.nearblocks.io/txns/7uid4UWxF8QkjgJvBhpaGzzPGkEfAP9snd5PmmfDWFR5) |
| `cancel` by customer | 1 mUSDC refunded to customer | [6rYRsx…](https://testnet.nearblocks.io/txns/6rYRsxodAjkktGponPTGjsGvXJXg9dHdnZVSDdHYdjpx) |
| `charge` after cancel | rejected: `subscription cancelled` | [KnSX5e…](https://testnet.nearblocks.io/txns/KnSX5eGDfmnXLQ9vQwtJGgC8zyFSKrF8SU4XGbLeSGN) |

Check it yourself:

```sh
near contract call-function as-read-only vault-v2.recurra-farzad.testnet get_subscription json-args '{"sub_id":1}' network-config testnet now
```

→ `"status": "Cancelled"`, `"charges_count": 2`, `"balance": "0"`, `"in_flight": null`.

## License

MIT — see [LICENSE](LICENSE).
