<?php

return [
    'billing_payment_failed' => [
        'title' => 'Payment Failed',
        'body' => 'A payment attempt for invoice #:invoice_id has failed.',
    ],
    'billing_invoice_created' => [
        'title' => 'New Invoice',
        'body' => 'Invoice #:invoice_id for :amount has been created.',
    ],
    'billing_payment_received' => [
        'title' => 'Payment Received',
        'body' => 'Payment of :amount has been received.',
    ],
    'billing_plan_changed' => [
        'title' => 'Plan Changed',
        'body' => 'Your plan has been changed to :plan_name.',
    ],
    'billing_trial_expiring' => [
        'title' => 'Trial Expiring',
        'body' => 'Your trial expires in :days days.',
    ],
    'billing_trial_started' => [
        'title' => 'Trial Started',
        'body' => 'Your trial has started. Welcome!',
    ],
    'billing_trial_converted' => [
        'title' => 'Trial Converted',
        'body' => 'Your trial has been converted to an active subscription.',
    ],
    'billing_payment_method_expiring' => [
        'title' => 'Payment Method Expiring',
        'body' => 'Your payment method ending in :last4 expires soon.',
    ],
    'billing_account_suspended' => [
        'title' => 'Account Suspended',
        'body' => 'Your account has been suspended due to unpaid invoices.',
    ],
    'billing_addon_activated' => [
        'title' => 'Add-on Activated',
        'body' => 'The module :module_name has been activated.',
    ],
    'members_invited' => [
        'title' => 'Member Invited',
        'body' => ':member_name has been invited to join.',
    ],
    'members_joined' => [
        'title' => 'Member Joined',
        'body' => ':member_name has joined the team.',
    ],
    'members_removed' => [
        'title' => 'Member Removed',
        'body' => ':member_name has been removed from the team.',
    ],
    'members_role_changed' => [
        'title' => 'Role Changed',
        'body' => ':member_name\'s role has been changed to :role_name.',
    ],
    'modules_activated' => [
        'title' => 'Module Activated',
        'body' => 'The module :module_name has been activated.',
    ],
    'modules_deactivated' => [
        'title' => 'Module Deactivated',
        'body' => 'The module :module_name has been deactivated.',
    ],
    'security_alert' => [
        'title' => 'Security Alert',
        'body' => 'A security event has been detected.',
    ],

    // Platform-scoped topics
    'platform_new_subscription' => [
        'title' => 'New Subscription',
        'body' => 'Company :company_name subscribed to :plan_name plan.',
    ],
    'platform_plan_changed' => [
        'title' => 'Plan Changed',
        'body' => 'Company :company_name changed from :old_plan to :new_plan.',
    ],
    'platform_cancellation_requested' => [
        'title' => 'Cancellation Requested',
        'body' => 'Company :company_name requested subscription cancellation.',
    ],
    'platform_payment_failed_alert' => [
        'title' => 'Payment Failed',
        'body' => 'Payment failed for company :company_name (invoice #:invoice_id).',
    ],
    'platform_new_company_registered' => [
        'title' => 'New Company Registered',
        'body' => 'Company :company_name just registered on the platform.',
    ],
    'platform_trial_expired' => [
        'title' => 'Trial Expired',
        'body' => 'Trial for company :company_name expired without conversion.',
    ],
    'platform_account_suspended' => [
        'title' => 'Account Suspended',
        'body' => 'Account for company :company_name has been suspended.',
    ],

    // Support topics
    'support_ticket_created' => [
        'title' => 'New Support Ticket',
        'body' => ':company_name opened a ticket: :subject',
    ],
    'support_ticket_replied' => [
        'title' => 'Reply on Your Ticket',
        'body' => 'New reply on ticket ":subject".',
    ],
    'support_ticket_resolved' => [
        'title' => 'Ticket Resolved',
        'body' => 'Your ticket ":subject" has been resolved.',
    ],
    'support_ticket_assigned' => [
        'title' => 'Ticket Assigned',
        'body' => 'Ticket ":subject" has been assigned to you.',
    ],
];
