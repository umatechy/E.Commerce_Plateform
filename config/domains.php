<?php

// Module 19 §8 "Platform Subdomain System" — see
// docs/development/b14-inspection-findings.md "Architectural Decision
// — Platform Subdomain Pattern". A placeholder value in this sandbox
// (no real wildcard DNS zone is provisioned here); the real value is a
// deployment-time configuration, not a business decision this code
// hard-codes.
return [
    'platform_base_domain' => env('PLATFORM_BASE_DOMAIN', 'stores.example'),

    // Module 19 §9 "Reserved Domains" — platform-reserved hostnames/
    // subdomain labels a tenant may never register as their own custom
    // domain or as the label portion of a platform subdomain.
    'reserved_labels' => ['admin', 'api', 'www', 'app', 'mail', 'support', 'static', 'assets', 'cdn'],
];
