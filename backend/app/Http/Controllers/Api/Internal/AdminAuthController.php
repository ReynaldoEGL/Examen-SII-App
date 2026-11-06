<?php

namespace App\Http\Controllers\Api\Internal;

use App\Models\Admin;
use App\Models\AppSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminAuthController
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        // La restricción SQL es única sobre lower(email); normalizamos también
        // la búsqueda para que el comportamiento coincida con el índice.
        $email = mb_strtolower(trim($credentials['email']));
        $admin = Admin::query()->where('email', $email)->where('active', true)->first();

        // Ejecuta una verificación aunque el correo no exista.
        $dummyHash = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
        $valid = Hash::check($credentials['password'], $admin?->password ?? $dummyHash);

        if (!$admin || !$valid) {
            return response()->json(['message' => 'Credenciales administrativas incorrectas.'], 422);
        }

        $plainSessionToken = bin2hex(random_bytes(32));
        $expiresAt = now()->addHours(4);

        AppSession::query()->create([
            'principal_type' => 'admin',
            'principal_id' => (string) $admin->getKey(),
            'token_hash' => hash('sha256', $plainSessionToken),
            'sii_token' => null,
            'expires_at' => $expiresAt,
        ]);

        $admin->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'session_token' => $plainSessionToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $admin = $request->attributes->get('admin');

        return response()->json([
            'data' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('app_session')?->delete();

        return response()->json(['message' => 'Sesión administrativa cerrada.']);
    }
}
