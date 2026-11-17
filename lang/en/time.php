<?php

// ADR-818 (chantier F) — messages du temps de travail (pointages, corrections).
return [
    'open_entry_needs_clock_out' => 'This time entry is still open: enter the clock-out time to close it.',
    'entry_not_correctable' => 'This time entry cannot be corrected in its current state.',
    'clock_out_in_future' => 'The clock-out time cannot be in the future.',
    'clock_in_before_out' => 'Clock-in time must be before clock-out time.',
    'locked_timesheet' => 'This time entry belongs to an approved or locked timesheet: it can no longer be changed.',
    'already_completed' => 'This time entry is already completed.',
    'already_clocked_in' => 'A time entry is already in progress: clock out first.',
];
