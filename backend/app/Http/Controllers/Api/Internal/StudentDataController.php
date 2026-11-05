<?php

namespace App\Http\Controllers\Api\Internal;

use App\Models\AppSession;
use App\Services\SiiService;
use App\Services\SiiTokenExpiredException;
use App\Services\SiiUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class StudentDataController
{
    public function __construct(private readonly SiiService $sii)
    {
    }

    public function profile(Request $request): JsonResponse
    {
        return $this->respond($request, 'studentProfile', true);
    }

    public function grades(Request $request): JsonResponse
    {
        return $this->respond($request, 'grades');
    }

    public function kardex(Request $request): JsonResponse
    {
        return $this->respond($request, 'kardex');
    }

    public function schedule(Request $request): JsonResponse
    {
        return $this->respond($request, 'schedule');
    }

    private function respond(Request $request, string $method, bool $omitPhoto = false): JsonResponse
    {
        /** @var AppSession $session */
        $session = $request->attributes->get('app_session');

        try {
            $siiToken = Crypt::decryptString((string) $session->sii_token);
            $data = $this->sii->{$method}($siiToken);

            // El SII incluye una foto Base64 grande. Esta aplicación no la usa;
            // se elimina en el backend para no transportarla a Next.js sin necesidad.
            if ($omitPhoto) {
                unset($data['foto']);
            }

            return response()->json(['data' => $data]);
        } catch (SiiTokenExpiredException) {
            $session->delete();
            return response()->json(['message' => 'La sesión del SII expiró. Inicia sesión nuevamente.'], 401);
        } catch (SiiUnavailableException) {
            return response()->json(['message' => 'No fue posible obtener la información académica. Intenta nuevamente.'], 503);
        }
    }
}
