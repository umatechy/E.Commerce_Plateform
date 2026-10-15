---
key: shipping-setup
title: Set up delivery areas, methods and rates
summary: Decide where you deliver, how, and what it costs. Customers choose from these at checkout.
category: shipping
type: how_to
audience: merchant
module: 13
tags: shipping, delivery, zones, rates
keywords: shipping, delivery charges, delivery fee, free delivery, zone, city, province, rate, method
contexts: /shipping
related: ship-orders, customers-cannot-order
applies_from: 1.1
owner: Shipping (Module 13)
review_due_on: 2027-04-30
---
Open [Shipping setup](/shipping). You need three things; a customer only sees a delivery option when all three match their address.

1. **Zones**: the areas you deliver to, by country (two letters, such as PK), province or region, and city. Leave a field empty to match any value.
2. **Methods**: how you deliver, such as "Standard delivery". Customers see the name at checkout. A method can ship free from an order amount you choose.
3. **Rates**: the cost of one method in one zone. A rate can include a number of units in its base cost and charge for each extra unit.

Without a rate, a method is not offered in that zone. If an address matches no zone, the customer sees "We do not deliver to this address yet."

## Good to know

- Rates are in your store currency.
- Changes apply to new orders; placed orders keep the delivery cost they were charged.
- Delivery can be taxed: see [Set up tax](help:tax-setup).
