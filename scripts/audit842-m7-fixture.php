<?php
// Leezr 4.2 M7 recipe fixture — MARKED audit clone only, fictitious company 106 « Audit 842 Services » only.
// Two employees (M7-A Alice, M7-B Bruno) with March 2026 clock-ins, a break, timesheets (approved / submitted),
// a shift on a site and leave requests (approved / pending). Previous M7 rows are replaced. No historical data.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Core\Workforce\{Employee, LeaveRequest, LeaveType, Shift, TimeEntry, TimeEntryBreak, TimesheetLine, TimesheetPeriod, WorkLocation};
use Illuminate\Support\Facades\DB;
$db = DB::connection()->getDatabaseName();
if (! str_starts_with($db, 'leezr_audit') || DB::table('_recipe_clone_identity')->value('database_name') !== $db) { fwrite(STDERR, "refused: $db\n"); exit(2); }
$c = 106;
if (! DB::table('companies')->where('id', $c)->where('name', 'like', 'Audit 842 Services%')->exists()) { fwrite(STDERR, "fictitious company 106 not found\n"); exit(2); }
$old = DB::table('workforce_employees')->where('company_id', $c)->where('employee_number', 'like', 'M7-%')->pluck('id');
foreach (['workforce_time_entry_breaks' => null, 'workforce_time_entries' => 'employee_id', 'workforce_timesheet_lines' => 'employee_id', 'workforce_timesheet_periods' => 'employee_id', 'workforce_shifts' => 'employee_id', 'workforce_leave_requests' => 'employee_id'] as $t => $col) {
    if ($col === null) DB::table($t)->whereIn('time_entry_id', DB::table('workforce_time_entries')->whereIn('employee_id', $old)->pluck('id'))->delete();
    else DB::table($t)->whereIn($col, $old)->delete();
}
DB::table('workforce_employees')->whereIn('id', $old)->delete();
$alice = Employee::create(['company_id' => $c, 'first_name' => 'Alice', 'last_name' => 'Export', 'employee_number' => 'M7-A', 'status' => 'active', 'hire_date' => '2026-01-01']);
$bruno = Employee::create(['company_id' => $c, 'first_name' => 'Bruno', 'last_name' => 'Éxport', 'employee_number' => 'M7-B', 'status' => 'active', 'hire_date' => '2026-01-01']);
$e = TimeEntry::create(['company_id' => $c, 'employee_id' => $alice->id, 'date' => '2026-03-10', 'clock_in' => '2026-03-10 08:00:00', 'clock_out' => '2026-03-10 16:30:00', 'status' => 'completed', 'total_worked_minutes' => 480, 'total_break_minutes' => 30, 'source' => 'web']);
TimeEntryBreak::create(['company_id' => $c, 'time_entry_id' => $e->id, 'start_at' => '2026-03-10 12:00:00', 'end_at' => '2026-03-10 12:30:00', 'type' => 'lunch', 'duration_minutes' => 30]);
TimeEntry::create(['company_id' => $c, 'employee_id' => $bruno->id, 'date' => '2026-03-12', 'clock_in' => '2026-03-12 07:00:00', 'clock_out' => '2026-03-12 15:00:00', 'status' => 'completed', 'total_worked_minutes' => 480, 'total_break_minutes' => 0, 'source' => 'kiosk']);
$pa = TimesheetPeriod::create(['company_id' => $c, 'employee_id' => $alice->id, 'period_start' => '2026-03-09', 'period_end' => '2026-03-15', 'status' => 'approved']);
TimesheetLine::create(['company_id' => $c, 'timesheet_period_id' => $pa->id, 'employee_id' => $alice->id, 'date' => '2026-03-10', 'worked_minutes' => 480, 'break_minutes' => 30, 'daily_overtime_minutes' => 30, 'planned_minutes' => 450, 'leave_minutes' => 0, 'is_leave_day' => false, 'is_rest_day' => false, 'source_snapshot' => []]);
$pb = TimesheetPeriod::create(['company_id' => $c, 'employee_id' => $bruno->id, 'period_start' => '2026-03-09', 'period_end' => '2026-03-15', 'status' => 'submitted']);
TimesheetLine::create(['company_id' => $c, 'timesheet_period_id' => $pb->id, 'employee_id' => $bruno->id, 'date' => '2026-03-12', 'worked_minutes' => 480, 'break_minutes' => 0, 'daily_overtime_minutes' => 60, 'planned_minutes' => 420, 'leave_minutes' => 0, 'is_leave_day' => false, 'is_rest_day' => false, 'source_snapshot' => []]);
$site = WorkLocation::firstOrCreate(['company_id' => $c, 'name' => 'Agence M7'], ['type' => 'internal', 'enabled' => true]);
Shift::create(['company_id' => $c, 'employee_id' => $alice->id, 'work_location_id' => $site->id, 'date' => '2026-03-10', 'start_at' => '2026-03-10 08:00:00', 'end_at' => '2026-03-10 15:30:00', 'timezone' => 'Europe/Paris', 'status' => 'published']);
$type = LeaveType::where('company_id', $c)->orderBy('id')->first() ?? LeaveType::create(['company_id' => $c, 'code' => 'CP', 'name' => 'Congés payés', 'accrual_mode' => 'none', 'enabled' => true]);
LeaveRequest::create(['company_id' => $c, 'employee_id' => $bruno->id, 'leave_type_id' => $type->id, 'date_from' => '2026-03-16', 'date_to' => '2026-03-18', 'days_count_hundredths' => 250, 'status' => 'approved']);
LeaveRequest::create(['company_id' => $c, 'employee_id' => $alice->id, 'leave_type_id' => $type->id, 'date_from' => '2026-03-20', 'date_to' => '2026-03-20', 'days_count_hundredths' => 100, 'status' => 'pending']);
// a team lead with the export right but no « see everyone » : sees herself and her direct report (Alice) only
$hash = Illuminate\Support\Facades\Hash::make(trim(file_get_contents($argv[1])));
$roleId = DB::table('company_roles')->where('company_id', $c)->where('key', 'recette844_chef')->value('id')
    ?? DB::table('company_roles')->insertGetId(['company_id' => $c, 'key' => 'recette844_chef', 'name' => "Cheffe d'équipe (recette)", 'is_system' => false, 'is_administrative' => true, 'created_at' => now(), 'updated_at' => now()]);
DB::table('company_role_permission')->where('company_role_id', $roleId)->delete();
foreach (DB::table('company_permissions')->whereIn('key', ['workforce.view', 'workforce.export', 'workforce.timesheet_view', 'workforce.planning_view', 'workforce.leave_request'])->pluck('id') as $p) DB::table('company_role_permission')->insert(['company_role_id' => $roleId, 'company_permission_id' => $p]);
$uid = DB::table('users')->where('email', 'chef844@audit842.test')->value('id');
if ($uid) DB::table('users')->where('id', $uid)->update(['password' => $hash]);
else $uid = DB::table('users')->insertGetId(['first_name' => 'Chloé', 'last_name' => 'Recette', 'email' => 'chef844@audit842.test', 'password' => $hash, 'password_set_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
DB::table('memberships')->updateOrInsert(['user_id' => $uid, 'company_id' => $c], ['role' => 'user', 'company_role_id' => $roleId, 'acceptance_pending_at' => null, 'created_at' => now(), 'updated_at' => now()]);
$chef = Employee::create(['company_id' => $c, 'first_name' => 'Chloé', 'last_name' => 'Recette', 'employee_number' => 'M7-C', 'status' => 'active', 'hire_date' => '2026-01-01', 'user_id' => $uid]);
$alice->update(['manager_id' => $chef->id]);
DB::table('company_module_setups')->where('company_id', $c)->update(['pending_access' => null]);
echo "M7 fixture ready (106 : M7-A, M7-B, March 2026 ; leave type « {$type->name} »)\n";
