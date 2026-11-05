<?php

namespace App\Http\Controllers\Api\Internal;

use App\Models\AppSession;
use App\Services\SiiAuthenticationException;
use App\Services\SiiService;
use App\Services\SiiTokenExpiredException;
use App\Services\SiiUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class StudentAuthController
{
    public function login(Request $request, SiiService $sii): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        try {
            // SiiService hace POST /api/login y extrae message.login.token.
            $siiToken = $sii->login($credentials['email'], $credentials['password']);

            // Se valida el JWT consultando el perfil real antes de crear una sesión local.
            $profile = $sii->studentProfile($siiToken);
        } catch (SiiAuthenticationException) {
            return response()->json(['message' => 'Correo o contraseña incorrectos.'], 401);
        } catch (SiiTokenExpiredException) {
            return response()->json(['message' => 'El SII no aceptó el token recién emitido. Intenta nuevamente más tarde.'], 502);
        } catch (SiiUnavailableException) {
            return response()->json(['message' => 'No se pudo validar con el SII. Intenta nuevamente más tarde.'], 503);
        }

        $studentNumber = data_get($profile, 'numero_control');
        if (!is_string($studentNumber) || trim($studentNumber) === '') {
            return response()->json(['message' => 'El SII no devolvió el número de control esperado.'], 502);
        }

        $expiresAt = $this->sessionExpirationFromJwt($siiToken);
        if ($expiresAt->lessThanOrEqualTo(now()->addSeconds(15))) {
            return response()->json(['message' => 'El token institucional está vencido o próximo a vencer. Intenta iniciar sesión nuevamente.'], 502);
        }

        $plainSessionToken = bin2hex(random_bytes(32));

        AppSession::query()->create([
            'principal_type' => 'student',
            'principal_id' => trim($studentNumber),
            'token_hash' => hash('sha256', $plainSessionToken),
            'sii_token' => Crypt::encryptString($siiToken),
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'session_token' => $plainSessionToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('app_session')?->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /**
     * El JWT compartido por el usuario muestra un exp de una hora después de iat.
     * La sesión de la aplicación nunca debe vivir más que el JWT institucional.
     * El claim solo acorta el límite local de 8 horas; no se usa para autenticar.
     */
    private function sessionExpirationFromJwt(string $jwt): CarbonImmutable
    {
        $parts = explode('.', $jwt);
        if (count($parts) === 3) {
            $encodedPayload = strtr($parts[1], '-_', '+/');
            $encodedPayload .= str_repeat('=', (4 - strlen($encodedPayload) % 4) % 4);
            $decodedPayload = base64_decode($encodedPayload, true);
            $claims = $decodedPayload === false ? null : json_decode($decodedPayload, true);

            if (is_array($claims) && isset($claims['exp']) && is_numeric($claims['exp'])) {
                $jwtExpiry = CarbonImmutable::createFromTimestampUTC((int) $claims['exp']);
                $localMaximum = CarbonImmutable::now(config('app.timezone'))->addHours(8);

                return $jwtExpiry->lessThan($localMaximum) ? $jwtExpiry->setTimezone(config('app.timezone')) : $localMaximum;
            }
        }

        // Fallback conservador si el proveedor cambiara el formato del JWT.
        return CarbonImmutable::now(config('app.timezone'))->addHour();
    }
}
