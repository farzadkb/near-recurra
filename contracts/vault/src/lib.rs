//! Recurra Vault.
//!
//! Phase 1 placeholder: a minimal "hello" contract used to verify the toolchain
//! (`cargo near build`). Replaced by the real vault in phase 2 (see docs/PLAN.md §3).

use near_sdk::{AccountId, PanicOnDefault, env, near};

#[near(contract_state)]
#[derive(PanicOnDefault)]
pub struct Contract {
    owner: AccountId,
    greeting: String,
}

#[near]
impl Contract {
    #[init]
    pub fn new(owner: AccountId) -> Self {
        Self {
            owner,
            greeting: "Hello".to_string(),
        }
    }

    pub fn get_greeting(&self) -> String {
        self.greeting.clone()
    }

    pub fn get_owner(&self) -> AccountId {
        self.owner.clone()
    }

    pub fn set_greeting(&mut self, greeting: String) {
        near_sdk::require!(
            env::predecessor_account_id() == self.owner,
            "Only the owner can set the greeting"
        );
        self.greeting = greeting;
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use near_sdk::test_utils::{VMContextBuilder, accounts};
    use near_sdk::testing_env;

    #[test]
    fn owner_can_set_greeting() {
        testing_env!(VMContextBuilder::new().predecessor_account_id(accounts(0)).build());
        let mut c = Contract::new(accounts(0));
        assert_eq!(c.get_greeting(), "Hello");
        c.set_greeting("Hi".to_string());
        assert_eq!(c.get_greeting(), "Hi");
    }

    #[test]
    #[should_panic(expected = "Only the owner")]
    fn non_owner_cannot_set_greeting() {
        testing_env!(VMContextBuilder::new().predecessor_account_id(accounts(1)).build());
        let mut c = Contract::new(accounts(0));
        c.set_greeting("Hi".to_string());
    }
}
