<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudentSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->attributes->get('app_session');

        if (!$session || $session->principal_type !== 'student' || !$session->sii_token) {
            return response()->json(['message' => 'Sesión de alumno requerida.'], 403);
        }

        return $next($request);
    }
}
