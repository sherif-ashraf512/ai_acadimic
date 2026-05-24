<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'admin',
            'code' => 'admin',
            'national_id' => '00000000000000',
            'gpa' => 4.0,
            'level' => 'admin',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
    }
}
