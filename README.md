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
| Docker + Compose | any recent | https://docs.docker.com/get-docker/ |

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
| Recurra Vault | [`vault.recurra-farzad.testnet`](https://testnet.nearblocks.io/address/vault.recurra-farzad.testnet) |
| Mock USDC (6 decimals, test only) | [`musdc.recurra-farzad.testnet`](https://testnet.nearblocks.io/address/musdc.recurra-farzad.testnet) |

Manual lifecycle (plan: 1 mUSDC / 60 s; customer prepaid 3 mUSDC):

| Step | Result | Tx |
| --- | --- | --- |
| `create_plan` | plan 1 | [6w5U6o…](https://testnet.nearblocks.io/txns/6w5U6oa4BCoAaF2U9AUCpNZyqnQnC1m63Jh1tS4Wwonq) |
| subscribe via `ft_transfer_call` (`charge_now`) | sub 1, charge #1 → merchant | [3bMDNS…](https://testnet.nearblocks.io/txns/3bMDNSmzui4ozNevhsHdhSvWgtVVdqxzdEQk3ausCffs) |
| `charge` before period ends | rejected: `not due yet` | [BbymQp…](https://testnet.nearblocks.io/txns/BbymQpNBQHHuWQuk8dkJYYDHrGShVFHrV6VmA4PpKBfX) |
| `charge` after 60 s (by a third party) | charge #2 → merchant | [DGj9Xv…](https://testnet.nearblocks.io/txns/DGj9Xv29xYDp9i2toX4Eir4JqsgobaCtoCmw77Ynf5qK) |
| `cancel` by customer | 1 mUSDC refunded to customer | [8TLtSj…](https://testnet.nearblocks.io/txns/8TLtSjz8HJsswdnFLVLAbQ5pG9k2VzTVeTTYJWRN3udN) |
| `charge` after cancel | rejected: `subscription cancelled` | [EhbEYu…](https://testnet.nearblocks.io/txns/EhbEYu19jHByCN5hkooyq8DWtjSysoL2ifkqw4eBx9Zh) |

Final balances: merchant 2, customer 8 (10 − 3 + 1), vault 0.

Check it yourself:

```sh
near contract call-function as-read-only vault.recurra-farzad.testnet get_subscription json-args '{"sub_id":1}' network-config testnet now
```

→ `"status": "Cancelled"`, `"charges_count": 2`, `"balance": "0"`.

## License

MIT — see [LICENSE](LICENSE).
