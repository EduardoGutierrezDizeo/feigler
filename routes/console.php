<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Cada noche se barre un día de carritos abandonados; el scheduler corre en el
// servidor (o `php artisan schedule:work` en local), la política de cuánto se
// conserva vive en el propio comando con sus edades por defecto.
Schedule::command('carts:prune')->daily();

// Y los archivos temporales de la carga masiva que nadie llegó a confirmar: la
// copia privada se guarda al revisar y se borra al confirmar o cancelar, así que
// lo que quede a estas horas es lo que quedó a medias.
Schedule::command('bulk-imports:prune')->daily();
