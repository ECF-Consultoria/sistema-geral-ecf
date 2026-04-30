<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@ecfconsultoria.com.br'],
            [
                'name'     => 'Admin ECF',
                'password' => \Illuminate\Support\Facades\Hash::make('Admin@ecf2024'),
                'role'     => 'admin',
                'active'   => true,
            ]
        );
    }
}
