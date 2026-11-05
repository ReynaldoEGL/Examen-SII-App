<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyInternalSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.internal.shared_secret');
        $timestamp = (string) $request->header('X-Service-Timestamp', '');
        $nonce = (string) $request->header('X-Service-Nonce', '');
        $provided = (string) $request->header('X-Service-Signature', '');
        $session = (string) $request->header('X-App-Session', '');

        if (strlen($secret) < 32 || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 60) {
            return response()->json(['message' => 'Solicitud no autorizada.'], 401);
        }

        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $nonce) || !preg_match('/^[0-9a-f]{64}$/', $provided)) {
            return response()->json(['message' => 'Solicitud no autorizada.'], 401);
        }

        $bodyHash = hash('sha256', $request->getContent());
        $sessionHash = hash('sha256', $session);
        $canonical = implode("\n", [
            $timestamp,
            $nonce,
            strtoupper($request->method()),
            $request->getPathInfo(),
            $bodyHash,
            $sessionHash,
        ]);

        $expected = hash_hmac('sha256', $canonical, $secret);

        if (!hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Solicitud no autorizada.'], 401);
        }

        // Un nonce solo puede usarse una vez. El cache de producción debe ser compartido entre réplicas.
        if (!Cache::add('internal_nonce:'.$nonce, true, now()->addSeconds(90))) {
            return response()->json(['message' => 'Solicitud repetida.'], 409);
        }

        return $next($request);
    }
}
