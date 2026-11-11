//! Sandbox smoke test: build the wasm, deploy it to a local near-sandbox, call it.

use near_api::{AccountId, NearToken};
use near_sdk::serde_json::json;

async fn test_basics_on(contract_wasm: Vec<u8>) -> testresult::TestResult<()> {
    let sandbox = near_sandbox::Sandbox::start_sandbox().await?;
    let network = near_api::NetworkConfig::from_rpc_url("sandbox", sandbox.rpc_addr.parse()?);

    let alice = create_account(&sandbox, "alice.sandbox").await?;
    let bob = create_account(&sandbox, "bob.sandbox").await?;
    let contract = create_account(&sandbox, "vault.sandbox").await?.as_contract();

    let signer = near_api::Signer::from_secret_key(
        near_sandbox::config::DEFAULT_GENESIS_ACCOUNT_PRIVATE_KEY.parse().unwrap(),
    )?;

    near_api::Contract::deploy(contract.account_id().clone())
        .use_code(contract_wasm)
        .with_init_call("new", json!({ "owner": alice.account_id() }))?
        .with_signer(signer.clone())
        .send_to(&network)
        .await?
        .assert_success();

    let greeting: String = contract
        .call_function("get_greeting", ())
        .read_only()
        .fetch_from(&network)
        .await?
        .data;
    assert_eq!(greeting, "Hello");

    // Non-owner is rejected.
    contract
        .call_function("set_greeting", json!({ "greeting": "pwned" }))
        .transaction()
        .with_signer(bob.account_id().clone(), signer.clone())
        .send_to(&network)
        .await?
        .assert_failure();

    // Owner succeeds.
    contract
        .call_function("set_greeting", json!({ "greeting": "Hi" }))
        .transaction()
        .with_signer(alice.account_id().clone(), signer.clone())
        .send_to(&network)
        .await?
        .assert_success();

    let greeting: String = contract
        .call_function("get_greeting", ())
        .read_only()
        .fetch_from(&network)
        .await?
        .data;
    assert_eq!(greeting, "Hi");

    Ok(())
}

async fn create_account(
    sandbox: &near_sandbox::Sandbox,
    name: &str,
) -> testresult::TestResult<near_api::Account> {
    let account_id: AccountId = name.parse()?;
    sandbox
        .create_account(account_id.as_str().parse()?)
        .initial_balance(NearToken::from_near(10))
        .send()
        .await?;
    Ok(near_api::Account(account_id))
}

#[tokio::test]
async fn test_contract_is_operational() -> testresult::TestResult<()> {
    let contract_wasm_path = cargo_near_build::build_with_cli(Default::default())?;
    let contract_wasm = std::fs::read(contract_wasm_path)?;
    test_basics_on(contract_wasm).await
}
