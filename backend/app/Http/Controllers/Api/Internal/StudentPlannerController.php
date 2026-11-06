<?php

namespace App\Http\Controllers\Api\Internal;

use App\Models\AppSession;
use App\Services\AcademicPlannerService;
use App\Services\SiiService;
use App\Services\SiiTokenExpiredException;
use App\Services\SiiUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class StudentPlannerController
{
    public function __construct(
        private readonly SiiService $sii,
        private readonly AcademicPlannerService $planner,
    ) {
    }

    public function preview(Request $request): JsonResponse
    {
        // La selección de max_credits se conserva solo durante esta primera
        // entrega para no romper aún el formulario. En la siguiente etapa se
        // quitará del cliente y se resolverá exclusivamente desde load_rules.
        $params = $request->validate([
            'max_credits' => ['required', 'integer', 'between:10,40'],
            'max_periods' => ['sometimes', 'integer', 'between:1,12'],
        ]);

        /** @var AppSession $session */
        $session = $request->attributes->get('app_session');

        try {
            $token = Crypt::decryptString((string) $session->sii_token);
            $kardex = $this->sii->kardex($token);
            $grades = $this->sii->grades($token);
        } catch (SiiTokenExpiredException) {
            $session->delete();
            return response()->json(['message' => 'La sesión del SII expiró. Inicia sesión nuevamente.'], 401);
        } catch (SiiUnavailableException) {
            return response()->json(['message' => 'No fue posible consultar el historial institucional.'], 503);
        }

        // No se persisten parámetros, resultados ni identificadores del alumno.
        // Cada simulación se reconstruye con los datos institucionales actuales.
        $result = $this->planner->preview(
            $kardex,
            $grades,
            (int) $params['max_credits'],
            (int) ($params['max_periods'] ?? 8),
        );

        return response()->json(['data' => $result]);
    }
}
