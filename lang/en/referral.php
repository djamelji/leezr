<?php

// ADR-819 (Company 2.0) — Workforce Referral.
return [
    'employee_required' => "Referral is reserved to the company employees.",
    'channel_disabled' => 'Your company does not offer this referral mode.',
    'position_not_open' => 'This position is not open to referral.',
    'daily_limit' => 'You reached the limit of :n referrals per day. Try again tomorrow.',
    'not_withdrawable' => 'This referral can no longer be withdrawn: the application was sent.',
    'already_decided' => 'A decision was already taken for this application.',
    'info_message_required' => 'Say what the candidate must complete.',
    'convert_requires_acceptance' => 'Retain the application before opening a hiring file.',
    'nothing_to_collect' => 'No information nor document is planned for this position: configure the position before opening the file.',
    'link_invalid' => 'This link is not valid or has expired. Ask the person who sent it for a new link.',
    'notice_text' => ':company uses your information to prepare your hiring. Only the people in charge of recruitment can see it.',
    'consent_text' => 'I agree that :company uses this information to study my application. The person who recommended me does not see my application. My information is deleted if I am not retained, at the end of the company retention period.',
    'mail' => [
        'subject' => ':company: someone you know recommends you',
        'greeting' => 'Hello,',
        'line' => ':referrer thinks the “:position” position at :company could interest you.',
        'action' => 'Discover the position and apply',
        'expiry' => 'This link is personal and valid until :date.',
        'optional' => 'If you are not interested, simply ignore this message: no information is sent to the company.',
        'info_subject' => ':company: more information for your application',
        'info_line' => ':company needs more information to study your application.',
        'info_action' => 'Complete my application',
    ],
];
