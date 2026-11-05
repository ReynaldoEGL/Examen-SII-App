<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Único punto de salida hacia el API institucional.
 * No registra credenciales, JWT ni cuerpos de respuesta que puedan contener datos personales.
 */
class SiiService
{
    public function login(string $email, string $password): string
    {
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout((int) config('services.sii.timeout', 15))
                ->post($this->url('/login'), [
                    'email' => $email,
                    'password' => $password,
                ]);
        } catch (ConnectionException) {
            throw new SiiUnavailableException('El servicio institucional no está disponible.');
        }

        $json = $response->json();
        $payload = is_array($json) ? $json : [];
        $apiStatus = $this->envelopeStatus($payload);

        // El SII puede responder HTTP 200 y comunicar el 401 dentro del JSON.
        if (in_array($response->status(), [401, 403, 422], true)
            || in_array($apiStatus, [401, 403, 422], true)) {
            throw new SiiAuthenticationException('Credenciales no válidas.');
        }

        if (!$response->successful()) {
            throw new SiiUnavailableException('No fue posible autenticar con el servicio institucional.');
        }

        if ($apiStatus !== null && $apiStatus !== 200) {
            throw new SiiUnavailableException('El servicio institucional devolvió un estado inesperado.');
        }

        $token = data_get($payload, 'message.login.token');
        if (!is_string($token) || $token === '' || substr_count($token, '.') !== 2) {
            throw new SiiUnavailableException('La respuesta de autenticación del SII no contiene un JWT válido.');
        }

        $flag = $payload['flag'] ?? null;
        if ($flag !== null && $flag !== 'success') {
            throw new SiiUnavailableException('El SII no confirmó la autenticación.');
        }

        $accountType = $payload['type'] ?? null;
        if ($accountType !== null && $accountType !== 'estudiante') {
            throw new SiiAuthenticationException('La cuenta no corresponde a un estudiante.');
        }

        return $token;
    }

    public function studentProfile(string $token): array
    {
        return $this->getStudentData($token, '/movil/estudiante');
    }

    public function grades(string $token): array
    {
        return $this->getStudentData($token, '/movil/estudiante/calificaciones');
    }

    public function kardex(string $token): array
    {
        return $this->getStudentData($token, '/movil/estudiante/kardex');
    }

    public function schedule(string $token): array
    {
        return $this->getStudentData($token, '/movil/estudiante/horarios');
    }

    private function getStudentData(string $token, string $path): array
    {
        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->connectTimeout(5)
                ->timeout((int) config('services.sii.timeout', 15))
                ->get($this->url($path));
        } catch (ConnectionException) {
            throw new SiiUnavailableException('No fue posible conectar con el servicio institucional.');
        }

        $json = $response->json();
        $payload = is_array($json) ? $json : [];
        $apiStatus = $this->envelopeStatus($payload);

        // El vencimiento también puede llegar dentro del JSON aunque el HTTP sea 200.
        if (in_array($response->status(), [401, 403], true)
            || in_array($apiStatus, [401, 403], true)) {
            throw new SiiTokenExpiredException('La sesión institucional expiró.');
        }

        if (!$response->successful()) {
            throw new SiiUnavailableException('El servicio institucional devolvió un error HTTP.');
        }

        if ($apiStatus !== null && $apiStatus !== 200) {
            throw new SiiUnavailableException('El servicio institucional devolvió un estado inesperado.');
        }

        if (array_key_exists('flag', $payload) && $payload['flag'] !== true) {
            throw new SiiUnavailableException('El servicio institucional no confirmó la consulta.');
        }

        if (isset($payload['code']) && (int) $payload['code'] !== 200) {
            throw new SiiUnavailableException('La respuesta del servicio institucional no fue válida.');
        }

        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            throw new SiiUnavailableException('La respuesta del servicio institucional no contiene datos válidos.');
        }

        return $data;
    }

    /**
     * Lee el código de la envoltura institucional. En login el API usa `status`
     * y `responseCodeTxt`; en los GET de estudiante usa `code`.
     */
    private function envelopeStatus(array $payload): ?int
    {
        $candidates = [];

        foreach (['status', 'code'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                $candidates[] = (int) $payload[$key];
            }
        }

        $responseCode = $payload['responseCodeTxt'] ?? null;
        if (is_string($responseCode) && preg_match('/^\s*(\d{3})\b/', $responseCode, $matches) === 1) {
            $candidates[] = (int) $matches[1];
        }

        // En las respuestas de login del SII, `data` también contiene 200 o 401.
        if (isset($payload['data']) && is_numeric($payload['data'])) {
            $candidates[] = (int) $payload['data'];
        }

        // Si una futura envoltura fuera inconsistente, nunca ocultar un 4xx/5xx
        // porque otro campo haya quedado accidentalmente en 200.
        foreach ($candidates as $candidate) {
            if ($candidate >= 400) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.sii.base_url'), '/').'/'.ltrim($path, '/');
    }
}
