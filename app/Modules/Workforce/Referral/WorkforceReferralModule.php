<?php

namespace App\Modules\Workforce\Referral;

use App\Core\Modules\Capabilities;
use App\Core\Modules\ModuleDefinition;
use App\Core\Modules\ModuleManifest;

/**
 * ADR-819 (Company 2.0) — Workforce Parrainage : employees recommend a relative or share an application link
 * for the positions the company opens ; the candidate consents and applies ; authorised HR review, ask for
 * more, accept or decline and convert a retained application into the EXISTING hiring file (ADR-796/818).
 * No account created, no access to the HR file for the referrer, rewards are information only (no payroll,
 * no payment engine). Commercial listing (price) stays a Platform decision.
 */
class WorkforceReferralModule implements ModuleDefinition
{
    public static function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'workforce_referral',
            name: 'Workforce Referral',
            description: 'Employee referrals: recommend a relative or share an application link, HR review and conversion into a hire.',
            surface: 'hr',
            sortOrder: 265,
            capabilities: new Capabilities(
                navItems: [
                    ['key' => 'workforce-referrals', 'title' => 'Referrals', 'to' => ['name' => 'company-workforce-referrals'], 'icon' => 'tabler-heart-handshake', 'permission' => 'workforce_referral.review', 'surface' => 'hr', 'group' => 'team', 'sort' => 16],
                    ['key' => 'my-referrals', 'title' => 'My referrals', 'to' => ['name' => 'company-my-referrals'], 'icon' => 'tabler-heart-handshake', 'permission' => 'workforce_referral.refer', 'group' => 'personal', 'sort' => 60, 'requires' => 'employment'],
                    ['key' => 'settings-referrals', 'title' => 'Referrals', 'to' => ['name' => 'company-workforce-referrals', 'query' => ['tab' => 'settings']], 'icon' => 'tabler-heart-handshake', 'permission' => 'workforce_referral.manage', 'surface' => 'hr', 'group' => 'settings', 'sort' => 58, 'settings' => ['section' => 'work', 'level' => 'regular']],
                ],
                routeNames: ['company-workforce-referrals'], // « Mon espace › Parrainage » : gardé par definePage (module + permission)
                middlewareKey: 'workforce_referral',
            ),
            permissions: [
                ['key' => 'workforce_referral.refer', 'label' => 'Refer a relative', 'hint' => 'Recommend a relative or share an application link for open positions.'],
                ['key' => 'workforce_referral.review', 'label' => 'Review referrals', 'is_admin' => true, 'hint' => 'See applications received through referral, accept, decline and convert them into a hire.'],
                ['key' => 'workforce_referral.manage', 'label' => 'Configure referrals', 'is_admin' => true, 'hint' => 'Choose the open positions, the referral modes and the retention.'],
            ],
            bundles: [
                ['key' => 'workforce_referral.employee', 'label' => 'Referral (employee)', 'hint' => 'Recommend relatives and follow own referrals.', 'scope' => 'own', 'permissions' => ['workforce_referral.refer']],
                ['key' => 'workforce_referral.review', 'label' => 'Referral review', 'hint' => 'Review and convert referral applications.', 'permissions' => ['workforce_referral.review'], 'is_admin' => true],
                ['key' => 'workforce_referral.management', 'label' => 'Referral settings', 'hint' => 'Configure the referral programme.', 'permissions' => ['workforce_referral.review', 'workforce_referral.manage'], 'is_admin' => true],
            ],
            scope: 'company',
            type: 'addon',
            requires: ['workforce'],
        );
    }
}
