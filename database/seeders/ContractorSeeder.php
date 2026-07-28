<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class ContractorSeeder extends Seeder
{
    public function run(): void
    {
        // First Contractor User
        $user1 = DB::table('users')->insertGetId([
            'role' => 'contractor',
            'email' => 'libertyandalis@gmail.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contractors')->insert([
            'user_id' => $user1,
            'contractor_id' => 'CON-001',
            'company_name' => 'ABC Construction Services',
            'contractor_name' => 'John Doe',
            'contact_number' => '09123456789',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}