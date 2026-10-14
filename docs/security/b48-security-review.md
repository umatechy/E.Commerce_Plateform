# B48 security review — payment gateways

| Area | Risk | Control | Test |
|---|---|---|---|
| Credentials | Merchant secrets leaked through the API, logs, browser or backups of the DB | encrypted at rest (`encrypted:array`), hidden from serialisation; API returns masked hints and "set" only; `ProviderCredentials` hides values from `var_dump`/JSON; audit logs field names only; requests and responses are never logged; no browser form carries a credential (JazzCash page redirection not used) | settings test (secret not in response, not in DB column, not in audit) |
| Where the money goes | An insider changes the bank account or merchant account | `payments.provider_config` (Owner only by default), step-up on bank details and accounts, audited | permissions test; StepUpTest |
| Amount / currency tampering | The client chooses what is charged | the amount is the order's payable amount or the invoice's amount due; the provider's confirmed amount and currency must equal the attempt's, else the payment is not recorded and a person is asked | mismatch test |
| False paid | A redirect, a forged or replayed notice marks an order paid | browser state is never proof; notices are hints — signature checked with the store's own credentials where signed, timestamp window, exact-repeat dedupe, then the provider is asked server-to-server | notification test; WebhookTest replay window |
| Double charge | A retry while the first attempt is still waiting; a job retried | one attempt in flight per payment (row lock); job tried once and claims the attempt atomically; a timeout is Unknown and settled by inquiry | unknown test (retry 409) |
| Double record | Two answers for one attempt | answers applied under a lock on the attempt; sale transaction idempotent per attempt; invoice payment idempotent per attempt | notification test (paid once) |
| Sandbox money as real | Sandbox payments marking real orders paid | sandbox accounts are never offered at checkout; production requires the platform's go-ahead per provider; closing a provider switches all live accounts off | sandbox/production test |
| Guest session theft / IDOR | Someone follows or retries another shopper's payment | 48-character token, hash only stored, compared in constant time; signed-in owner of the order otherwise; anyone else 404; store from the tenant context | retry test (no token, wrong token, other store → 404) |
| Tenant isolation | One store's accounts or attempts used for another | accounts and attempts are tenant-scoped; webhook resolves the attempt, then that store's credentials; route lookups stay in the store | isolation test |
| Wallet number privacy | Full numbers kept | only `0300•••4567` stored; the number travels once, in an encrypted queue job | wallet test (no full number in attempts) |
| SSRF | Following a URL in a notification | a notification's URL is never fetched; only the reference is read | — |
| Refund abuse | Refund above paid; duplicate refund | B7 lock and refundable balance; provider refund idempotent by key; unconfirmed provider refund not recorded | refund test |
| Bypassing the live gate | Changing `payments.live_providers` through generic settings or rollback | refused there (403); only the Payment providers page, which also switches accounts off | sandbox/production test |
| Provider outage | Shoppers stuck or charged twice | failure categories with plain messages; other methods stay available; unknown never retried blindly | adapter tests |
| Test wallet in production | A no-money provider live | only when `config('payments.simulator')` and the app is not in production | — |

Not verified: the real JazzCash and Easypaisa APIs (no sandbox credentials
yet). They stay out of production until checked (b48-payment-gateways.md §9).
No new dependency.
