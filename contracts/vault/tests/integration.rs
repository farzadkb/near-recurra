//! Sandbox integration tests (near-sandbox + near-api): the real vault wasm and
//! the mock NEP-141 token (contracts/mock-ft) on a local chain.
//!
//! Covers the full lifecycle and the attack cases of docs/PLAN.md §3, checking
//! invariant 1 (sum of balances <= real token balance) after every step.

use near_api::{AccountId, Contract, NearGas, NearToken, NetworkConfig, Signer};
use near_sdk::serde_json::{Value, json};
use std::sync::{Arc, OnceLock};
use testresult::TestResult;

const USDC: u128 = 1_000_000; // 6 decimals
const PERIOD_SEC: u64 = 60;
const ONE_YOCTO: NearToken = NearToken::from_yoctonear(1);

static VAULT_WASM: OnceLock<Vec<u8>> = OnceLock::new();
static FT_WASM: OnceLock<Vec<u8>> = OnceLock::new();

fn build(manifest: &str) -> Vec<u8> {
    let opts = cargo_near_build::BuildOpts {
        manifest_path: Some(manifest.into()),
        ..Default::default()
    };
    let path = cargo_near_build::build_with_cli(opts).expect("build failed");
    std::fs::read(path).unwrap()
}

fn vault_wasm() -> Vec<u8> {
    VAULT_WASM
        .get_or_init(|| build(concat!(env!("CARGO_MANIFEST_DIR"), "/Cargo.toml")))
        .clone()
}

fn ft_wasm() -> Vec<u8> {
    FT_WASM
        .get_or_init(|| build(concat!(env!("CARGO_MANIFEST_DIR"), "/../mock-ft/Cargo.toml")))
        .clone()
}

struct Env {
    sandbox: near_sandbox::Sandbox,
    net: NetworkConfig,
    signer: Arc<Signer>,
    vault: Contract,
    usdc: Contract,
    merchant: AccountId,
    alice: AccountId,
    bob: AccountId,
}

impl Env {
    async fn new() -> TestResult<Self> {
        // Build outside the async runtime's worker (blocking, cached across tests).
        let (vault_code, ft_code) =
            tokio::task::spawn_blocking(|| (vault_wasm(), ft_wasm())).await?;

        let sandbox = near_sandbox::Sandbox::start_sandbox().await?;
        let net = NetworkConfig::from_rpc_url("sandbox", sandbox.rpc_addr.parse()?);
        let signer = Signer::from_secret_key(
            near_sandbox::config::DEFAULT_GENESIS_ACCOUNT_PRIVATE_KEY.parse()?,
        )?;

        for name in ["vault", "usdc", "merchant", "alice", "bob"] {
            sandbox
                .create_account(format!("{name}.sandbox").parse::<near_sdk::AccountId>()?)
                .initial_balance(NearToken::from_near(50))
                .send()
                .await?;
        }
        let id = |n: &str| -> AccountId { format!("{n}.sandbox").parse().unwrap() };

        Contract::deploy(id("usdc"))
            .use_code(ft_code)
            .with_init_call("new", json!({ "owner": id("usdc"), "decimals": 6 }))?
            .with_signer(signer.clone())
            .send_to(&net)
            .await?
            .assert_success();
        Contract::deploy(id("vault"))
            .use_code(vault_code)
            .with_init_call("new", json!({ "owner": id("vault") }))?
            .with_signer(signer.clone())
            .send_to(&net)
            .await?
            .assert_success();

        let env = Self {
            sandbox,
            net,
            signer,
            vault: Contract(id("vault")),
            usdc: Contract(id("usdc")),
            merchant: id("merchant"),
            alice: id("alice"),
            bob: id("bob"),
        };
        // Register vault + merchant on the token (as on mainnet USDC), fund alice.
        env.storage_deposit(&env.usdc, &env.vault.0.clone()).await?;
        env.storage_deposit(&env.usdc, &env.merchant.clone()).await?;
        env.mint(&env.usdc, &env.alice.clone(), 100 * USDC).await?;
        Ok(env)
    }

    async fn create_account(&self, name: &str) -> TestResult<AccountId> {
        let id: AccountId = format!("{name}.sandbox").parse()?;
        self.sandbox.create_account(id.as_str().parse()?).initial_balance(NearToken::from_near(50)).send().await?;
        Ok(id)
    }

    async fn deploy_ft(&self, name: &str) -> TestResult<Contract> {
        let id = self.create_account(name).await?;
        Contract::deploy(id.clone())
            .use_code(ft_wasm())
            .with_init_call("new", json!({ "owner": id, "decimals": 6 }))?
            .with_signer(self.signer.clone())
            .send_to(&self.net)
            .await?
            .assert_success();
        Ok(Contract(id))
    }

    /// Sends a function-call transaction. Returns the near-api result to assert on.
    async fn call(
        &self,
        signer: &AccountId,
        contract: &Contract,
        method: &str,
        args: Value,
        deposit: NearToken,
        tgas: u64,
    ) -> TestResult<near_api::types::transaction::result::TransactionResult> {
        Ok(contract
            .call_function(method, args)
            .transaction()
            .deposit(deposit)
            .gas(NearGas::from_tgas(tgas))
            .with_signer(signer.clone(), self.signer.clone())
            .send_to(&self.net)
            .await?)
    }

    async fn view<T: near_sdk::serde::de::DeserializeOwned + Send + Sync>(
        &self,
        contract: &Contract,
        method: &str,
        args: Value,
    ) -> TestResult<T> {
        Ok(contract.call_function(method, args).read_only().fetch_from(&self.net).await?.data)
    }

    async fn storage_deposit(&self, token: &Contract, account: &AccountId) -> TestResult<()> {
        self.call(account, token, "storage_deposit", json!({ "account_id": account }), NearToken::from_millinear(10), 30)
            .await?
            .assert_success();
        Ok(())
    }

    async fn mint(&self, token: &Contract, to: &AccountId, amount: u128) -> TestResult<()> {
        self.call(&token.0.clone(), token, "mint", json!({ "account_id": to, "amount": amount.to_string() }), NearToken::from_yoctonear(0), 30)
            .await?
            .assert_success();
        Ok(())
    }

    async fn balance(&self, token: &Contract, account: &AccountId) -> TestResult<u128> {
        let s: String = self.view(token, "ft_balance_of", json!({ "account_id": account })).await?;
        Ok(s.parse()?)
    }

    async fn create_plan(&self, merchant: &AccountId, token: &Contract, amount: u128) -> TestResult<u32> {
        let r = self
            .call(
                merchant,
                &self.vault,
                "create_plan",
                json!({ "token": token.0, "amount": amount.to_string(), "period_sec": PERIOD_SEC, "name": "Pro" }),
                NearToken::from_millinear(100),
                30,
            )
            .await?
            .assert_success();
        Ok(r.json()?)
    }

    /// `ft_transfer_call` with the vault as receiver (100 TGas, as the WP frontend sends).
    async fn ft_transfer_call(
        &self,
        from: &AccountId,
        token: &Contract,
        amount: u128,
        msg: Value,
    ) -> TestResult<near_api::types::transaction::result::TransactionResult> {
        self.call(
            from,
            token,
            "ft_transfer_call",
            json!({ "receiver_id": self.vault.0, "amount": amount.to_string(), "msg": msg.to_string() }),
            ONE_YOCTO,
            100,
        )
        .await
    }

    async fn sub(&self, id: u64) -> TestResult<Value> {
        self.view(&self.vault, "get_subscription", json!({ "sub_id": id })).await
    }

    async fn charge(&self, caller: &AccountId, id: u64) -> TestResult<near_api::types::transaction::result::TransactionResult> {
        self.call(caller, &self.vault, "charge", json!({ "sub_id": id }), NearToken::from_yoctonear(0), 50).await
    }

    async fn now_ns(&self) -> TestResult<u64> {
        Ok(near_api::Chain::block().fetch_from(&self.net).await?.header.timestamp)
    }

    /// Fast-forwards the chain until block time >= `target_ns`.
    async fn advance_to(&self, target_ns: u64) -> TestResult<()> {
        for _ in 0..200 {
            if self.now_ns().await? >= target_ns {
                return Ok(());
            }
            self.sandbox.fast_forward(50).await?;
        }
        Err("could not fast-forward far enough".into())
    }

    async fn advance_past_next_charge(&self, id: u64) -> TestResult<()> {
        let next: u64 = self.sub(id).await?["next_charge_at_ns"].as_str().unwrap().parse()?;
        self.advance_to(next + 1).await
    }

    /// Invariant 1: sum of subscription balances (= total_held) <= real token balance.
    async fn assert_inv1(&self, token: &Contract) -> TestResult<()> {
        let held: String = self.view(&self.vault, "get_total_held", json!({ "token": token.0 })).await?;
        let held: u128 = held.parse()?;
        let n: u64 = self.view(&self.vault, "get_num_subscriptions", json!({})).await?;
        let mut sum = 0u128;
        for id in 1..=n {
            let s = self.sub(id).await?;
            if s["token"] == json!(token.0) {
                sum += s["balance"].as_str().unwrap().parse::<u128>()?;
            }
        }
        let real = self.balance(token, &self.vault.0.clone()).await?;
        assert_eq!(held, sum, "total_held != sum of balances");
        assert!(sum <= real, "invariant 1 violated: sum {sum} > real {real}");
        Ok(())
    }
}

fn subscribe_msg(plan_id: u32, ext: &str, charge_now: bool) -> Value {
    json!({ "subscribe": { "plan_id": plan_id, "external_ref": ext, "charge_now": charge_now, "max_charges": null } })
}

fn has_event(logs: &[&str], event: &str) -> bool {
    logs.iter().any(|l| l.starts_with("EVENT_JSON:") && l.contains(&format!(r#""event":"{event}""#)))
}

fn status(s: &Value) -> &str {
    s["status"].as_str().unwrap()
}

fn bal(s: &Value) -> u128 {
    s["balance"].as_str().unwrap().parse().unwrap()
}

// ---------------------------------------------------------------------------

/// create plan → subscribe (charge_now) → early charge rejected → charge after
/// period → double charge rejected → balance exhausted → PastDue → top_up →
/// Active → charge → cancel → refund → charge after cancel rejected.
#[tokio::test]
async fn full_lifecycle() -> TestResult {
    let e = Env::new().await?;
    let (alice, bob, merchant) = (e.alice.clone(), e.bob.clone(), e.merchant.clone());

    let plan = e.create_plan(&merchant, &e.usdc, USDC).await?;
    let plans: Vec<Value> = e.view(&e.vault, "get_plans", json!({ "merchant": merchant })).await?;
    assert_eq!(plans.len(), 1);

    // Subscribe with 3 periods prepaid; first period charged immediately.
    let r = e.ft_transfer_call(&alice, &e.usdc, 3 * USDC, subscribe_msg(plan, "wc:test:1", true)).await?.assert_success();
    let logs = r.logs();
    assert!(has_event(&logs, "subscribed"), "{logs:?}");
    assert!(has_event(&logs, "charged"), "{logs:?}");
    let s = e.view::<Value>(&e.vault, "get_subscription_by_ref", json!({ "merchant": merchant, "external_ref": "wc:test:1" })).await?;
    let id = s["id"].as_u64().unwrap();
    assert_eq!((bal(&s), s["charges_count"].as_u64(), status(&s)), (2 * USDC, Some(1), "Active"));
    assert_eq!(e.balance(&e.usdc, &merchant).await?, USDC);
    assert_eq!(e.balance(&e.usdc, &alice).await?, 97 * USDC);
    e.assert_inv1(&e.usdc).await?;

    // Invariant 4: early charge by anyone is refused, nothing moves.
    e.charge(&bob, id).await?.assert_failure();
    assert_eq!(e.balance(&e.usdc, &merchant).await?, USDC);
    assert_eq!(bal(&e.sub(id).await?), 2 * USDC);

    // After the period: permissionless charge succeeds and pays the merchant.
    e.advance_past_next_charge(id).await?;
    let due: Vec<u64> = e.view(&e.vault, "get_due", json!({})).await?;
    assert_eq!(due, vec![id]);
    let ok: bool = e.charge(&bob, id).await?.assert_success().json()?;
    assert!(ok);
    assert_eq!(e.balance(&e.usdc, &merchant).await?, 2 * USDC);
    assert_eq!(e.balance(&e.usdc, &bob).await?, 0, "caller gets nothing");
    e.assert_inv1(&e.usdc).await?;

    // Double charge in the same period refused.
    e.charge(&bob, id).await?.assert_failure();
    assert_eq!(e.sub(id).await?["charges_count"], 2);

    // Charge #3 empties the balance; #4 marks PastDue without moving funds.
    e.advance_past_next_charge(id).await?;
    assert!(e.charge(&bob, id).await?.assert_success().json::<bool>()?);
    assert_eq!(bal(&e.sub(id).await?), 0);
    e.advance_past_next_charge(id).await?;
    let r = e.charge(&bob, id).await?.assert_success();
    assert!(has_event(&r.logs(), "past_due"));
    assert!(!r.json::<bool>()?);
    assert_eq!(status(&e.sub(id).await?), "PastDue");
    assert!(e.view::<Vec<u64>>(&e.vault, "get_due", json!({})).await?.is_empty());
    assert_eq!(e.balance(&e.usdc, &merchant).await?, 3 * USDC);

    // Top-up reactivates; overdue charge then goes through.
    let r = e.ft_transfer_call(&alice, &e.usdc, 2 * USDC, json!({ "top_up": { "sub_id": id } })).await?.assert_success();
    assert!(has_event(&r.logs(), "topped_up"));
    assert_eq!(status(&e.sub(id).await?), "Active");
    assert!(e.charge(&bob, id).await?.assert_success().json::<bool>()?);
    assert_eq!(e.balance(&e.usdc, &merchant).await?, 4 * USDC);
    e.assert_inv1(&e.usdc).await?;

    // Cancel by a non-owner fails; owner cancel refunds the full remainder.
    e.call(&bob, &e.vault, "cancel", json!({ "sub_id": id }), ONE_YOCTO, 50).await?.assert_failure();
    e.call(&merchant, &e.vault, "cancel", json!({ "sub_id": id }), ONE_YOCTO, 50).await?.assert_failure();
    let alice_before = e.balance(&e.usdc, &alice).await?;
    let r = e.call(&alice, &e.vault, "cancel", json!({ "sub_id": id }), ONE_YOCTO, 50).await?.assert_success();
    assert!(has_event(&r.logs(), "cancelled"));
    assert!(r.json::<bool>()?);
    assert_eq!(e.balance(&e.usdc, &alice).await?, alice_before + USDC);
    let s = e.sub(id).await?;
    assert_eq!((status(&s), bal(&s)), ("Cancelled", 0));
    assert_eq!(e.balance(&e.usdc, &e.vault.0.clone()).await?, 0);
    e.assert_inv1(&e.usdc).await?;

    // Invariant 7: cancelled subscription can never be charged.
    e.advance_past_next_charge(id).await?;
    e.charge(&bob, id).await?.assert_failure();
    assert_eq!(e.balance(&e.usdc, &merchant).await?, 4 * USDC);

    // Conservation: 5 deposited = 4 to merchant + 1 refunded.
    assert_eq!(e.balance(&e.usdc, &alice).await?, 96 * USDC);
    Ok(())
}

/// Invariant 5: tokens from an unexpected contract are always returned, and a
/// forged `ft_on_transfer` call cannot credit anything.
#[tokio::test]
async fn attack_wrong_token_and_forged_transfer() -> TestResult {
    let e = Env::new().await?;
    let (alice, bob, merchant) = (e.alice.clone(), e.bob.clone(), e.merchant.clone());
    let plan = e.create_plan(&merchant, &e.usdc, USDC).await?;

    // A real but different NEP-141 token.
    let evil = e.deploy_ft("evil").await?;
    e.storage_deposit(&evil, &e.vault.0.clone()).await?;
    e.mint(&evil, &alice, 10 * USDC).await?;
    e.ft_transfer_call(&alice, &evil, 5 * USDC, subscribe_msg(plan, "evil:1", true)).await?.assert_success();
    assert_eq!(e.balance(&evil, &alice).await?, 10 * USDC, "wrong token must be returned");
    assert_eq!(e.balance(&evil, &e.vault.0.clone()).await?, 0);
    assert_eq!(e.view::<u64>(&e.vault, "get_num_subscriptions", json!({})).await?, 0);

    // Legit sub, then wrong-token top-up is returned.
    e.ft_transfer_call(&alice, &e.usdc, 2 * USDC, subscribe_msg(plan, "ok:1", false)).await?.assert_success();
    e.ft_transfer_call(&alice, &evil, 5 * USDC, json!({ "top_up": { "sub_id": 1 } })).await?.assert_success();
    assert_eq!(e.balance(&evil, &alice).await?, 10 * USDC);
    assert_eq!(bal(&e.sub(1).await?), 2 * USDC);

    // Forged: bob calls ft_on_transfer directly claiming 1000 USDC.
    e.call(
        &bob,
        &e.vault,
        "ft_on_transfer",
        json!({ "sender_id": bob, "amount": (1000 * USDC).to_string(), "msg": json!({ "top_up": { "sub_id": 1 } }).to_string() }),
        NearToken::from_yoctonear(0),
        50,
    )
    .await?
    .assert_success();
    assert_eq!(bal(&e.sub(1).await?), 2 * USDC);
    let r = e
        .call(
            &bob,
            &e.vault,
            "ft_on_transfer",
            json!({ "sender_id": bob, "amount": (1000 * USDC).to_string(), "msg": subscribe_msg(plan, "forged", false).to_string() }),
            NearToken::from_yoctonear(0),
            50,
        )
        .await?
        .assert_success();
    assert_eq!(r.json::<String>()?, (1000 * USDC).to_string(), "forged subscribe must be rejected");
    assert_eq!(e.view::<String>(&e.vault, "get_total_held", json!({ "token": e.usdc.0 })).await?, (2 * USDC).to_string());
    e.assert_inv1(&e.usdc).await?;
    Ok(())
}

/// Invariants 2 + 3: the merchant cannot raise the price of an existing
/// subscription; charges only ever go to the snapshot merchant.
#[tokio::test]
async fn attack_merchant_cannot_raise_price() -> TestResult {
    let e = Env::new().await?;
    let (alice, bob, merchant) = (e.alice.clone(), e.bob.clone(), e.merchant.clone());
    let plan = e.create_plan(&merchant, &e.usdc, USDC).await?;
    e.ft_transfer_call(&alice, &e.usdc, 10 * USDC, subscribe_msg(plan, "p:1", true)).await?.assert_success();
    let terms = |s: &Value| (s["amount"].clone(), s["period_ns"].clone(), s["merchant"].clone(), s["token"].clone());
    let before = terms(&e.sub(1).await?);

    // Merchant deactivates the plan and creates a 10x plan; there is no method to edit a sub.
    e.call(&merchant, &e.vault, "set_plan_active", json!({ "plan_id": plan, "active": false }), NearToken::from_yoctonear(0), 30)
        .await?
        .assert_success();
    e.create_plan(&merchant, &e.usdc, 10 * USDC).await?;
    // Stranger cannot toggle the plan.
    e.call(&bob, &e.vault, "set_plan_active", json!({ "plan_id": plan, "active": true }), NearToken::from_yoctonear(0), 30)
        .await?
        .assert_failure();

    e.advance_past_next_charge(1).await?;
    assert!(e.charge(&merchant, 1).await?.assert_success().json::<bool>()?);
    assert_eq!(terms(&e.sub(1).await?), before);
    assert_eq!(e.balance(&e.usdc, &merchant).await?, 2 * USDC, "still the old price");
    assert_eq!(bal(&e.sub(1).await?), 8 * USDC);
    e.assert_inv1(&e.usdc).await?;
    Ok(())
}

/// Invariant 6: a failed outgoing ft_transfer never loses accounting — charge to
/// an unregistered merchant, and refund to an unregistered owner.
#[tokio::test]
async fn attack_failed_transfers_restore_state() -> TestResult {
    let e = Env::new().await?;
    let (alice, bob) = (e.alice.clone(), e.bob.clone());

    // Merchant2 is NOT registered on the token.
    let m2 = e.create_account("merchant2").await?;
    let plan = e.create_plan(&m2, &e.usdc, USDC).await?;
    e.ft_transfer_call(&alice, &e.usdc, 3 * USDC, subscribe_msg(plan, "u:1", false)).await?.assert_success();
    e.advance_past_next_charge(1).await?;
    let before = e.sub(1).await?;

    let r = e.charge(&bob, 1).await?.assert_success();
    assert!(has_event(&r.logs(), "charge_failed"), "{:?}", r.logs());
    assert!(!r.json::<bool>()?);
    let after = e.sub(1).await?;
    assert_eq!(after, before, "state restored exactly");
    assert_eq!(e.balance(&e.usdc, &e.vault.0.clone()).await?, 3 * USDC);
    e.assert_inv1(&e.usdc).await?;

    // Once registered, the same charge succeeds.
    e.storage_deposit(&e.usdc, &m2).await?;
    assert!(e.charge(&bob, 1).await?.assert_success().json::<bool>()?);
    assert_eq!(e.balance(&e.usdc, &m2).await?, USDC);

    // Refund to an owner who unregistered from the token.
    let carol = e.create_account("carol").await?;
    e.mint(&e.usdc, &carol, 2 * USDC).await?;
    e.ft_transfer_call(&carol, &e.usdc, 2 * USDC, subscribe_msg(plan, "u:2", false)).await?.assert_success();
    e.call(&carol, &e.usdc, "storage_unregister", json!({ "force": true }), ONE_YOCTO, 30).await?.assert_success();
    let r = e.call(&carol, &e.vault, "cancel", json!({ "sub_id": 2 }), ONE_YOCTO, 50).await?.assert_success();
    assert!(has_event(&r.logs(), "refund_failed"), "{:?}", r.logs());
    assert!(!r.json::<bool>()?);
    let s = e.sub(2).await?;
    assert_eq!((status(&s), bal(&s)), ("Cancelled", 2 * USDC));
    e.assert_inv1(&e.usdc).await?;

    // Only the owner can retry; after re-registering the refund goes through.
    e.call(&bob, &e.vault, "withdraw_unclaimed", json!({ "sub_id": 2 }), ONE_YOCTO, 50).await?.assert_failure();
    e.storage_deposit(&e.usdc, &carol).await?;
    assert!(e.call(&carol, &e.vault, "withdraw_unclaimed", json!({ "sub_id": 2 }), ONE_YOCTO, 50).await?.assert_success().json::<bool>()?);
    assert_eq!(e.balance(&e.usdc, &carol).await?, 2 * USDC);
    assert_eq!(bal(&e.sub(2).await?), 0);
    e.assert_inv1(&e.usdc).await?;
    Ok(())
}

/// Merchant-initiated cancel refunds the owner, never the merchant (invariant 3).
#[tokio::test]
async fn merchant_cancel_refunds_owner() -> TestResult {
    let e = Env::new().await?;
    let (alice, bob, merchant) = (e.alice.clone(), e.bob.clone(), e.merchant.clone());
    let plan = e.create_plan(&merchant, &e.usdc, USDC).await?;
    e.ft_transfer_call(&alice, &e.usdc, 4 * USDC, subscribe_msg(plan, "mc:1", true)).await?.assert_success();
    e.call(&bob, &e.vault, "merchant_cancel", json!({ "sub_id": 1 }), ONE_YOCTO, 50).await?.assert_failure();
    e.call(&merchant, &e.vault, "merchant_cancel", json!({ "sub_id": 1 }), ONE_YOCTO, 50).await?.assert_success();
    assert_eq!(e.balance(&e.usdc, &merchant).await?, USDC);
    assert_eq!(e.balance(&e.usdc, &alice).await?, 99 * USDC);
    e.assert_inv1(&e.usdc).await?;
    Ok(())
}
