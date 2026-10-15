---
key: customers-cannot-order
title: Customers cannot place an order
summary: Check the usual reasons a customer cannot finish checkout, from payment methods and delivery to your package's order limit.
category: troubleshooting
type: troubleshooting
audience: merchant
module: 11
tags: checkout, orders
keywords: cannot checkout, checkout not working, cannot order, no delivery, we do not deliver, out of stock, not accepting orders
error_codes: usage_limit_exceeded
related: payment-methods, shipping-setup, manage-stock, setup-checklist
applies_from: 1.1
owner: Cart and checkout (Module 11)
review_due_on: 2027-04-30
---
## Problem

A customer tells you they cannot finish their order.

## Symptoms

- "This store is not accepting orders online right now."
- "We do not deliver to this address yet."
- A product cannot be added to the cart, or the cart says it is no longer available.

## Likely causes

- No way to pay is turned on.
- No delivery zone, method and rate matches the customer's address.
- The product is out of stock, inactive or has no price.
- The store is not launched, or is closed or suspended.
- Your package's monthly order limit is reached (`usage_limit_exceeded`).

## Safe checks

1. Open your storefront in a private window and try the same product and address.
2. Look at your dashboard's setup checklist: open steps are listed there.
3. Check the product's status and its stock under [Inventory](/inventory).

## Resolution steps

- Turn on at least one way to pay: [Choose how customers pay](help:payment-methods).
- Add a zone, method and rate for that address: [Set up delivery](help:shipping-setup).
- Make the product active, give it a price, and add stock: [Manage your stock](help:manage-stock).
- Launch the store: [Set up your store and launch it](help:setup-checklist).
- If the order limit is reached, the Owner can change the plan under [Plan and billing](/billing).

## Verification

Place a test order yourself from the storefront, and see it under [Orders](/orders).

## Prevention

Place a test order after every change to payments, delivery or tax.

## When to contact support

If everything above is in order and checkout still fails, open a request under [Support](/support) with the time, the product and the message the customer saw.
