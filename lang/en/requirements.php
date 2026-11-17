<?php

return [
    // ADR-792 — exigences par poste (mode parallèle).
    'invalid_state' => 'Invalid requirement state.',
    'imperative_locked' => 'This requirement is imposed by a mandatory rule: it cannot be removed.',
    'unknown_definition' => 'Information or document not found in your catalogue.',
    'unknown_position' => 'Position not found in your company.',
    'authority_unknown' => 'Unknown computation mode.',
    'authority_losses' => ':n document(s) would no longer be requested: take them over on the positions concerned or confirm explicitly.',
    'position_required' => 'Choose a position to see its takeover proposal.',
    'forbidden_section' => 'You cannot change this part.',
    'module_not_active' => "This module is not active for your company: its proposals cannot be adopted.",
    'unknown_proposal' => "Unknown proposal.",
    'apply_requires_position_authority' => 'This position\'s requirements do not apply yet: your company still computes documents per access role. Switch to per-position calculation before applying them to people in place.', // ADR-819
];
