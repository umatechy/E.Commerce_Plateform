============================================================
PHASE B25 CHECKPOINT
============================================================

Phase:
Development Phase B

Module:
B25 — Storefront Customer Accounts (Module 10 accounts on the Module 05 storefront)

Implementation Summary:
Shoppers can now create an account, sign in and sign out on the
storefront. The session is kept in an HttpOnly, SameSite=Lax cookie that
page scripts cannot read, and it is honoured only on requests that
carry the storefront header (CSRF-safe).

Signed-in customers have an account area:
- an overview with their recent orders;
- the full order history, with item, payment and shipment detail;
- an address book with a default address;
- a wishlist;
- profile and security: name, phone and marketing consent, email change,
  password change, and a personal-data download.

Forgotten passwords can be reset by email: requests never reveal whether
an account exists, and reset tokens are hashed, single-use and expire
after 60 minutes. Checkout pre-fills a signed-in customer's details and
saved addresses, and product pages can save items to the wishlist.

Bugs fixed:
- Variant wishlist items were saved without their product, so they
  showed without a name and as "unavailable".
- Nested rate limiters shared one counter, so every request was counted
  twice.

New Components Implemented:
App\Domain\CustomerAccount — CustomerAddress, StorefrontSession,
CustomerRegistration, AddressBook, CustomerOrderHistory,
CustomerPasswordResetService, UseStorefrontCustomerSession middleware,
StorefrontSessionController, CustomerAddressController,
CustomerOrderController, CustomerProfileController,
CustomerPasswordResetController. Frontend: AccountLayout, AddressForm,
account/orders helpers, and ten account pages (Login, Register,
ForgotPassword, ResetPassword, Dashboard, Orders, Order, Addresses,
Profile, Wishlist). See docs/architecture/b25-customer-accounts.md.

Database Changes:
- New: customer_addresses, customer_password_resets.

Automated Test Status:
EXECUTED.
- Backend: 851 tests, 2374 assertions — all passing on MySQL 8.0
  (22 new in tests/Feature/CustomerAccount/).
- Frontend: 19 Vitest tests passing (3 new); `npm run lint`,
  `tsc --noEmit` and `npm run build` pass.
- Static analysis: PHPStan/Larastan level 5 — no errors.

Runtime Verification Status:

| Area | Status |
|------|--------|
| Migrations up / rollback / up (MySQL 8.0) | EXECUTED |
| PHPUnit suite (MySQL 8.0, Redis) | EXECUTED — PASSING |
| PHPStan level 5 | EXECUTED — CLEAN |
| Frontend lint / typecheck / Vitest / Vite build | EXECUTED — PASSING |
| End-to-end in headless Chromium: guest redirected to sign-in → register (HttpOnly cookie, invisible to JS) → add address → save variant to wishlist → add to cart → pre-filled checkout → order ORD-000002 in history and detail → profile edit → sign out (cookie cleared) → sign in with redirect to orders; zero API errors | EXECUTED |
| Password reset email delivery through a real mail provider | NOT EXECUTED — covered by the stored notification message in tests |
| GitHub Actions CI run | NOT EXECUTED — runs on the next push to main/develop or a PR |

Known Limitations:
- No email verification at sign-up; no customer order cancellation or
  returns; no social login or 2FA for customers.
- The reset link is stored inside the notification body (see the
  security review).

Recommended Next Milestone:
Module 34 (Support) — customers now have accounts and order history, so
support tickets can be attached to both. Running the GitHub Actions
workflow on a pull request for B21–B25 should come first.
