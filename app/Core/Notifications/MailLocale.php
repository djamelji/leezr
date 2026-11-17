<?php

namespace App\Core\Notifications;

use App\Core\Markets\MarketResolver;
use App\Core\Models\Company;
use App\Core\Models\User;

/**
 * ADR-813 — language of an outgoing e-mail (body when translated, and Laravel's layout : greeting,
 * subcopy, « All rights reserved »). Order : the Company's market → the recipient's companies when they
 * all share one market → the default market → config('app.locale'). Only shipped translations are used.
 */
final class MailLocale
{
    private const SUPPORTED = ['fr', 'en'];

    public static function forCompany(?Company $company): string
    {
        if ($company) {
            try {
                return self::normalize(MarketResolver::resolveForCompany($company)->locale) ?? self::fallback();
            } catch (\Throwable) {
                return self::fallback();
            }
        }

        return self::fallback();
    }

    public static function forUser(User $user): string
    {
        $markets = $user->companies()->pluck('companies.market_key')->filter()->unique();

        return $markets->count() === 1
            ? self::forCompany($user->companies()->first())
            : self::fallback();
    }

    public static function fallback(): string
    {
        try {
            return self::normalize(MarketResolver::resolveDefault()->locale) ?? self::normalize(config('app.locale')) ?? 'en';
        } catch (\Throwable) {
            return self::normalize(config('app.locale')) ?? 'en';
        }
    }

    private static function normalize(?string $locale): ?string
    {
        $lang = strtolower(substr((string) $locale, 0, 2)); // "fr-FR" → "fr"

        return in_array($lang, self::SUPPORTED, true) ? $lang : null;
    }
}
