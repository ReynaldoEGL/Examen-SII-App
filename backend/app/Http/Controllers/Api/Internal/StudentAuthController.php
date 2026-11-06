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

        // La clave es independiente de APP_KEY e INTERNAL_SHARED_SECRET.
        // Una configuración ausente/insegura debe impedir el login; nunca se
        // debe degradar silenciosamente a guardar el número de control.
        $pseudonymKey = (string) config('services.pseudonym_key');
        if (strlen($pseudonymKey) < 32) {
            return response()->json([
                'message' => 'La configuración de seguridad del servidor está incompleta.',
            ], 500);
        }

        try {
            // El JWT solo existe en Laravel. Se valida consultando el perfil del SII.
            $siiToken = $sii->login($credentials['email'], $credentials['password']);
            $profile = $sii->studentProfile($siiToken);
        } catch (SiiAuthenticationException) {
            return response()->json(['message' => 'Correo o contraseña incorrectos.'], 401);
        } catch (SiiTokenExpiredException) {
            return response()->json([
                'message' => 'El SII no aceptó el token recién emitido. Intenta nuevamente más tarde.',
            ], 502);
        } catch (SiiUnavailableException) {
            return response()->json([
                'message' => 'No se pudo validar con el SII. Intenta nuevamente más tarde.',
            ], 503);
        }

        // El número de control se obtiene del perfil institucional, se utiliza
        // solo en memoria para calcular el HMAC y nunca se guarda en claro.
        $studentNumber = data_get($profile, 'numero_control');
        if ((!is_string($studentNumber) && !is_int($studentNumber)) || trim((string) $studentNumber) === '') {
            return response()->json([
                'message' => 'El perfil institucional no contiene un número de control válido.',
            ], 502);
        }

        $normalizedStudentNumber = strtoupper(trim((string) $studentNumber));
        if (strlen($normalizedStudentNumber) > 64) {
            return response()->json([
                'message' => 'El identificador institucional tiene un formato inesperado.',
            ], 502);
        }

        // Identificador seudónimo estable. Permite agrupar sesiones del mismo
        // alumno (límite de sesiones y limpieza) sin persistir su matrícula.
        // El formato hexadecimal de 64 caracteres coincide con el CHECK SQL.
        $studentHash = hash_hmac('sha256', $normalizedStudentNumber, $pseudonymKey);

        $expiresAt = $this->sessionExpirationFromJwt($siiToken);
        if ($expiresAt->lessThanOrEqualTo(now()->addSeconds(15))) {
            return response()->json([
                'message' => 'El token institucional está vencido o próximo a vencer. Inicia sesión nuevamente.',
            ], 502);
        }

        // Este identificador aleatorio es la sesión de NUESTRA aplicación,
        // no el JWT del SII. La base solo conserva su SHA-256.
        $plainSessionToken = bin2hex(random_bytes(32));

        AppSession::query()->create([
            'principal_type' => 'student',
            'principal_id' => $studentHash,
            'token_hash' => hash('sha256', $plainSessionToken),
            'sii_token' => Crypt::encryptString($siiToken),
            'expires_at' => $expiresAt,
        ]);

        // Next.js recibe solo el identificador opaco de sesión local. El JWT,
        // el número de control y el HMAC nunca se incluyen en esta respuesta.
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
     * El claim exp únicamente limita la duración local. No se considera una
     * validación criptográfica del JWT: el SII sigue siendo la autoridad.
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

                return $jwtExpiry->lessThan($localMaximum)
                    ? $jwtExpiry->setTimezone(config('app.timezone'))
                    : $localMaximum;
            }
        }

        // Fallback conservador si el proveedor cambia el formato del JWT.
        return CarbonImmutable::now(config('app.timezone'))->addHour();
    }
}
