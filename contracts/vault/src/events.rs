//! NEP-297 events: `EVENT_JSON:{"standard":"recurra","version":"1.0.0","event":"...","data":{...}}`.

use near_sdk::json_types::U128;
use near_sdk::{AccountId, near};

#[near(event_json(standard = "recurra"))]
pub enum RecurraEvent {
    #[event_version("1.0.0")]
    PlanCreated {
        plan_id: u32,
        merchant: AccountId,
        token: AccountId,
        amount: U128,
        period_sec: u32,
    },
    #[event_version("1.0.0")]
    Subscribed {
        sub_id: u64,
        plan_id: u32,
        merchant: AccountId,
        owner: AccountId,
        amount: U128,
        deposit: U128,
        external_ref: String,
    },
    #[event_version("1.0.0")]
    ToppedUp {
        sub_id: u64,
        owner: AccountId,
        amount: U128,
        balance: U128,
    },
    #[event_version("1.0.0")]
    Charged {
        sub_id: u64,
        plan_id: u32,
        merchant: AccountId,
        owner: AccountId,
        amount: U128,
        charge_number: u32,
        external_ref: String,
    },
    #[event_version("1.0.0")]
    ChargeFailed {
        sub_id: u64,
        merchant: AccountId,
        amount: U128,
    },
    #[event_version("1.0.0")]
    PastDue {
        sub_id: u64,
        merchant: AccountId,
        owner: AccountId,
        amount: U128,
        balance: U128,
        external_ref: String,
    },
    #[event_version("1.0.0")]
    Cancelled {
        sub_id: u64,
        plan_id: u32,
        merchant: AccountId,
        owner: AccountId,
        cancelled_by: AccountId,
        refund: U128,
        external_ref: String,
    },
    /// An in-flight charge whose callback never arrived within
    /// `STALE_CHARGE_BLOCKS` was cleared and treated as paid.
    #[event_version("1.0.0")]
    StaleChargeCleared {
        sub_id: u64,
        charge_number: u32,
        amount: U128,
    },
    #[event_version("1.0.0")]
    RefundFailed {
        sub_id: u64,
        owner: AccountId,
        amount: U128,
    },
}
