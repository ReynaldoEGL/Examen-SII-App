<?php

use App\Http\Middleware\AuthenticateAppSession;
use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsureAdminSession;
use App\Http\Middleware\EnsureStudentSession;
use App\Http\Middleware\VerifyInternalSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'service.signature' => VerifyInternalSignature::class,
            'app.session' => AuthenticateAppSession::class,
            'student.session' => EnsureStudentSession::class,
            'admin.session' => EnsureAdminSession::class,
            'admin.role' => EnsureAdminRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Los controladores devuelven mensajes genéricos; no se exponen trazas ni respuestas del SII.
    })
    ->create();
