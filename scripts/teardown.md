# Tearing down the mainnet Recurra Vault

How to shut down `recurra-vault.near` after the hackathon: give every customer their money back, take the vault off the USDC contract and delete the account so its NEAR goes to an account you choose.

Every signed step is run **by you**, in your own terminal, signing interactively (`sign-with-seed-phrase` or `sign-with-ledger`). No key is stored anywhere. `scripts/teardown-check.py` is read-only and safe to run at any time.

> ## ⚠️ Read before you start
>
> 1. **Deleting an account does not move its tokens.** USDC is recorded in the USDC contract under the account name, not in the account. If you delete `recurra-vault.near` while it still holds USDC, those tokens are stranded. Because `recurra-vault.near` is a top-level `.near` name, **anyone can register it again after deletion and take them.** Never delete while `ft_balance_of(recurra-vault.near)` is not `"0"`.
> 2. **Never use `storage_unregister` with `"force": true`.** It burns any remaining balance. Always use `"force": false`, which refuses if the balance is not zero.
> 3. **Do not "shut down" by removing keys.** If you delete all full-access keys instead of deleting the account, the account and all its NEAR become permanently unrecoverable. Only `delete-account` returns the NEAR.
> 4. **The beneficiary must be an existing mainnet account you control.** Double-check it; a typo sends all the NEAR to someone else (or burns it if the account doesn't exist).
> 5. The vault has no admin sweep. The vault owner cannot move customer funds; only `merchant_cancel` (merchant) or `cancel` (customer) refunds a subscription. If another merchant created plans on this vault, their subscriptions must be cancelled by them or their customers before you can tear down safely.

Variables used below:

```sh
VAULT=recurra-vault.near
MERCHANT=recurra-shop.near
USDC=17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1
BENEFICIARY=<your-main-account>.near   # choose carefully
```

## 1. Stop new subscriptions

For every plan id `get_plans` shows for your merchant, signed by the merchant:

```sh
near contract call-function as-read-only $VAULT get_plans json-args "{\"merchant\":\"$MERCHANT\"}" network-config mainnet now
near contract call-function as-transaction $VAULT set_plan_active json-args '{"plan_id":1,"active":false}' prepaid-gas '30.0 Tgas' attached-deposit '0 NEAR' sign-as $MERCHANT network-config mainnet sign-with-seed-phrase
```

Also disable subscription products in WooCommerce, and stop the charger.

## 2. See what is left

```sh
python3 scripts/teardown-check.py $VAULT --merchant $MERCHANT
```

→ one line per subscription; every line marked `NOT CANCELLED`, `BALANCE > 0` or `CHARGE IN FLIGHT` must be resolved. Exit code 0 = safe, 1 = not safe.

## 3. Refund every subscription

For each subscription that is not `Cancelled`, the merchant cancels it; the contract refunds the full remaining balance **to the customer** (never to the merchant):

```sh
near contract call-function as-transaction $VAULT merchant_cancel json-args '{"sub_id":1}' prepaid-gas '50.0 Tgas' attached-deposit '1 yoctoNEAR' sign-as $MERCHANT network-config mainnet sign-with-seed-phrase
```

(The customer can do the same with `cancel`, signed by their own account.)

- `CHARGE IN FLIGHT`: wait a few blocks for the callback and re-run step 2. A record stuck for more than 100 blocks is cleared by the next `merchant_cancel` (the in-flight charge counts as paid; nothing is paid twice).
- `Cancelled` but `BALANCE > 0`: the refund transfer failed (e.g. the customer is not registered on USDC any more). Only the customer can retry, with `withdraw_unclaimed(sub_id)` (1 yoctoNEAR, 50 TGas), after re-registering on USDC. Contact them; do not delete the vault before this is resolved.

## 4. Verify the vault is empty

```sh
python3 scripts/teardown-check.py $VAULT --merchant $MERCHANT
```

→ must end with `OK: no customer funds and no token balance` and exit code 0. That means `total_held = 0` and `ft_balance_of($VAULT) = 0`.

If `total_held` is `0` but `ft_balance_of` is not, someone sent USDC to the vault with a plain `ft_transfer` (not `ft_transfer_call`). The contract has no way to move those tokens. Do **not** continue; keeping the account is the only way to keep them from being lost.

## 5. Unregister the vault from USDC (returns 0.00125 NEAR)

```sh
near contract call-function as-transaction $USDC storage_unregister json-args '{"force":false}' prepaid-gas '30.0 Tgas' attached-deposit '1 yoctoNEAR' sign-as $VAULT network-config mainnet sign-with-seed-phrase
near contract call-function as-read-only $USDC storage_balance_of json-args "{\"account_id\":\"$VAULT\"}" network-config mainnet now
```

→ the second command prints `null`.

## 6. Delete the account

```sh
near account view-account-summary $BENEFICIARY network-config mainnet now   # must exist and be yours
near account delete-account $VAULT beneficiary $BENEFICIARY network-config mainnet sign-with-seed-phrase
```

→ the vault's NEAR (contract storage stake, including any merchant storage deposits paid into the vault, minus gas) is transferred to `$BENEFICIARY`, and `near account view-account-summary $VAULT network-config mainnet now` reports that the account does not exist.

The merchant's own USDC (`recurra-shop.near`) is not affected by any of this.
