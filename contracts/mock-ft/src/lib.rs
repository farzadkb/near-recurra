//! Mock NEP-141 token for Recurra tests. Standard behaviour from
//! `near-contract-standards`, plus an owner-only `mint` helper and a public
//! testnet `faucet` (capped per call, cooldown per account).
//! TEST ONLY — never deploy to mainnet (the faucet refuses to run there).

use near_contract_standards::fungible_token::core::FungibleTokenCore;
use near_contract_standards::fungible_token::metadata::{
    FT_METADATA_SPEC, FungibleTokenMetadata, FungibleTokenMetadataProvider,
};
use near_contract_standards::fungible_token::resolver::FungibleTokenResolver;
use near_contract_standards::fungible_token::FungibleToken;
use near_contract_standards::fungible_token::events::FtMint;
use near_contract_standards::storage_management::{
    StorageBalance, StorageBalanceBounds, StorageManagement,
};
use near_sdk::json_types::U128;
use near_sdk::store::LookupMap;
use near_sdk::{
    AccountId, BorshStorageKey, NearToken, PanicOnDefault, PromiseOrValue, env, near, require,
};

#[near]
#[derive(BorshStorageKey)]
enum StorageKey {
    Token,
}

/// Faucet cap per call, in whole tokens.
pub const FAUCET_MAX_TOKENS: u128 = 100;
/// Seconds an account must wait between faucet claims.
pub const FAUCET_COOLDOWN_SEC: u64 = 3600;
/// Faucet claim times live under their own prefix, not in `Contract`, so the
/// already-deployed testnet token can be upgraded without a state migration.
const FAUCET_PREFIX: &[u8] = b"faucet";

fn faucet_allowed_here() -> bool {
    let id = env::current_account_id();
    id.as_str().ends_with(".testnet") || id.as_str().ends_with(".test.near")
}

#[near(contract_state)]
#[derive(PanicOnDefault)]
pub struct Contract {
    owner: AccountId,
    token: FungibleToken,
    decimals: u8,
}

#[near]
impl Contract {
    #[init]
    pub fn new(owner: AccountId, decimals: u8) -> Self {
        Self {
            owner,
            token: FungibleToken::new(StorageKey::Token),
            decimals,
        }
    }

    /// Owner-only: mint to `account_id`, registering it if needed.
    pub fn mint(&mut self, account_id: AccountId, amount: U128) {
        require!(env::predecessor_account_id() == self.owner, "Only owner can mint");
        if !self.token.accounts.contains_key(&account_id) {
            self.token.internal_register_account(&account_id);
        }
        self.token.internal_deposit(&account_id, amount.0);
        FtMint {
            owner_id: &account_id,
            amount,
            memo: None,
        }
        .emit();
    }

    /// Public testnet faucet: mints `amount` (default and max 100 tokens) to the
    /// caller, registering it if needed. One claim per account per hour.
    pub fn faucet(&mut self, amount: Option<U128>) -> U128 {
        require!(faucet_allowed_here(), "faucet: testnet only");
        let max = FAUCET_MAX_TOKENS * 10u128.pow(u32::from(self.decimals));
        let amount = amount.map_or(max, |a| a.0);
        require!(amount > 0 && amount <= max, "faucet: amount must be between 1 base unit and 100 tokens");
        let who = env::predecessor_account_id();
        let now = env::block_timestamp() / 1_000_000_000;
        let mut claims: LookupMap<AccountId, u64> = LookupMap::new(FAUCET_PREFIX);
        if let Some(last) = claims.get(&who) {
            let next = last + FAUCET_COOLDOWN_SEC;
            if now < next {
                env::panic_str(&format!("faucet: next claim in {} s", next - now));
            }
        }
        claims.insert(who.clone(), now);
        claims.flush();
        if !self.token.accounts.contains_key(&who) {
            self.token.internal_register_account(&who);
        }
        self.token.internal_deposit(&who, amount);
        FtMint { owner_id: &who, amount: U128(amount), memo: Some("faucet") }.emit();
        U128(amount)
    }

    /// Unix seconds when `account_id` may claim again (0 = now).
    pub fn faucet_next_claim_at(&self, account_id: AccountId) -> u64 {
        let claims: LookupMap<AccountId, u64> = LookupMap::new(FAUCET_PREFIX);
        claims.get(&account_id).map_or(0, |t| t + FAUCET_COOLDOWN_SEC)
    }
}

#[near]
impl FungibleTokenCore for Contract {
    #[payable]
    fn ft_transfer(&mut self, receiver_id: AccountId, amount: U128, memo: Option<String>) {
        self.token.ft_transfer(receiver_id, amount, memo)
    }

    #[payable]
    fn ft_transfer_call(
        &mut self,
        receiver_id: AccountId,
        amount: U128,
        memo: Option<String>,
        msg: String,
    ) -> PromiseOrValue<U128> {
        self.token.ft_transfer_call(receiver_id, amount, memo, msg)
    }

    fn ft_total_supply(&self) -> U128 {
        self.token.ft_total_supply()
    }

    fn ft_balance_of(&self, account_id: AccountId) -> U128 {
        self.token.ft_balance_of(account_id)
    }
}

#[near]
impl FungibleTokenResolver for Contract {
    #[private]
    fn ft_resolve_transfer(
        &mut self,
        sender_id: AccountId,
        receiver_id: AccountId,
        amount: U128,
    ) -> U128 {
        let (used, _burned) =
            self.token.internal_ft_resolve_transfer(&sender_id, receiver_id, amount);
        used.into()
    }
}

#[near]
impl StorageManagement for Contract {
    #[payable]
    fn storage_deposit(
        &mut self,
        account_id: Option<AccountId>,
        registration_only: Option<bool>,
    ) -> StorageBalance {
        self.token.storage_deposit(account_id, registration_only)
    }

    #[payable]
    fn storage_withdraw(&mut self, amount: Option<NearToken>) -> StorageBalance {
        self.token.storage_withdraw(amount)
    }

    #[payable]
    fn storage_unregister(&mut self, force: Option<bool>) -> bool {
        self.token.internal_storage_unregister(force).is_some()
    }

    fn storage_balance_bounds(&self) -> StorageBalanceBounds {
        self.token.storage_balance_bounds()
    }

    fn storage_balance_of(&self, account_id: AccountId) -> Option<StorageBalance> {
        self.token.storage_balance_of(account_id)
    }
}

#[near]
impl FungibleTokenMetadataProvider for Contract {
    fn ft_metadata(&self) -> FungibleTokenMetadata {
        FungibleTokenMetadata {
            spec: FT_METADATA_SPEC.to_string(),
            name: "Mock USDC (test only)".to_string(),
            symbol: "mUSDC".to_string(),
            icon: None,
            reference: None,
            reference_hash: None,
            decimals: self.decimals,
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use near_sdk::test_utils::VMContextBuilder;
    use near_sdk::testing_env;

    const SEC: u64 = 1_000_000_000;

    fn ctx(current: &str, caller: &str, ts_sec: u64) {
        testing_env!(VMContextBuilder::new()
            .current_account_id(current.parse().unwrap())
            .predecessor_account_id(caller.parse().unwrap())
            .block_timestamp(ts_sec * SEC)
            .build());
    }

    fn token() -> Contract {
        ctx("musdc.testnet", "owner.testnet", 1_000);
        Contract::new("owner.testnet".parse().unwrap(), 6)
    }

    #[test]
    fn faucet_default_is_100_tokens_and_registers_caller() {
        let mut c = token();
        ctx("musdc.testnet", "judge.testnet", 1_000);
        assert_eq!(c.faucet(None).0, 100_000_000);
        assert_eq!(c.ft_balance_of("judge.testnet".parse().unwrap()).0, 100_000_000);
        assert_eq!(c.ft_total_supply().0, 100_000_000);
        assert_eq!(c.faucet_next_claim_at("judge.testnet".parse().unwrap()), 1_000 + FAUCET_COOLDOWN_SEC);
    }

    #[test]
    #[should_panic(expected = "faucet: amount must be between 1 base unit and 100 tokens")]
    fn faucet_rejects_more_than_cap() {
        let mut c = token();
        ctx("musdc.testnet", "judge.testnet", 1_000);
        c.faucet(Some(U128(100_000_001)));
    }

    #[test]
    #[should_panic(expected = "faucet: amount must be between")]
    fn faucet_rejects_zero() {
        let mut c = token();
        ctx("musdc.testnet", "judge.testnet", 1_000);
        c.faucet(Some(U128(0)));
    }

    #[test]
    #[should_panic(expected = "faucet: next claim in 1 s")]
    fn faucet_cooldown_per_account() {
        let mut c = token();
        ctx("musdc.testnet", "judge.testnet", 1_000);
        c.faucet(Some(U128(5)));
        ctx("musdc.testnet", "judge.testnet", 1_000 + FAUCET_COOLDOWN_SEC - 1);
        c.faucet(Some(U128(5)));
    }

    #[test]
    fn faucet_cooldown_is_per_account_and_expires() {
        let mut c = token();
        ctx("musdc.testnet", "a.testnet", 1_000);
        c.faucet(Some(U128(5)));
        ctx("musdc.testnet", "b.testnet", 1_001);
        c.faucet(Some(U128(7)));
        ctx("musdc.testnet", "a.testnet", 1_000 + FAUCET_COOLDOWN_SEC);
        c.faucet(Some(U128(5)));
        assert_eq!(c.ft_balance_of("a.testnet".parse().unwrap()).0, 10);
        assert_eq!(c.ft_balance_of("b.testnet".parse().unwrap()).0, 7);
    }

    #[test]
    #[should_panic(expected = "faucet: testnet only")]
    fn faucet_refuses_on_mainnet_accounts() {
        ctx("musdc.near", "owner.near", 1_000);
        let mut c = Contract::new("owner.near".parse().unwrap(), 6);
        ctx("musdc.near", "judge.near", 1_000);
        c.faucet(None);
    }

    #[test]
    #[should_panic(expected = "Only owner can mint")]
    fn mint_is_owner_only() {
        let mut c = token();
        ctx("musdc.testnet", "judge.testnet", 1_000);
        c.mint("judge.testnet".parse().unwrap(), U128(1));
    }
}
