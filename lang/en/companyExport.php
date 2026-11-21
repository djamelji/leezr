<?php

// ADR-845 (Leezr 4.2 M8) — full company data export .
return [
    'owner_only' => "Only the company owner can request or download the full data export.",
    'password_invalid' => 'Incorrect password.',
    'already_running' => 'An export is already being prepared for this company.',
    'cooldown' => 'An export was just requested: you can request a new one in :minutes minutes.',
    'expired' => 'This download link expired or was revoked: request a new export.',
    'no_scheduled_cancellation' => 'No scheduled cancellation to undo.',
    'exceptional' => [
        'recipient_invalid' => "The recipient must be an active member of the company.",
        'not_awaiting' => "This request is no longer awaiting approval.",
        'same_operator' => "The request must be approved by another operator than the one who created it.",
        'disabled' => "Exceptional exports cannot be executed until the representative authority check is validated.",
    ],
    'columns' => [
        'last_name' => 'Last name', 'first_name' => 'First name', 'email' => 'E-mail', 'membership' => 'Membership', 'role' => 'Role', 'access' => 'Access',
        'joined_at' => 'Since', 'employee_number' => 'Employee number', 'phone' => 'Phone', 'status' => 'Status', 'hire_date' => "Hire date",
        'termination_date' => 'Termination date', 'department' => 'Department', 'job_role' => 'Job role', 'contract_type' => 'Contract type',
        'start_date' => 'Start', 'end_date' => 'End', 'weekly_hours' => 'Weekly hours', 'is_current' => 'Current', 'reference' => 'Reference',
        'origin_address' => "Origin address", 'destination_address' => 'Destination address', 'scheduled_at' => 'Scheduled at', 'assigned_to' => 'Driver',
        'notes' => 'Notes', 'created_at' => 'Created at', 'number' => 'Number', 'issued_at' => 'Issued at', 'due_at' => 'Due at', 'paid_at' => 'Paid at',
        'period_start' => 'Period start', 'period_end' => 'Period end', 'subtotal' => 'Amount excl. tax', 'tax_amount' => 'VAT', 'amount' => 'Amount incl. tax',
        'amount_due' => 'Amount due', 'currency' => 'Currency', 'plan' => 'Plan', 'interval' => 'Interval', 'contracted_price' => 'Contracted price',
        'cancel_at_period_end' => 'Cancellation scheduled', 'date' => 'Date', 'action' => 'Action', 'actor_type' => "Actor type", 'actor_id' => 'Actor (id)',
        'target_type' => 'Target', 'target_id' => 'Target (id)', 'severity' => 'Severity', 'kind' => 'Kind', 'id' => 'Id', 'file' => 'File in the archive',
    ],
    'files' => [
        'company' => "Company identity, address, plan and active modules",
        'members' => 'Users attached and their access', 'employees' => 'Employees (HR record without pay or national identifier)',
        'contracts' => 'Employment contracts (metadata; signed documents are in documents/)', 'shipments' => 'Shipments and deliveries',
        'invoices' => 'Invoices issued by Leezr', 'subscriptions' => 'Subscription history', 'audit' => "Company audit log",
        'documents' => 'Document index (included, missing or beyond the size budget)',
        'time_entries' => 'Clock-ins', 'time_breaks' => 'Breaks', 'time_daily' => 'Planned / worked (timesheets)', 'time_planning' => 'Planning', 'time_leaves' => 'Absences and leave',
    ],
    'mail' => [
        'partial' => "The data export of :company is ready but PARTIAL: some data could not be included. The list and reasons are shown before downloading.",
        'subject' => 'Data export of :company ready', 'greeting' => 'Hello,',
        'ready' => 'The full data export of :company is ready.',
        'expires' => 'It can be downloaded from Leezr until :date; it is then deleted.',
        'action' => 'Open the download page', 'notYou' => 'If you did not request this export, warn your administrator right away.',
    ],
];
