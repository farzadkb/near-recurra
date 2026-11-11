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

## Build the contract

```sh
cd contracts/vault
cargo near build non-reproducible-wasm
```

→ you see `✓ Contract successfully built!` and the wasm at `contracts/vault/target/near/recurra_vault.wasm`.

## Run contract tests

```sh
cd contracts/vault
cargo test
```

→ unit tests and the sandbox integration test pass (`test result: ok`). The first run downloads the `near-sandbox` binary, so it needs network once; the sandbox integration test takes ~1 min.

## License

MIT — see [LICENSE](LICENSE).
