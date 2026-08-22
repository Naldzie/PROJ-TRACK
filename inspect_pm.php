<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$p = App\Models\Project::find(153);
echo "Project: {$p->title}\n";
echo "assigned_project_manager: " . var_export($p->assigned_project_manager, true) . "\n";
echo "project_manager_contact: " . var_export($p->project_manager_contact, true) . "\n";
echo "contractor_id: " . var_export($p->contractor_id, true) . "\n\n";

echo "--- All Staff ---\n";
foreach (App\Models\Staff::with('user')->get() as $s) {
    echo "Staff ID={$s->id} name={$s->full_name} user_id={$s->user_id} user->id={$s->user?->id}\n";
}

echo "\n--- All Admins ---\n";
foreach (App\Models\Admin::with('user')->get() as $a) {
    echo "Admin ID={$a->id} name={$a->full_name} user_id={$a->user_id} user->id={$a->user?->id}\n";
}

echo "\n--- Users with staff/admin role ---\n";
foreach (App\Models\User::whereIn('role', ['staff', 'admin'])->get() as $u) {
    echo "User ID={$u->id} name={$u->display_name} role={$u->role} staff_id=" . var_export($u->staff?->id, true) . " admin_id=" . var_export($u->admin?->id, true) . "\n";
}
