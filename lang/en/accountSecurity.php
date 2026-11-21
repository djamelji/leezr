<?php

// ADR-838 (Leezr 4.2 M2) — sensitive account operations.
return [
    'password_invalid' => 'Incorrect password.',
    'code_required' => 'Enter the code from your authenticator app or a backup code.',
    'code_invalid' => 'Invalid or expired code. Enter the code currently shown by your app, or an unused backup code.',
    'not_enabled' => 'Two-factor authentication is not enabled on this account.',
    'too_many_attempts' => 'Too many attempts. Try again in :minutes minute(s).',
    'disabled' => 'Two-factor authentication has been disabled.',
    'codes_regenerated' => 'New backup codes generated. The previous codes no longer work.',
    'mail' => [
        'subject' => 'Your Leezr account security',
        'greeting' => 'Hello,',
        'two_factor_disabled' => 'Two-factor authentication has just been disabled on your account.',
        'backup_codes_regenerated' => 'New backup codes have just been generated for your account. The previous codes no longer work.',
        'password_set_by_admin' => 'Your account password has just been changed by an administrator of :space. Your open sessions have been closed.',
        'password_changed' => 'Your account password has just been changed. Your other open sessions have been closed.',
        'not_you' => 'If you did not do this, reset your password right away and warn your administrator.',
        'salutation' => 'The Leezr team',
    ],
];
