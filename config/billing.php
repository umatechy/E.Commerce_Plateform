<?php

declare(strict_types=1);

/*
 * Module 29 — Billing, Invoices & Renewals (Phase B23). Platform-to-store
 * subscription billing; unrelated to what stores charge their own
 * customers (Modules 09/12).
 */
return [
    // Currency new subscriptions are billed in (a PackagePrice must exist
    // for it). ISO 4217.
    // Owner decision 2026-10-03: Pakistan first. Existing subscriptions keep
    // the currency they were started in.
    'currency' => env('BILLING_CURRENCY', 'PKR'),

    // Tax charged on every invoice, in basis points (1700 = 17%). 0 = none.
    'tax_rate_bps' => (int) env('BILLING_TAX_RATE_BPS', 0),
    'tax_label' => env('BILLING_TAX_LABEL', 'Tax'),

    'invoice_prefix' => env('BILLING_INVOICE_PREFIX', 'INV-'),

    // A renewal invoice is issued this many days before the period it
    // bills for starts; it is due when that period starts.
    'issue_days_before' => (int) env('BILLING_ISSUE_DAYS_BEFORE', 7),

    // Dunning ladder, in days after the due date. Past due and grace
    // period still grant access (Module 04 §36: warn before cutting off).
    'dunning' => [
        'grace_after_days' => (int) env('BILLING_GRACE_AFTER_DAYS', 3),
        'suspend_after_days' => (int) env('BILLING_SUSPEND_AFTER_DAYS', 10),
        'expire_after_days' => (int) env('BILLING_EXPIRE_AFTER_DAYS', 40),
    ],
];
