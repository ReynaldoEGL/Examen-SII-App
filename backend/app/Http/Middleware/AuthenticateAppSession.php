<?php

namespace App\Http\Middleware;

use App\Models\AppSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAppSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = (string) $request->header('X-App-Session', '');

        if ($plainToken === '' || strlen($plainToken) < 48) {
            return response()->json(['message' => 'Sesión no válida.'], 401);
        }

        $session = AppSession::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->where('expires_at', '>', now())
            ->first();

        if (!$session) {
            return response()->json(['message' => 'La sesión expiró.'], 401);
        }

        if (!$session->last_used_at || $session->last_used_at->lt(now()->subMinute())) {
            $session->forceFill(['last_used_at' => now()])->save();
        }

        $request->attributes->set('app_session', $session);

        return $next($request);
    }
}
