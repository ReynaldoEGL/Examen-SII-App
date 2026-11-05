<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Registro de servicios de aplicación, si se requieren más adelante.
    }

    public function boot(): void
    {
        // Next.js centraliza las solicitudes en su servidor. Limitar únicamente por IP
        // bloquearía a todos los alumnos detrás del mismo servidor Next.js.
        RateLimiter::for('student-login', function (Request $request): array {
            $email = mb_strtolower(trim((string) $request->input('email')));
            $accountKey = hash('sha256', $email !== '' ? $email : 'missing-email');

            return [
                Limit::perMinute(6)->by('student-login:'.$accountKey),
                Limit::perMinute(120)->by('student-login:global'),
            ];
        });

        RateLimiter::for('student-planner', function (Request $request): array {
            // La petición está firmada por Next.js; usar el hash de la sesión evita
            // que una sola IP de servidor comparta el límite entre todos los alumnos.
            $sessionKey = hash('sha256', (string) $request->header('X-App-Session', ''));

            return [
                Limit::perMinute(20)->by('student-planner:'.$sessionKey),
                Limit::perMinute(300)->by('student-planner:global'),
            ];
        });
    }
}
