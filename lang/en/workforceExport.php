<?php

// ADR-844 (Leezr 4.2 M7) — time / planning / absence exports : CSV headers and messages.
return [
    'too_large' => 'More than :max rows: shorten the period or select fewer people.',
    'period_too_long' => 'The period cannot exceed :days days.',
    'module_inactive' => 'This kind of data is not available for your company.',
    'values' => [
        'bool' => ['yes' => 'yes', 'no' => 'no'],
        'entry' => ['idle' => 'not started', 'working' => 'working', 'on_break' => 'on break', 'completed' => 'completed'],
        'timesheet' => ['draft' => 'draft', 'submitted' => 'submitted', 'approved' => 'approved', 'rejected' => 'rejected', 'locked' => 'locked'],
        'shift' => ['draft' => 'draft', 'published' => 'published', 'completed' => 'completed', 'cancelled' => 'cancelled'],
        'leave' => ['pending' => 'pending', 'approved' => 'approved', 'consumed' => 'taken', 'rejected' => 'rejected', 'cancelled' => 'cancelled'],
        'break' => ['lunch' => 'meal', 'rest' => 'rest', 'personal' => 'personal'],
    ],
    'columns' => [
        'date' => 'Date', 'employee_number' => 'Employee number', 'last_name' => 'Last name', 'first_name' => 'First name', 'department' => 'Department',
        'clock_in' => 'Clock in', 'clock_out' => 'Clock out', 'break_minutes' => 'Breaks (min)', 'worked_minutes' => 'Worked (min)',
        'worked_hhmm' => 'Worked (hh:mm)', 'status' => 'Status', 'source' => 'Source', 'start' => 'Start', 'end' => 'End',
        'type' => 'Type', 'duration_minutes' => 'Duration (min)', 'planned_minutes' => 'Planned (min)',
        'difference_minutes' => 'Worked − planned (min)', 'overtime_identified_minutes' => 'Identified overtime (min)',
        'leave_minutes' => 'Leave (min)', 'leave_type' => 'Leave type', 'rest_day' => 'Rest day', 'anomalies' => 'Anomalies',
        'timesheet_status' => 'Timesheet status', 'validated' => 'Validated', 'site' => 'Site', 'timezone' => 'Timezone',
        'shift_minutes' => 'Shift span (min)', 'from' => 'From', 'to' => 'To', 'start_time' => 'Start time', 'end_time' => 'End time',
        'days' => 'Days', 'hours' => 'Hours', 'reviewed_at' => 'Decided on',
    ],
];
