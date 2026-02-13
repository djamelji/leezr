<?php

namespace App\Core\Auth;

use Illuminate\Validation\Rules\Password;

/**
 * Centralized password validation policy — single source of truth.
 * Used by all password fields across both scopes (company + platform).
 */
class PasswordPolicy
{
    public static function rules(): Password
    {
        return Password::min(8)
            ->mixedCase()
            ->numbers()
            ->symbols()
            ->uncompromised();
    }
}
