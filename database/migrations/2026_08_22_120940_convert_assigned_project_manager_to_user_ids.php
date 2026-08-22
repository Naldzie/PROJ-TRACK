<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $projects = DB::table('projects')
            ->whereNotNull('assigned_project_manager')
            ->where('assigned_project_manager', '!=', '')
            ->get();

        foreach ($projects as $project) {
            $val = trim($project->assigned_project_manager);
            $userId = null;

            // Already a prefixed format from previous attempt
            if (str_starts_with($val, 'admin-')) {
                $adminId = (int) substr($val, 6);
                $admin = DB::table('admins')->where('id', $adminId)->first();
                $userId = $admin?->user_id;
            } elseif (str_starts_with($val, 'staff-')) {
                $staffId = (int) substr($val, 6);
                $staff = DB::table('staff')->where('id', $staffId)->first();
                $userId = $staff?->user_id;
            } elseif (is_numeric($val)) {
                $id = (int) $val;
                // Try admin first, then staff
                $admin = DB::table('admins')->where('id', $id)->first();
                if ($admin) {
                    $userId = $admin->user_id;
                } else {
                    $staff = DB::table('staff')->where('id', $id)->first();
                    $userId = $staff?->user_id;
                }
            }

            if ($userId) {
                DB::table('projects')->where('id', $project->id)->update([
                    'assigned_project_manager' => (string) $userId,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse — old Admin/Staff IDs cannot be reliably restored
    }
};
