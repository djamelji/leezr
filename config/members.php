<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Invitation validity (M7 LOT 3)
    |--------------------------------------------------------------------------
    |
    | Number of days during which a PRE-EXISTING account may accept an invitation to join a
    | company. Past this delay the invitation is expired: it can no longer be accepted, it frees
    | its seat in the member quota, and an administrator may renew it (new deadline) or cancel it.
    |
    | An account CREATED by the invitation follows the password broker instead
    | (`auth.passwords.users.expire`, in minutes): defining its password is the acceptance, and an
    | administrator re-sends a fresh link with the same « resend invitation » action.
    |
    */

    'invitation_ttl_days' => (int) env('MEMBERS_INVITATION_TTL_DAYS', 14),

];
