<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // Create User
        $user = User::create([
            'role' => 'admin',
            'email' => 'ronandalis@my.cspc.edu.ph',
            'profile_photo' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create Admin Profile
        Admin::create([
            'user_id' => $user->id,
            'admin_id' => 'ADM-002',
            'full_name' => 'System Administrator',
        ]);

        // Create Second Admin User
        $user2 = User::create([
            'role' => 'admin',
            'email' => 'neinocencio@my.cspc.edu.ph',
            'profile_photo' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create Second Admin Profile
        Admin::create([
            'user_id' => $user2->id,
            'admin_id' => 'ADM-003',
            'full_name' => 'Neil Inocencio',
        ]);
    }
}