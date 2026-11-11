//! Recurra Vault — customer-signed recurring payment rules for NEP-141 tokens.
//!
//! A merchant publishes a plan (token, amount, period). A customer funds a
//! subscription with `ft_transfer_call`; the contract then allows at most one
//! charge of `amount` per `period` to the plan's merchant, and refunds the
//! remaining balance to the customer on cancel. `charge` is permissionless:
//! the rule, not the caller, decides whether money moves.
//!
//! See docs/PLAN.md §3 for the specification and invariants.

mod events;

use events::RecurraEvent;
use near_sdk::json_types::{U64, U128};
use near_sdk::serde::{Deserialize, Serialize};
use near_sdk::store::{IterableMap, IterableSet, LookupMap};
use near_sdk::{
    AccountId, BorshStorageKey, CryptoHash, Gas, NearToken, PanicOnDefault, Promise, PromiseError,
    PromiseOrValue, assert_one_yocto, env, ext_contract, log, near, require,
};

pub const MIN_PERIOD_SEC: u32 = 60;
pub const MAX_NAME_LEN: usize = 64;
pub const MAX_REF_LEN: usize = 96;
pub const MAX_PAGE: u32 = 100;
const NS_PER_SEC: u64 = 1_000_000_000;
const GAS_FOR_FT_TRANSFER: Gas = Gas::from_tgas(10);
const GAS_FOR_RESOLVE: Gas = Gas::from_tgas(10);
const ONE_YOCTO: NearToken = NearToken::from_yoctonear(1);

#[ext_contract(ext_ft)]
#[allow(dead_code)]
trait FungibleToken {
    fn ft_transfer(&mut self, receiver_id: AccountId, amount: U128, memo: Option<String>);
}

#[near]
#[derive(BorshStorageKey)]
enum StorageKey {
    Plans,
    Subs,
    SubsByOwner,
    SubsByOwnerInner { owner_hash: CryptoHash },
    SubByRef,
    MerchantStorage,
    TotalHeld,
}

#[near(serializers = [borsh, json])]
#[derive(Clone, Debug, PartialEq)]
pub struct Plan {
    pub merchant: AccountId,
    /// NEP-141 token contract.
    pub token: AccountId,
    /// Amount per period, base units.
    pub amount: U128,
    pub period_sec: u32,
    pub name: String,
    /// Inactive = no new subscriptions; existing ones continue.
    pub active: bool,
}

#[near(serializers = [borsh, json])]
#[derive(Clone, Copy, Debug, PartialEq)]
pub enum SubStatus {
    Active,
    PastDue,
    Cancelled,
}

#[near(serializers = [borsh, json])]
#[derive(Clone, Debug, PartialEq)]
pub struct Subscription {
    pub id: u64,
    pub plan_id: u32,
    /// Can cancel; receives refunds.
    pub owner: AccountId,
    /// Snapshot from plan; never changes.
    pub merchant: AccountId,
    /// Snapshot from plan; never changes.
    pub token: AccountId,
    /// Snapshot from plan; the merchant can never raise the price of an existing subscription.
    pub amount: U128,
    /// Snapshot from plan.
    pub period_ns: U64,
    pub balance: U128,
    pub next_charge_at_ns: U64,
    pub charges_count: u32,
    pub max_charges: Option<u32>,
    pub status: SubStatus,
    pub external_ref: String,
    pub created_at_ns: U64,
    /// True while an outgoing charge transfer awaits its callback. Blocks a
    /// second concurrent charge of the same subscription.
    pub charging: bool,
}

/// `msg` of `ft_transfer_call` with this vault as receiver.
#[derive(Serialize, Deserialize, Debug, PartialEq)]
#[serde(crate = "near_sdk::serde", rename_all = "snake_case", deny_unknown_fields)]
pub enum TransferMsg {
    Subscribe {
        plan_id: u32,
        owner: Option<AccountId>,
        external_ref: String,
        /// Default `true`: charge the first period immediately.
        charge_now: Option<bool>,
        max_charges: Option<u32>,
    },
    TopUp {
        sub_id: u64,
    },
}

#[near(contract_state)]
#[derive(PanicOnDefault)]
pub struct Contract {
    owner: AccountId,
    plans: IterableMap<u32, Plan>,
    subs: IterableMap<u64, Subscription>,
    subs_by_owner: LookupMap<AccountId, IterableSet<u64>>,
    /// Key: "<merchant>|<external_ref>" ('|' cannot appear in an account id).
    sub_by_ref: LookupMap<String, u64>,
    /// Prepaid storage pool per merchant.
    merchant_storage: LookupMap<AccountId, NearToken>,
    /// Sum of subscription balances per token (excludes amounts in flight).
    total_held: LookupMap<AccountId, U128>,
    next_plan_id: u32,
    next_sub_id: u64,
}

fn ref_key(merchant: &AccountId, external_ref: &str) -> String {
    format!("{merchant}|{external_ref}")
}

/// Success of the single promise this callback is attached to. A result that is
/// merely too long is still a successful transfer.
fn promise_succeeded() -> bool {
    !matches!(env::promise_result_checked(0, 0), Err(PromiseError::Failed))
}

#[near]
impl Contract {
    #[init]
    pub fn new(owner: AccountId) -> Self {
        Self {
            owner,
            plans: IterableMap::new(StorageKey::Plans),
            subs: IterableMap::new(StorageKey::Subs),
            subs_by_owner: LookupMap::new(StorageKey::SubsByOwner),
            sub_by_ref: LookupMap::new(StorageKey::SubByRef),
            merchant_storage: LookupMap::new(StorageKey::MerchantStorage),
            total_held: LookupMap::new(StorageKey::TotalHeld),
            next_plan_id: 1,
            next_sub_id: 1,
        }
    }

    // ------------------------------------------------------------------ merchant

    /// Creates a plan. The attached deposit is credited to the caller's storage
    /// pool, which pays for the plan and for every future subscription to it.
    #[payable]
    pub fn create_plan(
        &mut self,
        token: AccountId,
        amount: U128,
        period_sec: u32,
        name: String,
    ) -> u32 {
        require!(amount.0 > 0, "amount must be > 0");
        require!(period_sec >= MIN_PERIOD_SEC, "period_sec too short");
        require!(!name.is_empty() && name.len() <= MAX_NAME_LEN, "invalid name length");

        let merchant = env::predecessor_account_id();
        self.credit_storage(&merchant, env::attached_deposit());

        let plan_id = self.next_plan_id;
        let before = env::storage_usage();
        self.plans.insert(
            plan_id,
            Plan {
                merchant: merchant.clone(),
                token: token.clone(),
                amount,
                period_sec,
                name,
                active: true,
            },
        );
        self.plans.flush();
        let used = env::storage_usage().saturating_sub(before);
        require!(self.try_debit_storage(&merchant, used), "insufficient storage deposit");
        self.next_plan_id += 1;

        RecurraEvent::PlanCreated { plan_id, merchant, token, amount, period_sec }.emit();
        plan_id
    }

    /// Tops up the caller's storage pool.
    #[payable]
    pub fn deposit_storage(&mut self) -> NearToken {
        let deposit = env::attached_deposit();
        require!(!deposit.is_zero(), "attach a deposit");
        let merchant = env::predecessor_account_id();
        self.credit_storage(&merchant, deposit);
        self.get_merchant_storage(merchant)
    }

    pub fn set_plan_active(&mut self, plan_id: u32, active: bool) {
        let plan = self.plans.get_mut(&plan_id).unwrap_or_else(|| env::panic_str("plan not found"));
        require!(plan.merchant == env::predecessor_account_id(), "only the plan merchant");
        plan.active = active;
    }

    // ------------------------------------------------------------------ NEP-141 receiver

    /// Called by a token contract on `ft_transfer_call`. Never panics after the
    /// message is parsed: every rejection returns the full amount to the sender.
    pub fn ft_on_transfer(
        &mut self,
        sender_id: AccountId,
        amount: U128,
        msg: String,
    ) -> PromiseOrValue<U128> {
        let token = env::predecessor_account_id();
        let parsed: TransferMsg = match near_sdk::serde_json::from_str(&msg) {
            Ok(m) => m,
            Err(_) => return reject(amount, "invalid msg"),
        };
        if amount.0 == 0 {
            return reject(amount, "zero amount");
        }
        let res = match parsed {
            TransferMsg::Subscribe { plan_id, owner, external_ref, charge_now, max_charges } => self
                .internal_subscribe(
                    &token,
                    plan_id,
                    owner.unwrap_or(sender_id),
                    external_ref,
                    charge_now.unwrap_or(true),
                    max_charges,
                    amount.0,
                ),
            TransferMsg::TopUp { sub_id } => self.internal_top_up(&token, sub_id, amount.0),
        };
        match res {
            Ok(()) => PromiseOrValue::Value(U128(0)),
            Err(reason) => reject(amount, reason),
        }
    }

    // ------------------------------------------------------------------ charging

    /// Permissionless. Charges one period if the subscription's rule allows it.
    /// Panics if not chargeable (not due, cancelled, max reached, in flight).
    /// Returns `false` (and marks PastDue) if the balance is insufficient.
    pub fn charge(&mut self, sub_id: u64) -> PromiseOrValue<bool> {
        self.internal_charge(sub_id)
    }

    #[private]
    pub fn on_charge_resolved(&mut self, sub_id: u64, amount: U128, prev_next_charge_at_ns: U64) -> bool {
        let ok = promise_succeeded();
        let sub = self.subs.get_mut(&sub_id).unwrap_or_else(|| env::panic_str("sub not found"));
        sub.charging = false;
        if ok {
            RecurraEvent::Charged {
                sub_id,
                plan_id: sub.plan_id,
                merchant: sub.merchant.clone(),
                owner: sub.owner.clone(),
                amount,
                charge_number: sub.charges_count,
                external_ref: sub.external_ref.clone(),
            }
            .emit();
            return true;
        }
        // Restore accounting exactly as before the charge.
        sub.balance = U128(sub.balance.0 + amount.0);
        sub.charges_count -= 1;
        sub.next_charge_at_ns = prev_next_charge_at_ns;
        let (token, merchant) = (sub.token.clone(), sub.merchant.clone());
        self.add_held(&token, amount.0);
        RecurraEvent::ChargeFailed { sub_id, merchant, amount }.emit();
        false
    }

    // ------------------------------------------------------------------ cancel / refund

    /// Owner cancels and gets the full remaining balance back.
    #[payable]
    pub fn cancel(&mut self, sub_id: u64) -> PromiseOrValue<bool> {
        assert_one_yocto();
        let sub = self.get_sub(sub_id);
        require!(sub.owner == env::predecessor_account_id(), "only the subscription owner");
        self.internal_cancel(sub_id)
    }

    /// Merchant cancels; the remaining balance is refunded to the owner.
    #[payable]
    pub fn merchant_cancel(&mut self, sub_id: u64) -> PromiseOrValue<bool> {
        assert_one_yocto();
        let sub = self.get_sub(sub_id);
        require!(sub.merchant == env::predecessor_account_id(), "only the subscription merchant");
        self.internal_cancel(sub_id)
    }

    /// Retries the refund of a cancelled subscription whose refund failed
    /// (or whose in-flight charge failed after cancellation).
    #[payable]
    pub fn withdraw_unclaimed(&mut self, sub_id: u64) -> PromiseOrValue<bool> {
        assert_one_yocto();
        let sub = self.get_sub(sub_id);
        require!(sub.owner == env::predecessor_account_id(), "only the subscription owner");
        require!(sub.status == SubStatus::Cancelled, "subscription not cancelled");
        require!(sub.balance.0 > 0, "nothing to withdraw");
        self.internal_refund(sub_id).into()
    }

    #[private]
    pub fn on_refund_resolved(&mut self, sub_id: u64, amount: U128) -> bool {
        if promise_succeeded() {
            return true;
        }
        let sub = self.subs.get_mut(&sub_id).unwrap_or_else(|| env::panic_str("sub not found"));
        sub.balance = U128(sub.balance.0 + amount.0);
        let (token, owner) = (sub.token.clone(), sub.owner.clone());
        self.add_held(&token, amount.0);
        RecurraEvent::RefundFailed { sub_id, owner, amount }.emit();
        false
    }

    // ------------------------------------------------------------------ views

    pub fn get_owner(&self) -> AccountId {
        self.owner.clone()
    }

    pub fn get_plan(&self, plan_id: u32) -> Option<Plan> {
        self.plans.get(&plan_id).cloned()
    }

    /// Returns `(plan_id, plan)` pairs of a merchant.
    pub fn get_plans(&self, merchant: AccountId) -> Vec<(u32, Plan)> {
        self.plans
            .iter()
            .filter(|(_, p)| p.merchant == merchant)
            .map(|(id, p)| (*id, p.clone()))
            .collect()
    }

    pub fn get_subscription(&self, sub_id: u64) -> Option<Subscription> {
        self.subs.get(&sub_id).cloned()
    }

    pub fn get_subscriptions_by_owner(
        &self,
        owner: AccountId,
        from_index: Option<u32>,
        limit: Option<u32>,
    ) -> Vec<Subscription> {
        let Some(ids) = self.subs_by_owner.get(&owner) else {
            return vec![];
        };
        ids.iter()
            .skip(from_index.unwrap_or(0) as usize)
            .take(limit.unwrap_or(MAX_PAGE).min(MAX_PAGE) as usize)
            .filter_map(|id| self.subs.get(id).cloned())
            .collect()
    }

    pub fn get_subscription_by_ref(&self, merchant: AccountId, external_ref: String) -> Option<Subscription> {
        self.sub_by_ref
            .get(&ref_key(&merchant, &external_ref))
            .and_then(|id| self.subs.get(id).cloned())
    }

    /// Ids (within the page `[from_index, from_index+limit)` of all subscriptions)
    /// on which `charge` would currently succeed or move the subscription to PastDue.
    pub fn get_due(&self, from_index: Option<u32>, limit: Option<u32>) -> Vec<u64> {
        let now = env::block_timestamp();
        self.subs
            .values()
            .skip(from_index.unwrap_or(0) as usize)
            .take(limit.unwrap_or(MAX_PAGE).min(MAX_PAGE) as usize)
            .filter(|s| is_due(s, now))
            .map(|s| s.id)
            .collect()
    }

    pub fn get_merchant_storage(&self, merchant: AccountId) -> NearToken {
        self.merchant_storage.get(&merchant).copied().unwrap_or(NearToken::from_yoctonear(0))
    }

    /// Sum of all subscription balances in `token` (base units, excludes in-flight transfers).
    pub fn get_total_held(&self, token: AccountId) -> U128 {
        self.total_held.get(&token).copied().unwrap_or(U128(0))
    }

    pub fn get_num_subscriptions(&self) -> u64 {
        self.subs.len() as u64
    }
}

fn reject(amount: U128, reason: &str) -> PromiseOrValue<U128> {
    log!("recurra: rejected transfer: {}", reason);
    PromiseOrValue::Value(amount)
}

fn is_due(s: &Subscription, now: u64) -> bool {
    s.status != SubStatus::Cancelled
        && !s.charging
        && now >= s.next_charge_at_ns.0
        && s.max_charges.is_none_or(|m| s.charges_count < m)
        // A PastDue sub is only worth retrying once topped up.
        && (s.status == SubStatus::Active || s.balance.0 >= s.amount.0)
}

// Internal helpers (not exported).
impl Contract {
    fn get_sub(&self, sub_id: u64) -> &Subscription {
        self.subs.get(&sub_id).unwrap_or_else(|| env::panic_str("subscription not found"))
    }

    fn credit_storage(&mut self, merchant: &AccountId, amount: NearToken) {
        if amount.is_zero() {
            return;
        }
        let cur = self.get_merchant_storage(merchant.clone());
        self.merchant_storage.insert(merchant.clone(), cur.saturating_add(amount));
    }

    /// Debits `bytes` of storage from the merchant's pool. Returns false (and
    /// changes nothing) if the pool is insufficient.
    fn try_debit_storage(&mut self, merchant: &AccountId, bytes: u64) -> bool {
        let cost = env::storage_byte_cost().saturating_mul(bytes as u128);
        let cur = self.get_merchant_storage(merchant.clone());
        match cur.checked_sub(cost) {
            Some(rest) => {
                self.merchant_storage.insert(merchant.clone(), rest);
                true
            }
            None => false,
        }
    }

    fn add_held(&mut self, token: &AccountId, amount: u128) {
        let cur = self.get_total_held(token.clone()).0;
        self.total_held.insert(token.clone(), U128(cur + amount));
    }

    fn sub_held(&mut self, token: &AccountId, amount: u128) {
        let cur = self.get_total_held(token.clone()).0;
        self.total_held.insert(token.clone(), U128(cur - amount));
    }

    #[allow(clippy::too_many_arguments)]
    fn internal_subscribe(
        &mut self,
        token: &AccountId,
        plan_id: u32,
        owner: AccountId,
        external_ref: String,
        charge_now: bool,
        max_charges: Option<u32>,
        amount: u128,
    ) -> Result<(), &'static str> {
        let plan = self.plans.get(&plan_id).cloned().ok_or("plan not found")?;
        if &plan.token != token {
            return Err("wrong token for plan");
        }
        if !plan.active {
            return Err("plan inactive");
        }
        if external_ref.is_empty() || external_ref.len() > MAX_REF_LEN {
            return Err("invalid external_ref length");
        }
        if max_charges == Some(0) {
            return Err("max_charges must be > 0");
        }
        if charge_now && amount < plan.amount.0 {
            return Err("deposit below first charge");
        }
        let key = ref_key(&plan.merchant, &external_ref);
        if self.sub_by_ref.contains_key(&key) {
            return Err("external_ref already used");
        }

        let now = env::block_timestamp();
        let period_ns = plan.period_sec as u64 * NS_PER_SEC;
        let sub_id = self.next_sub_id;
        let sub = Subscription {
            id: sub_id,
            plan_id,
            owner: owner.clone(),
            merchant: plan.merchant.clone(),
            token: token.clone(),
            amount: plan.amount,
            period_ns: U64(period_ns),
            balance: U128(amount),
            next_charge_at_ns: U64(if charge_now { now } else { now + period_ns }),
            charges_count: 0,
            max_charges,
            status: SubStatus::Active,
            external_ref: external_ref.clone(),
            created_at_ns: U64(now),
            charging: false,
        };

        // Write, measure, and roll back if the merchant's pool cannot pay.
        let before = env::storage_usage();
        self.subs.insert(sub_id, sub);
        self.sub_by_ref.insert(key.clone(), sub_id);
        self.owner_set(&owner).insert(sub_id);
        self.flush_indexes(&owner);
        let used = env::storage_usage().saturating_sub(before);
        if !self.try_debit_storage(&plan.merchant, used) {
            self.subs.remove(&sub_id);
            self.sub_by_ref.remove(&key);
            let set = self.owner_set(&owner);
            set.remove(&sub_id);
            if set.is_empty() {
                self.subs_by_owner.remove(&owner);
            }
            self.flush_indexes(&owner);
            return Err("merchant storage pool insufficient");
        }
        self.next_sub_id += 1;
        self.add_held(token, amount);

        RecurraEvent::Subscribed {
            sub_id,
            plan_id,
            merchant: plan.merchant,
            owner,
            amount: plan.amount,
            deposit: U128(amount),
            external_ref,
        }
        .emit();

        if charge_now {
            // Preconditions guaranteed above: active, due now, balance >= amount.
            if let PromiseOrValue::Promise(p) = self.internal_charge(sub_id) {
                p.detach();
            }
        }
        Ok(())
    }

    fn owner_set(&mut self, owner: &AccountId) -> &mut IterableSet<u64> {
        if !self.subs_by_owner.contains_key(owner) {
            let owner_hash = env::sha256_array(owner.as_bytes());
            self.subs_by_owner.insert(
                owner.clone(),
                IterableSet::new(StorageKey::SubsByOwnerInner { owner_hash }),
            );
        }
        self.subs_by_owner.get_mut(owner).unwrap()
    }

    /// Writes cached collection changes so `env::storage_usage()` is accurate.
    fn flush_indexes(&mut self, owner: &AccountId) {
        self.subs.flush();
        self.sub_by_ref.flush();
        if let Some(set) = self.subs_by_owner.get_mut(owner) {
            set.flush();
        }
        self.subs_by_owner.flush();
    }

    fn internal_top_up(&mut self, token: &AccountId, sub_id: u64, amount: u128) -> Result<(), &'static str> {
        let sub = self.subs.get_mut(&sub_id).ok_or("subscription not found")?;
        if &sub.token != token {
            return Err("wrong token for subscription");
        }
        if sub.status == SubStatus::Cancelled {
            return Err("subscription cancelled");
        }
        sub.balance = U128(sub.balance.0 + amount);
        if sub.status == SubStatus::PastDue && sub.balance.0 >= sub.amount.0 {
            sub.status = SubStatus::Active;
        }
        let (owner, balance) = (sub.owner.clone(), sub.balance);
        self.add_held(token, amount);
        RecurraEvent::ToppedUp { sub_id, owner, amount: U128(amount), balance }.emit();
        Ok(())
    }

    fn internal_charge(&mut self, sub_id: u64) -> PromiseOrValue<bool> {
        let now = env::block_timestamp();
        let sub = self.subs.get_mut(&sub_id).unwrap_or_else(|| env::panic_str("subscription not found"));
        require!(sub.status != SubStatus::Cancelled, "subscription cancelled");
        require!(!sub.charging, "charge already in flight");
        require!(now >= sub.next_charge_at_ns.0, "not due yet");
        require!(sub.max_charges.is_none_or(|m| sub.charges_count < m), "max charges reached");

        if sub.balance.0 < sub.amount.0 {
            if sub.status == SubStatus::Active {
                sub.status = SubStatus::PastDue;
                RecurraEvent::PastDue {
                    sub_id,
                    merchant: sub.merchant.clone(),
                    owner: sub.owner.clone(),
                    amount: sub.amount,
                    balance: sub.balance,
                    external_ref: sub.external_ref.clone(),
                }
                .emit();
            }
            return PromiseOrValue::Value(false);
        }

        // Effects before interactions. Next charge anchored to `now`: at most one
        // charge per period even if nobody called `charge` for a long time.
        let amount = sub.amount;
        let prev_next = sub.next_charge_at_ns;
        sub.balance = U128(sub.balance.0 - amount.0);
        sub.charges_count += 1;
        sub.next_charge_at_ns = U64(now + sub.period_ns.0);
        sub.status = SubStatus::Active;
        sub.charging = true;
        let memo = format!("recurra:{}:{}", sub_id, sub.charges_count);
        let (token, merchant) = (sub.token.clone(), sub.merchant.clone());
        self.sub_held(&token, amount.0);

        ext_ft::ext(token)
            .with_attached_deposit(ONE_YOCTO)
            .with_static_gas(GAS_FOR_FT_TRANSFER)
            .ft_transfer(merchant, amount, Some(memo))
            .then(
                Self::ext(env::current_account_id())
                    .with_static_gas(GAS_FOR_RESOLVE)
                    .on_charge_resolved(sub_id, amount, prev_next),
            )
            .into()
    }

    fn internal_cancel(&mut self, sub_id: u64) -> PromiseOrValue<bool> {
        let caller = env::predecessor_account_id();
        let sub = self.subs.get_mut(&sub_id).unwrap();
        require!(sub.status != SubStatus::Cancelled, "already cancelled");
        sub.status = SubStatus::Cancelled;
        let refund = sub.balance;
        RecurraEvent::Cancelled {
            sub_id,
            plan_id: sub.plan_id,
            merchant: sub.merchant.clone(),
            owner: sub.owner.clone(),
            cancelled_by: caller,
            refund,
            external_ref: sub.external_ref.clone(),
        }
        .emit();
        if refund.0 == 0 {
            return PromiseOrValue::Value(true);
        }
        self.internal_refund(sub_id).into()
    }

    /// Sends the full balance to the owner (effects first; callback restores on failure).
    fn internal_refund(&mut self, sub_id: u64) -> Promise {
        let sub = self.subs.get_mut(&sub_id).unwrap();
        let amount = sub.balance;
        sub.balance = U128(0);
        let (token, owner) = (sub.token.clone(), sub.owner.clone());
        self.sub_held(&token, amount.0);
        ext_ft::ext(token)
            .with_attached_deposit(ONE_YOCTO)
            .with_static_gas(GAS_FOR_FT_TRANSFER)
            .ft_transfer(owner, amount, Some(format!("recurra:refund:{sub_id}")))
            .then(
                Self::ext(env::current_account_id())
                    .with_static_gas(GAS_FOR_RESOLVE)
                    .on_refund_resolved(sub_id, amount),
            )
    }
}

#[cfg(test)]
mod tests;
