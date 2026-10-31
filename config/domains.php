<?php

// Module 19 §8 "Platform Subdomain System" — see
// docs/development/b14-inspection-findings.md "Architectural Decision
// — Platform Subdomain Pattern". A placeholder value in this sandbox
// (no real wildcard DNS zone is provisioned here); the real value is a
// deployment-time configuration, not a business decision this code
// hard-codes.
return [
    'platform_base_domain' => env('PLATFORM_BASE_DOMAIN', 'stores.example'),

    // Phase S2 (owner decision 2026-10-31): stores are public only on their own
    // domain. Platform subdomains still exist as records but are never served
    // nor used for links unless this is turned on.
    'platform_subdomains_public' => (bool) env('PLATFORM_SUBDOMAINS_PUBLIC', false),

    // The address a customer's domain must point to (its A records): the shop's
    // own IPv4. Null until the server has one; the domain page then says so.
    'shop_ipv4' => env('SHOP_PUBLIC_IPV4'),

    // Module 19 §9 "Reserved Domains" — platform-reserved hostnames/
    // subdomain labels a tenant may never register as their own custom
    // domain or as the label portion of a platform subdomain.
    'reserved_labels' => ['admin', 'api', 'www', 'app', 'mail', 'support', 'static', 'assets', 'cdn'],
];
