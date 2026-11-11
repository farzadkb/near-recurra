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

Reset everything: `docker compose -f docker/docker-compose.yml down -v`.

Plugin unit tests (no WordPress needed): `php wp-plugin/recurra-for-woocommerce/tests/unit.php` → `51 assertions, 0 failures`.

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
| Mock USDC (6 decimals, test only) | [`musdc.recurra-farzad.testnet`](https://testnet.nearblocks.io/address/musdc.recurra-farzad.testnet) |

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
