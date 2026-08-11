<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Database\Seeders\OnboardingTemplateGestaoSeeder;

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

        // Fase 135 Plano 04 — template de Gestão v1 (idempotente, D-08).
        $this->call(OnboardingTemplateGestaoSeeder::class);
    }
}
