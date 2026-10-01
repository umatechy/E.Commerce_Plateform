<?php

// Module 02 §18 — staff invitations. The blueprint requires expiry but
// sets no duration; 7 days is a proposed default the owner can change.
return [
    'invitation_ttl_days' => (int) env('TEAM_INVITATION_TTL_DAYS', 7),
];
