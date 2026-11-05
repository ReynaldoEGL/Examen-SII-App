<?php

namespace Tests\Feature;

use App\Models\AppSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentLoginFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SII_BASE = 'https://sii.celaya.tecnm.mx/api';
    private const INTERNAL_SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sii.base_url' => self::SII_BASE,
            'services.sii.timeout' => 10,
            'services.internal.shared_secret' => self::INTERNAL_SECRET,
        ]);

        Http::preventStrayRequests();
    }

    public function test_login_flow_consumes_sii_and_creates_only_an_opaque_local_session(): void
    {
        $jwt = $this->fakeJwt(now()->addHour()->timestamp);

        Http::fake([
            self::SII_BASE.'/login' => Http::response([
                'responseCodeTxt' => '200 OK',
                'message' => ['login' => ['token' => $jwt]],
                'status' => 200,
                'flag' => 'success',
                'data' => 200,
                'type' => 'estudiante',
            ], 200),
            self::SII_BASE.'/movil/estudiante' => Http::response([
                'code' => 200,
                'message' => 'Bienvenido al aplicación del tecnm',
                'flag' => true,
                'data' => [
                    'numero_control' => '00000001',
                    'persona' => 'ESTUDIANTE DE PRUEBA',
                ],
            ], 200),
        ]);

        $response = $this->signedInternalPost('/api/internal/student/login', [
            'email' => 'alumno@celaya.tecnm.mx',
            'password' => '  Clave de prueba  ',
        ]);

        $response->assertOk()->assertJsonStructure(['session_token', 'expires_at']);
        $sessionToken = (string) $response->json('session_token');
        $this->assertSame(64, strlen($sessionToken));

        $session = AppSession::query()->sole();
        $this->assertSame('student', $session->principal_type);
        $this->assertSame('00000001', $session->principal_id);
        $this->assertSame(hash('sha256', $sessionToken), $session->token_hash);
        $this->assertSame($jwt, Crypt::decryptString((string) $session->sii_token));
        $this->assertTrue($session->expires_at->greaterThan(now()));

        Http::assertSent(fn ($request): bool =>
            $request->method() === 'POST'
            && $request->url() === self::SII_BASE.'/login'
            && $request['email'] === 'alumno@celaya.tecnm.mx'
            && $request['password'] === '  Clave de prueba  '
        );

        Http::assertSent(fn ($request): bool =>
            $request->method() === 'GET'
            && $request->url() === self::SII_BASE.'/movil/estudiante'
            && $request->hasHeader('Authorization', 'Bearer '.$jwt)
        );
    }

    public function test_sii_json_401_inside_http_200_is_returned_as_login_401_and_creates_no_session(): void
    {
        Http::fake([
            self::SII_BASE.'/login' => Http::response([
                'responseCodeTxt' => '401 Unauthorized',
                'message' => null,
                'status' => 401,
                'flag' => null,
                'data' => 401,
            ], 200),
        ]);

        $response = $this->signedInternalPost('/api/internal/student/login', [
            'email' => 'alumno@celaya.tecnm.mx',
            'password' => 'incorrecta',
        ]);

        $response->assertUnauthorized()
            ->assertJson(['message' => 'Correo o contraseña incorrectos.']);

        $this->assertDatabaseCount('app_sessions', 0);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool =>
            $request->method() === 'POST' && $request->url() === self::SII_BASE.'/login'
        );
    }

    /** @param array<string, string> $payload */
    private function signedInternalPost(string $path, array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = (string) Str::uuid();
        $method = 'POST';
        $session = '';
        $canonical = implode("\n", [
            $timestamp,
            $nonce,
            $method,
            $path,
            hash('sha256', $body),
            hash('sha256', $session),
        ]);
        $signature = hash_hmac('sha256', $canonical, self::INTERNAL_SECRET);

        return $this->call('POST', $path, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SERVICE_TIMESTAMP' => $timestamp,
            'HTTP_X_SERVICE_NONCE' => $nonce,
            'HTTP_X_SERVICE_SIGNATURE' => $signature,
        ], $body);
    }

    private function fakeJwt(int $expiresAt): string
    {
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
        $payload = $this->base64UrlEncode(json_encode(['sub' => 'test-student', 'exp' => $expiresAt], JSON_THROW_ON_ERROR));

        return $header.'.'.$payload.'.test-signature';
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
