//! Unit tests: msg parsing, storage accounting, status transitions, time gating,
//! and the invariants of docs/PLAN.md §3 at the state-machine level.
//! Cross-contract behaviour (real token balances) is covered in tests/integration.rs.

use super::*;
use near_sdk::mock::MockAction;
use near_sdk::test_utils::{VMContextBuilder, get_created_receipts, get_logs};
use near_sdk::{PromiseResult, RuntimeFeesConfig, test_vm_config, testing_env};

const AMOUNT: u128 = 1_000_000; // 1 USDC (6 decimals)
const PERIOD_SEC: u32 = 300;
const PERIOD_NS: u64 = PERIOD_SEC as u64 * NS_PER_SEC;
const T0: u64 = 1_700_000_000 * NS_PER_SEC;

fn acc(s: &str) -> AccountId {
    s.parse().unwrap()
}
fn vault() -> AccountId {
    acc("vault.near")
}
fn usdc() -> AccountId {
    acc("usdc.near")
}
fn merchant() -> AccountId {
    acc("merchant.near")
}
fn alice() -> AccountId {
    acc("alice.near")
}

fn ctx(pred: &AccountId, deposit: NearToken, ts: u64) -> VMContextBuilder {
    let mut b = VMContextBuilder::new();
    b.current_account_id(vault())
        .predecessor_account_id(pred.clone())
        .signer_account_id(pred.clone())
        .attached_deposit(deposit)
        .account_balance(NearToken::from_near(100))
        .block_timestamp(ts);
    b
}

fn set(pred: &AccountId, deposit: NearToken, ts: u64) {
    testing_env!(ctx(pred, deposit, ts).build());
}

/// Sets up a callback context with the given promise result.
fn set_callback(ts: u64, ok: bool) {
    let res = if ok { PromiseResult::Successful(vec![]) } else { PromiseResult::Failed };
    testing_env!(
        ctx(&vault(), NearToken::from_yoctonear(0), ts).build(),
        test_vm_config(),
        RuntimeFeesConfig::test(),
        Default::default(),
        vec![res],
    );
}

fn setup() -> (Contract, u32) {
    set(&merchant(), NearToken::from_near(1), T0);
    let mut c = Contract::new(acc("owner.near"));
    let plan = c.create_plan(usdc(), U128(AMOUNT), PERIOD_SEC, "Pro".into());
    (c, plan)
}

fn sub_msg(plan_id: u32, ext: &str, charge_now: bool) -> String {
    format!(
        r#"{{"subscribe":{{"plan_id":{plan_id},"external_ref":"{ext}","charge_now":{charge_now},"max_charges":null}}}}"#
    )
}

fn transfer(c: &mut Contract, token: &AccountId, from: &AccountId, amount: u128, msg: &str, ts: u64) -> u128 {
    set(token, NearToken::from_yoctonear(0), ts);
    match c.ft_on_transfer(from.clone(), U128(amount), msg.to_string()) {
        PromiseOrValue::Value(v) => v.0,
        PromiseOrValue::Promise(_) => panic!("unexpected promise"),
    }
}

/// Subscribes alice with `deposit` and `charge_now`; returns sub id (1-based sequence).
fn subscribe(c: &mut Contract, plan: u32, deposit: u128, charge_now: bool) -> u64 {
    let id = c.next_sub_id;
    let refunded = transfer(c, &usdc(), &alice(), deposit, &sub_msg(plan, &format!("wc:x:{id}"), charge_now), T0);
    assert_eq!(refunded, 0, "subscribe rejected: {:?}", get_logs());
    id
}

fn resolve_charge(c: &mut Contract, sub_id: u64, prev_next: u64, ts: u64, ok: bool) -> bool {
    set_callback(ts, ok);
    c.on_charge_resolved(sub_id, U128(AMOUNT), U64(prev_next))
}

fn sum_balances(c: &Contract, token: &AccountId) -> u128 {
    c.subs.values().filter(|s| &s.token == token).map(|s| s.balance.0).sum()
}

/// (receiver_id, method, args JSON) of every function call receipt created so far.
fn calls() -> Vec<(String, String, String)> {
    let mut out = vec![];
    for r in get_created_receipts() {
        for a in r.actions {
            if let MockAction::FunctionCallWeight { method_name, args, .. } = a {
                out.push((
                    r.receiver_id.to_string(),
                    String::from_utf8(method_name).unwrap(),
                    String::from_utf8(args).unwrap(),
                ));
            }
        }
    }
    out
}

// ---------------------------------------------------------------- msg parsing

#[test]
fn parse_msgs() {
    let m: TransferMsg = near_sdk::serde_json::from_str(
        r#"{"subscribe":{"plan_id":1,"owner":"alice.near","external_ref":"wc:ab12:1042","charge_now":true,"max_charges":null}}"#,
    )
    .unwrap();
    assert_eq!(
        m,
        TransferMsg::Subscribe {
            plan_id: 1,
            owner: Some(alice()),
            external_ref: "wc:ab12:1042".into(),
            charge_now: Some(true),
            max_charges: None
        }
    );
    let m: TransferMsg = near_sdk::serde_json::from_str(r#"{"subscribe":{"plan_id":2,"external_ref":"r"}}"#).unwrap();
    assert!(matches!(m, TransferMsg::Subscribe { owner: None, charge_now: None, .. }));
    let m: TransferMsg = near_sdk::serde_json::from_str(r#"{"top_up":{"sub_id":7}}"#).unwrap();
    assert_eq!(m, TransferMsg::TopUp { sub_id: 7 });
    assert!(near_sdk::serde_json::from_str::<TransferMsg>(r#"{"steal":{}}"#).is_err());
    assert!(near_sdk::serde_json::from_str::<TransferMsg>(r#"{"top_up":{"sub_id":7,"x":1}}"#).is_err());
}

#[test]
fn invalid_msg_returns_full_amount() {
    let (mut c, _) = setup();
    assert_eq!(transfer(&mut c, &usdc(), &alice(), 5, "not json", T0), 5);
    assert_eq!(transfer(&mut c, &usdc(), &alice(), 5, r#"{"steal":{}}"#, T0), 5);
    assert_eq!(c.get_num_subscriptions(), 0);
}

// ---------------------------------------------------------------- plans + storage

#[test]
fn create_plan_debits_storage_pool() {
    let (c, plan) = setup();
    let pool = c.get_merchant_storage(merchant());
    assert!(pool < NearToken::from_near(1));
    assert!(pool > NearToken::from_millinear(900));
    let p = c.get_plan(plan).unwrap();
    assert_eq!(p.merchant, merchant());
    assert_eq!(p.amount, U128(AMOUNT));
    assert!(p.active);
    assert_eq!(c.get_plans(merchant()).len(), 1);
    assert!(get_logs().iter().any(|l| l.contains(r#""event":"plan_created""#)));
}

#[test]
#[should_panic(expected = "insufficient storage deposit")]
fn create_plan_without_deposit_fails() {
    set(&merchant(), NearToken::from_yoctonear(0), T0);
    let mut c = Contract::new(acc("owner.near"));
    c.create_plan(usdc(), U128(AMOUNT), PERIOD_SEC, "Pro".into());
}

#[test]
#[should_panic(expected = "period_sec too short")]
fn create_plan_short_period_fails() {
    let (mut c, _) = setup();
    set(&merchant(), NearToken::from_near(1), T0);
    c.create_plan(usdc(), U128(AMOUNT), MIN_PERIOD_SEC - 1, "x".into());
}

#[test]
#[should_panic(expected = "amount must be > 0")]
fn create_plan_zero_amount_fails() {
    let (mut c, _) = setup();
    set(&merchant(), NearToken::from_near(1), T0);
    c.create_plan(usdc(), U128(0), PERIOD_SEC, "x".into());
}

#[test]
#[should_panic(expected = "invalid name length")]
fn create_plan_long_name_fails() {
    let (mut c, _) = setup();
    set(&merchant(), NearToken::from_near(1), T0);
    c.create_plan(usdc(), U128(AMOUNT), PERIOD_SEC, "x".repeat(MAX_NAME_LEN + 1));
}

#[test]
fn subscribe_debits_merchant_pool() {
    let (mut c, plan) = setup();
    let before = c.get_merchant_storage(merchant());
    subscribe(&mut c, plan, 3 * AMOUNT, false);
    assert!(c.get_merchant_storage(merchant()) < before);
}

#[test]
fn subscribe_with_empty_pool_is_rejected_and_rolled_back() {
    // Pool sized to cover exactly the plan, nothing left for subscriptions.
    set(&merchant(), NearToken::from_near(1), T0);
    let mut c = Contract::new(acc("owner.near"));
    let plan = c.create_plan(usdc(), U128(AMOUNT), PERIOD_SEC, "Pro".into());
    let rest = c.get_merchant_storage(merchant());
    c.merchant_storage.insert(merchant(), NearToken::from_yoctonear(0));
    c.merchant_storage.flush();

    set(&usdc(), NearToken::from_yoctonear(0), T0);
    let usage = env::storage_usage();
    let r = c.ft_on_transfer(alice(), U128(AMOUNT), sub_msg(plan, "r1", true));
    assert!(matches!(r, PromiseOrValue::Value(U128(AMOUNT))));
    assert_eq!(env::storage_usage(), usage, "storage not rolled back");
    assert_eq!(c.get_num_subscriptions(), 0);
    assert_eq!(c.next_sub_id, 1);
    assert!(c.get_subscription_by_ref(merchant(), "r1".into()).is_none());
    assert!(c.get_subscriptions_by_owner(alice(), None, None).is_empty());
    assert_eq!(c.get_total_held(usdc()), U128(0));
    assert!(calls().is_empty(), "no transfer may be scheduled");

    // Top the pool back up -> same subscription now succeeds.
    set(&merchant(), rest, T0);
    c.deposit_storage();
    assert_eq!(transfer(&mut c, &usdc(), &alice(), AMOUNT, &sub_msg(plan, "r1", true), T0), 0);
}

// ---------------------------------------------------------------- subscribe

#[test]
fn subscribe_charge_now_charges_first_period_to_merchant() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, 3 * AMOUNT, true);
    let s = c.get_subscription(id).unwrap();
    assert_eq!(s.owner, alice());
    assert_eq!(s.merchant, merchant());
    assert_eq!(s.balance, U128(2 * AMOUNT));
    assert_eq!(s.charges_count, 1);
    assert!(s.charging);
    assert_eq!(s.next_charge_at_ns, U64(T0 + PERIOD_NS));
    assert_eq!(c.get_total_held(usdc()), U128(2 * AMOUNT));

    // Invariant 3: the only outgoing transfer goes to the snapshot merchant.
    let calls = calls();
    let ft: Vec<_> = calls.iter().filter(|(_, m, _)| m == "ft_transfer").collect();
    assert_eq!(ft.len(), 1);
    assert_eq!(ft[0].0, "usdc.near");
    assert!(ft[0].2.contains(r#""receiver_id":"merchant.near""#), "{}", ft[0].2);
    assert!(ft[0].2.contains(&format!(r#""amount":"{AMOUNT}""#)));
    assert!(calls.iter().any(|(r, m, _)| r == "vault.near" && m == "on_charge_resolved"));

    assert!(resolve_charge(&mut c, id, T0, T0, true));
    assert!(!c.get_subscription(id).unwrap().charging);
    assert!(get_logs().iter().any(|l| l.contains(r#""event":"charged""#)));
}

#[test]
fn subscribe_with_explicit_owner() {
    let (mut c, plan) = setup();
    let msg = r#"{"subscribe":{"plan_id":1,"owner":"bob.near","external_ref":"r","charge_now":false}}"#;
    assert_eq!(transfer(&mut c, &usdc(), &acc("relayer.near"), AMOUNT, msg, T0), 0);
    assert_eq!(c.get_subscription(1).unwrap().owner, acc("bob.near"));
    assert_eq!(c.get_subscriptions_by_owner(acc("bob.near"), None, None).len(), 1);
    let _ = plan;
}

#[test]
fn subscribe_without_charge_now_starts_after_one_period() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, AMOUNT, false);
    let s = c.get_subscription(id).unwrap();
    assert_eq!(s.charges_count, 0);
    assert_eq!(s.next_charge_at_ns, U64(T0 + PERIOD_NS));
    assert!(calls().is_empty());
    set(&alice(), NearToken::from_yoctonear(0), T0 + PERIOD_NS - 1);
    assert!(c.get_due(None, None).is_empty());
}

#[test]
fn subscribe_validation_failures_return_full_amount() {
    let (mut c, plan) = setup();
    // Deposit below first charge.
    assert_eq!(transfer(&mut c, &usdc(), &alice(), AMOUNT - 1, &sub_msg(plan, "a", true), T0), AMOUNT - 1);
    // Unknown plan.
    assert_eq!(transfer(&mut c, &usdc(), &alice(), AMOUNT, &sub_msg(99, "a", true), T0), AMOUNT);
    // Empty / too long external_ref.
    assert_eq!(transfer(&mut c, &usdc(), &alice(), AMOUNT, &sub_msg(plan, "", true), T0), AMOUNT);
    let long = "x".repeat(MAX_REF_LEN + 1);
    assert_eq!(transfer(&mut c, &usdc(), &alice(), AMOUNT, &sub_msg(plan, &long, true), T0), AMOUNT);
    // max_charges = 0.
    let m = r#"{"subscribe":{"plan_id":1,"external_ref":"z","max_charges":0}}"#;
    assert_eq!(transfer(&mut c, &usdc(), &alice(), AMOUNT, m, T0), AMOUNT);
    // Inactive plan.
    set(&merchant(), NearToken::from_yoctonear(0), T0);
    c.set_plan_active(plan, false);
    assert_eq!(transfer(&mut c, &usdc(), &alice(), AMOUNT, &sub_msg(plan, "a", true), T0), AMOUNT);
    // Zero amount.
    assert_eq!(transfer(&mut c, &usdc(), &alice(), 0, &sub_msg(plan, "a", true), T0), 0);
    assert_eq!(c.get_num_subscriptions(), 0);
    assert_eq!(c.get_total_held(usdc()), U128(0));
}

#[test]
fn external_ref_unique_per_merchant() {
    let (mut c, plan) = setup();
    assert_eq!(transfer(&mut c, &usdc(), &alice(), AMOUNT, &sub_msg(plan, "dup", false), T0), 0);
    assert_eq!(transfer(&mut c, &usdc(), &alice(), AMOUNT, &sub_msg(plan, "dup", false), T0), AMOUNT);
    // Another merchant may reuse the same ref.
    set(&acc("other.near"), NearToken::from_near(1), T0);
    let plan2 = c.create_plan(usdc(), U128(AMOUNT), PERIOD_SEC, "Other".into());
    assert_eq!(transfer(&mut c, &usdc(), &alice(), AMOUNT, &sub_msg(plan2, "dup", false), T0), 0);
    assert_eq!(c.get_subscription_by_ref(acc("other.near"), "dup".into()).unwrap().plan_id, plan2);
}

// ---------------------------------------------------------------- invariant 5: unexpected token

#[test]
fn inv5_wrong_token_is_returned() {
    let (mut c, plan) = setup();
    let evil = acc("evil.near");
    assert_eq!(transfer(&mut c, &evil, &alice(), 10 * AMOUNT, &sub_msg(plan, "e", true), T0), 10 * AMOUNT);
    assert_eq!(c.get_num_subscriptions(), 0);

    let id = subscribe(&mut c, plan, AMOUNT, false);
    let top = format!(r#"{{"top_up":{{"sub_id":{id}}}}}"#);
    assert_eq!(transfer(&mut c, &evil, &alice(), 10 * AMOUNT, &top, T0), 10 * AMOUNT);
    assert_eq!(c.get_subscription(id).unwrap().balance, U128(AMOUNT));
    assert_eq!(c.get_total_held(evil), U128(0));
    // Top-up of a non-existent sub is returned too.
    assert_eq!(transfer(&mut c, &usdc(), &alice(), 5, r#"{"top_up":{"sub_id":999}}"#, T0), 5);
}

// ---------------------------------------------------------------- invariant 4 + time gating

#[test]
fn inv4_at_most_one_charge_per_period() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, 10 * AMOUNT, true);
    resolve_charge(&mut c, id, T0, T0, true);

    // Same block, mid-period and one ns before the boundary: all refused.
    for ts in [T0, T0 + PERIOD_NS / 2, T0 + PERIOD_NS - 1] {
        set(&acc("anyone.near"), NearToken::from_yoctonear(0), ts);
        let r = std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
            let _ = c.charge(id);
        }));
        assert!(r.is_err(), "charge at {ts} must be refused");
    }
    set(&acc("anyone.near"), NearToken::from_yoctonear(0), T0 + PERIOD_NS);
    assert_eq!(c.get_due(None, None), vec![id]);
}

#[test]
#[should_panic(expected = "not due yet")]
fn early_charge_panics() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, 10 * AMOUNT, true);
    resolve_charge(&mut c, id, T0, T0, true);
    set(&acc("anyone.near"), NearToken::from_yoctonear(0), T0 + PERIOD_NS - 1);
    let _ = c.charge(id);
}

#[test]
#[should_panic(expected = "charge already in flight")]
fn concurrent_charge_is_refused() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, 10 * AMOUNT, false);
    set(&acc("anyone.near"), NearToken::from_yoctonear(0), T0 + 10 * PERIOD_NS);
    let _ = c.charge(id);
    // Callback not yet executed; even far in the future no second charge.
    set(&acc("anyone.near"), NearToken::from_yoctonear(0), T0 + 100 * PERIOD_NS);
    let _ = c.charge(id);
}

#[test]
fn next_charge_anchored_to_now_after_downtime() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, 10 * AMOUNT, true);
    resolve_charge(&mut c, id, T0, T0, true);
    // Charger offline for 5 periods: only one charge, next one a full period later.
    let late = T0 + 5 * PERIOD_NS + 7;
    set(&acc("anyone.near"), NearToken::from_yoctonear(0), late);
    let _ = c.charge(id);
    resolve_charge(&mut c, id, T0 + PERIOD_NS, late, true);
    let s = c.get_subscription(id).unwrap();
    assert_eq!(s.charges_count, 2);
    assert_eq!(s.next_charge_at_ns, U64(late + PERIOD_NS));
    assert!(c.get_due(None, None).is_empty());
}

#[test]
#[should_panic(expected = "max charges reached")]
fn max_charges_enforced() {
    let (mut c, plan) = setup();
    let m = format!(r#"{{"subscribe":{{"plan_id":{plan},"external_ref":"m","charge_now":true,"max_charges":1}}}}"#);
    assert_eq!(transfer(&mut c, &usdc(), &alice(), 5 * AMOUNT, &m, T0), 0);
    resolve_charge(&mut c, 1, T0, T0, true);
    set(&acc("anyone.near"), NearToken::from_yoctonear(0), T0 + PERIOD_NS);
    assert!(c.get_due(None, None).is_empty());
    let _ = c.charge(1);
}

// ---------------------------------------------------------------- PastDue transitions

#[test]
fn past_due_then_top_up_reactivates() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, AMOUNT, true); // exactly one period
    resolve_charge(&mut c, id, T0, T0, true);
    let t1 = T0 + PERIOD_NS;
    set(&acc("anyone.near"), NearToken::from_yoctonear(0), t1);
    assert_eq!(c.get_due(None, None), vec![id], "active sub with empty balance is due (to mark PastDue)");
    let r = c.charge(id);
    assert!(matches!(r, PromiseOrValue::Value(false)));
    let s = c.get_subscription(id).unwrap();
    assert_eq!(s.status, SubStatus::PastDue);
    assert_eq!(s.charges_count, 1);
    assert!(get_logs().iter().any(|l| l.contains(r#""event":"past_due""#)));
    assert!(c.get_due(None, None).is_empty(), "past-due without funds is not due");

    // Partial top-up keeps PastDue; full top-up reactivates.
    let top = format!(r#"{{"top_up":{{"sub_id":{id}}}}}"#);
    assert_eq!(transfer(&mut c, &usdc(), &alice(), AMOUNT / 2, &top, t1), 0);
    assert_eq!(c.get_subscription(id).unwrap().status, SubStatus::PastDue);
    assert_eq!(transfer(&mut c, &usdc(), &alice(), AMOUNT / 2, &top, t1), 0);
    assert_eq!(c.get_subscription(id).unwrap().status, SubStatus::Active);

    set(&acc("anyone.near"), NearToken::from_yoctonear(0), t1);
    assert!(matches!(c.charge(id), PromiseOrValue::Promise(_)));
    resolve_charge(&mut c, id, t1, t1, true);
    let s = c.get_subscription(id).unwrap();
    assert_eq!((s.charges_count, s.balance), (2, U128(0)));
}

// ---------------------------------------------------------------- invariant 6: failed transfer restores

#[test]
fn inv6_failed_charge_restores_accounting() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, 3 * AMOUNT, false);
    let before = c.get_subscription(id).unwrap();
    let t = T0 + PERIOD_NS + 5;
    set(&acc("anyone.near"), NearToken::from_yoctonear(0), t);
    let _ = c.charge(id);
    assert_eq!(c.get_total_held(usdc()), U128(2 * AMOUNT));

    assert!(!resolve_charge(&mut c, id, before.next_charge_at_ns.0, t, false));
    let after = c.get_subscription(id).unwrap();
    assert_eq!(after, before, "state must equal pre-charge state");
    assert_eq!(c.get_total_held(usdc()), U128(3 * AMOUNT));
    assert!(get_logs().iter().any(|l| l.contains(r#""event":"charge_failed""#)));
    // And it is chargeable again.
    set(&acc("anyone.near"), NearToken::from_yoctonear(0), t);
    assert_eq!(c.get_due(None, None), vec![id]);
}

#[test]
fn inv6_failed_refund_restores_and_withdraw_unclaimed_retries() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, 3 * AMOUNT, false);
    set(&alice(), NearToken::from_yoctonear(1), T0);
    let _ = c.cancel(id);
    assert_eq!(c.get_subscription(id).unwrap().balance, U128(0));
    assert_eq!(c.get_total_held(usdc()), U128(0));

    set_callback(T0, false);
    assert!(!c.on_refund_resolved(id, U128(3 * AMOUNT)));
    assert_eq!(c.get_subscription(id).unwrap().balance, U128(3 * AMOUNT));
    assert_eq!(c.get_total_held(usdc()), U128(3 * AMOUNT));
    assert!(get_logs().iter().any(|l| l.contains(r#""event":"refund_failed""#)));

    set(&alice(), NearToken::from_yoctonear(1), T0);
    let _ = c.withdraw_unclaimed(id);
    let ft: Vec<_> = calls().into_iter().filter(|(_, m, _)| m == "ft_transfer").collect();
    assert!(ft.last().unwrap().2.contains(r#""receiver_id":"alice.near""#));
    set_callback(T0, true);
    assert!(c.on_refund_resolved(id, U128(3 * AMOUNT)));
    assert_eq!(c.get_subscription(id).unwrap().balance, U128(0));
}

#[test]
fn charge_failing_after_cancel_is_withdrawable() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, 3 * AMOUNT, true); // charge #1 in flight
    set(&alice(), NearToken::from_yoctonear(1), T0);
    let _ = c.cancel(id); // refunds 2 * AMOUNT
    set_callback(T0, true);
    c.on_refund_resolved(id, U128(2 * AMOUNT));
    resolve_charge(&mut c, id, T0, T0, false); // charge #1 fails afterwards
    let s = c.get_subscription(id).unwrap();
    assert_eq!((s.status, s.balance), (SubStatus::Cancelled, U128(AMOUNT)));
    set(&alice(), NearToken::from_yoctonear(1), T0);
    let _ = c.withdraw_unclaimed(id);
    assert_eq!(c.get_subscription(id).unwrap().balance, U128(0));
}

#[test]
#[should_panic(expected = "Requires attached deposit of exactly 1 yoctoNEAR")]
fn withdraw_unclaimed_requires_one_yocto() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, AMOUNT, false);
    set(&alice(), NearToken::from_yoctonear(0), T0);
    let _ = c.withdraw_unclaimed(id);
}

// ---------------------------------------------------------------- invariant 7 + cancel auth

#[test]
#[should_panic(expected = "subscription cancelled")]
fn inv7_cancelled_cannot_be_charged() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, 3 * AMOUNT, false);
    set(&alice(), NearToken::from_yoctonear(1), T0);
    let _ = c.cancel(id);
    set(&acc("anyone.near"), NearToken::from_yoctonear(0), T0 + 10 * PERIOD_NS);
    assert!(c.get_due(None, None).is_empty());
    let _ = c.charge(id);
}

#[test]
fn inv7_cancelled_cannot_be_topped_up() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, AMOUNT, false);
    set(&merchant(), NearToken::from_yoctonear(1), T0);
    let _ = c.merchant_cancel(id);
    let top = format!(r#"{{"top_up":{{"sub_id":{id}}}}}"#);
    assert_eq!(transfer(&mut c, &usdc(), &alice(), AMOUNT, &top, T0), AMOUNT);
}

#[test]
fn cancel_refunds_full_balance_to_owner() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, 3 * AMOUNT, false);
    set(&alice(), NearToken::from_yoctonear(1), T0);
    let _ = c.cancel(id);
    let s = c.get_subscription(id).unwrap();
    assert_eq!((s.status, s.balance), (SubStatus::Cancelled, U128(0)));
    // Invariant 3: refunds go only to the owner.
    let ft: Vec<_> = calls().into_iter().filter(|(_, m, _)| m == "ft_transfer").collect();
    assert_eq!(ft.len(), 1);
    assert!(ft[0].2.contains(r#""receiver_id":"alice.near""#));
    assert!(ft[0].2.contains(&format!(r#""amount":"{}""#, 3 * AMOUNT)));
    assert!(get_logs().iter().any(|l| l.contains(r#""event":"cancelled""#)));
}

#[test]
fn merchant_cancel_refunds_owner() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, 2 * AMOUNT, false);
    set(&merchant(), NearToken::from_yoctonear(1), T0);
    let _ = c.merchant_cancel(id);
    let ft: Vec<_> = calls().into_iter().filter(|(_, m, _)| m == "ft_transfer").collect();
    assert!(ft[0].2.contains(r#""receiver_id":"alice.near""#));
}

#[test]
#[should_panic(expected = "only the subscription owner")]
fn cancel_by_non_owner_fails() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, AMOUNT, false);
    set(&merchant(), NearToken::from_yoctonear(1), T0);
    let _ = c.cancel(id);
}

#[test]
#[should_panic(expected = "only the subscription merchant")]
fn merchant_cancel_by_stranger_fails() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, AMOUNT, false);
    set(&alice(), NearToken::from_yoctonear(1), T0);
    let _ = c.merchant_cancel(id);
}

#[test]
#[should_panic(expected = "Requires attached deposit of exactly 1 yoctoNEAR")]
fn cancel_requires_one_yocto() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, AMOUNT, false);
    set(&alice(), NearToken::from_yoctonear(0), T0);
    let _ = c.cancel(id);
}

#[test]
#[should_panic(expected = "already cancelled")]
fn double_cancel_fails() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, AMOUNT, false);
    set(&alice(), NearToken::from_yoctonear(1), T0);
    let _ = c.cancel(id);
    let _ = c.cancel(id);
}

#[test]
#[should_panic(expected = "only the plan merchant")]
fn set_plan_active_by_stranger_fails() {
    let (mut c, plan) = setup();
    set(&alice(), NearToken::from_yoctonear(0), T0);
    c.set_plan_active(plan, false);
}

// ---------------------------------------------------------------- invariant 2: snapshot

#[test]
fn inv2_subscription_terms_never_change() {
    let (mut c, plan) = setup();
    let id = subscribe(&mut c, plan, 10 * AMOUNT, true);
    resolve_charge(&mut c, id, T0, T0, true);
    let terms = |s: &Subscription| (s.amount, s.period_ns, s.merchant.clone(), s.token.clone());
    let t0 = terms(&c.get_subscription(id).unwrap());

    // Merchant deactivates the plan and creates a pricier one: existing sub unaffected.
    set(&merchant(), NearToken::from_near(1), T0);
    c.set_plan_active(plan, false);
    c.create_plan(usdc(), U128(100 * AMOUNT), MIN_PERIOD_SEC, "Pricier".into());
    let t = T0 + PERIOD_NS;
    set(&acc("anyone.near"), NearToken::from_yoctonear(0), t);
    let _ = c.charge(id);
    let ft: Vec<_> = calls().into_iter().filter(|(_, m, _)| m == "ft_transfer").collect();
    assert!(ft[0].2.contains(&format!(r#""amount":"{AMOUNT}""#)), "old price charged");
    assert!(ft[0].2.contains(r#""receiver_id":"merchant.near""#));
    resolve_charge(&mut c, id, T0 + PERIOD_NS, t, true);
    // Top-up doesn't change terms either.
    let top = format!(r#"{{"top_up":{{"sub_id":{id}}}}}"#);
    transfer(&mut c, &usdc(), &alice(), AMOUNT, &top, t);
    let s = c.get_subscription(id).unwrap();
    assert_eq!(terms(&s), t0);
    assert_eq!(s.charges_count, 2);
}

// ---------------------------------------------------------------- invariant 1: accounting

#[test]
fn inv1_total_held_equals_sum_of_balances_through_lifecycle() {
    let (mut c, plan) = setup();
    let check = |c: &Contract| assert_eq!(c.get_total_held(usdc()).0, sum_balances(c, &usdc()));
    let a = subscribe(&mut c, plan, 5 * AMOUNT, true);
    check(&c);
    let b = subscribe(&mut c, plan, 2 * AMOUNT, false);
    check(&c);
    resolve_charge(&mut c, a, T0, T0, true);
    check(&c);
    let t = T0 + PERIOD_NS;
    set(&acc("anyone.near"), NearToken::from_yoctonear(0), t);
    let _ = c.charge(a);
    check(&c);
    resolve_charge(&mut c, a, T0 + PERIOD_NS, t, false);
    check(&c);
    set(&acc("anyone.near"), NearToken::from_yoctonear(0), t);
    let _ = c.charge(b);
    resolve_charge(&mut c, b, T0 + PERIOD_NS, t, true);
    check(&c);
    set(&alice(), NearToken::from_yoctonear(1), t);
    let _ = c.cancel(a);
    check(&c);
    set_callback(t, false);
    c.on_refund_resolved(a, U128(4 * AMOUNT));
    check(&c);
    // Deposited 7, charged 2 (a#1, b#1) -> 5 held.
    assert_eq!(c.get_total_held(usdc()), U128(5 * AMOUNT));
}

// ---------------------------------------------------------------- views

#[test]
fn views_and_pagination() {
    let (mut c, plan) = setup();
    for _ in 0..5 {
        subscribe(&mut c, plan, AMOUNT, false);
    }
    assert_eq!(c.get_subscriptions_by_owner(alice(), Some(1), Some(2)).len(), 2);
    assert_eq!(c.get_subscriptions_by_owner(alice(), None, None).len(), 5);
    assert!(c.get_subscriptions_by_owner(acc("nobody.near"), None, None).is_empty());
    set(&acc("anyone.near"), NearToken::from_yoctonear(0), T0 + PERIOD_NS);
    assert_eq!(c.get_due(Some(3), Some(10)), vec![4, 5]);
    assert_eq!(c.get_subscription_by_ref(merchant(), "wc:x:3".into()).unwrap().id, 3);
    assert!(c.get_subscription(42).is_none());
}

#[test]
fn event_format_is_nep297() {
    let (mut c, plan) = setup();
    subscribe(&mut c, plan, AMOUNT, false);
    let log = get_logs().into_iter().find(|l| l.contains("subscribed")).unwrap();
    let json = log.strip_prefix("EVENT_JSON:").expect("NEP-297 prefix");
    let v: near_sdk::serde_json::Value = near_sdk::serde_json::from_str(json).unwrap();
    assert_eq!(v["standard"], "recurra");
    assert_eq!(v["version"], "1.0.0");
    assert_eq!(v["event"], "subscribed");
    assert_eq!(v["data"]["external_ref"], "wc:x:1");
    assert_eq!(v["data"]["owner"], "alice.near");
}
