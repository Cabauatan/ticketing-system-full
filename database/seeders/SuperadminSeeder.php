<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('SUPERADMIN_EMAIL');
        $password = (string) env('SUPERADMIN_PASSWORD');

        if ($email === '' || $password === '') {
            throw new RuntimeException('Set SUPERADMIN_EMAIL and SUPERADMIN_PASSWORD before running database seeders.');
        }

        $superadmin = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => env('SUPERADMIN_NAME', 'Superadmin'),
                'password' => $password,
                'email_verified_at' => now(),
            ],
        );

        if ($superadmin->role !== 'superadmin') {
            $superadmin->forceFill(['role' => 'superadmin'])->save();
        }
    }
}
