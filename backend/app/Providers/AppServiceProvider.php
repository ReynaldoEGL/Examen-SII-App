<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Registro de servicios de aplicación.
    }

    public function boot(): void
    {
        // Next.js centraliza las solicitudes. Limitar solo por IP del servidor
        // bloquearía a todos los alumnos detrás de Vercel. Se deriva una clave
        // opaca a partir del correo normalizado y la IP observada por Laravel.
        RateLimiter::for('student-login', function (Request $request): array {
            $email = mb_strtolower(trim((string) $request->input('email')));
            $ip = (string) ($request->ip() ?? 'unknown');
            $key = (string) config('services.pseudonym_key');

            if (strlen($key) < 32) {
                // No dejar que una configuración ausente convierta el correo
                // en una clave de caché en claro o en un hash fácilmente atacable.
                throw new RuntimeException('PSEUDONYM_KEY debe contener al menos 32 caracteres aleatorios.');
            }

            $identity = hash_hmac('sha256', 'login|'.$email.'|'.$ip, $key);

            return [
                Limit::perMinute(6)->by('student-login:'.$identity),
                Limit::perMinute(120)->by('student-login:global'),
            ];
        });

        RateLimiter::for('student-planner', function (Request $request): array {
            // La clave usa el hash de la sesión opaca; nunca la cookie en claro.
            $sessionKey = hash('sha256', (string) $request->header('X-App-Session', ''));

            return [
                Limit::perMinute(20)->by('student-planner:'.$sessionKey),
                Limit::perMinute(300)->by('student-planner:global'),
            ];
        });
    }
}
