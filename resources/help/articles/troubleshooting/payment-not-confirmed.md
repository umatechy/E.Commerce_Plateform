---
key: payment-not-confirmed
title: An order still shows as waiting for payment
summary: What to do when a customer says they paid but the order is not marked paid.
category: troubleshooting
type: troubleshooting
audience: merchant
module: 12
tags: payments, jazzcash, easypaisa, bank transfer
keywords: paid but not showing, payment pending, waiting for payment, jazzcash failed, easypaisa failed, transfer not confirmed
related: payments-and-refunds, payment-methods
applies_from: 1.1
owner: Payments (Module 12)
review_due_on: 2027-04-30
---
## Problem

A customer says they paid, but the order still shows as waiting for payment.

## Symptoms

- The order's payment status is pending.
- The customer has a message from their bank or wallet.

## Likely causes

- **Bank transfer**: transfers are recorded by you, once the money is in your account.
- **JazzCash or Easypaisa**: the customer did not approve the request on their phone in time, or the provider has not answered yet.
- **Cash on delivery**: cash is marked paid when the courier pays you.

## Safe checks

1. Open the order and its payment. For an online payment, look at its **online payment attempts**: each shows whether it was paid, declined or got no answer.
2. For a transfer, check your bank account for the amount and the order number as reference.
3. Never ask the customer for their PIN, password or full card details.

## Resolution steps

- **Transfer received**: record the payment on the order. See [Record payments and give refunds](help:payments-and-refunds).
- **Online attempt not paid**: the customer can try again from the order page in their account, or choose another way to pay with you.
- **Online attempt with no answer yet**: wait. The platform keeps asking the provider and updates the payment on its own. Do not record it by hand at the same time.

## Verification

The order's payment shows as paid, and the customer receives a payment email.

## Prevention

Ask customers paying by transfer to use the order number as the reference.

## When to contact support

If an online payment stays without an answer for more than a day, or the provider shows it paid and the order does not, open a request under [Support](/support) with the order number.
