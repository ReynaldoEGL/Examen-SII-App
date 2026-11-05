<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('about:security', function (): void {
    $this->line('El navegador solo debe comunicarse con Next.js. Mantén secretos fuera del frontend.');
})->purpose('Muestra una nota de seguridad del proyecto');
