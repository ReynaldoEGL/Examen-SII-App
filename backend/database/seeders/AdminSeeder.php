<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = mb_strtolower(trim((string) config('services.admin_initial.email')));
        $password = (string) config('services.admin_initial.password');

        if ($email === '' || strlen($password) < 14 || str_contains($password, 'CHANGE_THIS')) {
            throw new RuntimeException(
                'Configura ADMIN_INITIAL_EMAIL y una ADMIN_INITIAL_PASSWORD de al menos 14 caracteres antes de ejecutar db:seed.'
            );
        }

        Admin::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Administrador principal',
                'password' => $password,
                'role' => 'superadmin',
                'active' => true,
            ]
        );
    }
}
