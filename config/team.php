<?php

// Module 02 §18 — staff invitations. The blueprint requires expiry but
// sets no duration; 7 days is a proposed default the owner can change.
return [
    'invitation_ttl_days' => (int) env('TEAM_INVITATION_TTL_DAYS', 7),
    // Phase B44: the owner invitation of a store Umar Techy created for a
    // customer — longer, since the customer may not be waiting for it. Staff
    // can send a new one from the Super Admin store page.
    'owner_invitation_ttl_days' => (int) env('OWNER_INVITATION_TTL_DAYS', 14),
];
