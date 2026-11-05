<?php

namespace Tests\Feature;

use App\Services\SiiAuthenticationException;
use App\Services\SiiService;
use App\Services\SiiTokenExpiredException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiiServiceContractTest extends TestCase
{
    private const BASE = 'https://sii.celaya.tecnm.mx/api';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sii.base_url' => self::BASE]);
        config(['services.sii.timeout' => 10]);
        Http::preventStrayRequests();
    }

    public function test_login_consumes_the_real_sii_endpoint_and_extracts_message_login_token(): void
    {
        $fakeJwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ0ZXN0IiwiZXhwIjo5OTk5OTk5OTk5fQ.signature';

        Http::fake([
            self::BASE.'/login' => Http::response([
                'responseCodeTxt' => '200 OK',
                'message' => ['login' => ['token' => $fakeJwt]],
                'status' => 200,
                'flag' => 'success',
                'data' => 200,
                'type' => 'estudiante',
            ], 200),
        ]);

        $token = app(SiiService::class)->login('alumno@celaya.tecnm.mx', '  Clave de prueba  ');

        $this->assertSame($fakeJwt, $token);
        Http::assertSent(fn (Request $request): bool =>
            $request->method() === 'POST'
            && $request->url() === self::BASE.'/login'
            && $request['email'] === 'alumno@celaya.tecnm.mx'
            && $request['password'] === '  Clave de prueba  '
        );
    }

    public function test_login_recognizes_the_sii_json_401_even_when_http_status_is_200(): void
    {
        Http::fake([
            self::BASE.'/login' => Http::response([
                'responseCodeTxt' => '401 Unauthorized',
                'message' => null,
                'status' => 401,
                'flag' => null,
                'data' => 401,
            ], 200),
        ]);

        try {
            app(SiiService::class)->login('alumno@celaya.tecnm.mx', 'incorrecta');
            $this->fail('Se esperaba una excepción por credenciales incorrectas.');
        } catch (SiiAuthenticationException) {
            $this->assertTrue(true);
        }

        Http::assertSent(fn (Request $request): bool =>
            $request->method() === 'POST' && $request->url() === self::BASE.'/login'
        );
    }

    public function test_login_recognizes_an_http_401_as_invalid_credentials(): void
    {
        Http::fake([
            self::BASE.'/login' => Http::response([
                'responseCodeTxt' => '401 Unauthorized',
                'message' => null,
                'status' => 401,
                'flag' => null,
                'data' => 401,
            ], 401),
        ]);

        $this->expectException(SiiAuthenticationException::class);
        app(SiiService::class)->login('alumno@celaya.tecnm.mx', 'incorrecta');
    }

    public function test_all_four_student_endpoints_are_consumed_with_the_bearer_jwt(): void
    {
        Http::fake([
            self::BASE.'/movil/estudiante' => Http::response([
                'code' => 200,
                'message' => 'Bienvenido al aplicación del tecnm',
                'flag' => true,
                'data' => ['numero_control' => '00000001', 'persona' => 'ALUMNO DE PRUEBA'],
            ], 200),
            self::BASE.'/movil/estudiante/calificaciones' => Http::response([
                'code' => 200,
                'message' => 'Calificaciones del alumno en este periodo',
                'flag' => true,
                'data' => [[
                    'periodo' => ['clave_periodo' => '26/2'],
                    'materias' => [[
                        'materia' => ['clave_materia' => 'IDD-2502'],
                        // Se conserva exactamente la ortografía usada por el SII.
                        'calificaiones' => [
                            ['numero_calificacion' => 1, 'calificacion' => '91'],
                            ['numero_calificacion' => 2, 'calificacion' => null],
                        ],
                    ]],
                ]],
            ], 200),
            self::BASE.'/movil/estudiante/kardex' => Http::response([
                'code' => 200,
                'message' => 'Kardex del alumno',
                'flag' => true,
                'data' => ['porcentaje_avance' => 90.38, 'kardex' => [['clave_materia' => 'ACF-2301', 'calificacion' => '75']]],
            ], 200),
            self::BASE.'/movil/estudiante/horarios' => Http::response([
                'code' => 200,
                'message' => 'Horario del alumno en este periodo',
                'flag' => true,
                'data' => [[
                    'periodo' => ['clave_periodo' => '26/2'],
                    'horario' => [['nombre_materia' => 'CIBERSEGURIDAD', 'lunes' => '12:00-13:00']],
                ]],
            ], 200),
        ]);

        $service = app(SiiService::class);
        $token = 'jwt-de-prueba-no-institucional';

        $profile = $service->studentProfile($token);
        $grades = $service->grades($token);
        $kardex = $service->kardex($token);
        $schedule = $service->schedule($token);

        $this->assertSame('00000001', $profile['numero_control']);
        $this->assertSame('91', $grades[0]['materias'][0]['calificaiones'][0]['calificacion']);
        $this->assertNull($grades[0]['materias'][0]['calificaiones'][1]['calificacion']);
        $this->assertSame(90.38, $kardex['porcentaje_avance']);
        $this->assertSame('12:00-13:00', $schedule[0]['horario'][0]['lunes']);

        Http::assertSentCount(4);

        $expectedUrls = [
            self::BASE.'/movil/estudiante',
            self::BASE.'/movil/estudiante/calificaciones',
            self::BASE.'/movil/estudiante/kardex',
            self::BASE.'/movil/estudiante/horarios',
        ];

        foreach ($expectedUrls as $url) {
            Http::assertSent(fn (Request $request): bool =>
                $request->method() === 'GET'
                && $request->url() === $url
                && $request->hasHeader('Authorization', 'Bearer '.$token)
            );
        }
    }

    public function test_student_endpoint_treats_a_json_401_as_an_expired_jwt(): void
    {
        Http::fake([
            self::BASE.'/movil/estudiante' => Http::response([
                'responseCodeTxt' => '401 Unauthorized',
                'message' => null,
                'status' => 401,
                'flag' => null,
                'data' => 401,
            ], 200),
        ]);

        $this->expectException(SiiTokenExpiredException::class);
        app(SiiService::class)->studentProfile('jwt-de-prueba');
    }
}
