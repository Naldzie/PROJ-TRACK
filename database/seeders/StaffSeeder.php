<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Staff;
use Illuminate\Support\Facades\Hash;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::create([
            'role' => 'staff',
            'email' => 'bagilbert@my.cspc.edu.ph',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user->staff()->create([
            'staff_id' => 'STF-001',
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'profession' => 'Engineer',
            'contact_number' => '09987654321',
        ]);
    }
}