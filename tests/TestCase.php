<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Vuelve a arrancar la aplicación bajo el entorno indicado y la deja como la
     * activa del test.
     *
     * Las rutas de la vista previa de la tienda solo existen en local, así que
     * para verlas responder hay que arrancar como local, y para comprobar que no
     * existen hay que arrancar como producción. APP_ENV solo decide mientras la
     * aplicación arranca (ahí es donde routes/web.php hace su guardia), por lo
     * que en cuanto la instancia nueva está creada se restaura el valor original
     * y el resto del proceso sigue viendo `testing`.
     *
     * La instancia que arranca aquí no comparte la transacción de
     * RefreshDatabase con la del setUp, así que sirve para peticiones de solo
     * lectura: es el caso de las vistas de la tienda, que no tocan la base.
     */
    protected function refreshApplicationIn(string $environment): void
    {
        $hadEnvironment = array_key_exists('APP_ENV', $_ENV);
        $hadServer = array_key_exists('APP_ENV', $_SERVER);
        $previous = [
            $_ENV['APP_ENV'] ?? null,
            $_SERVER['APP_ENV'] ?? null,
            getenv('APP_ENV'),
        ];

        $_ENV['APP_ENV'] = $environment;
        $_SERVER['APP_ENV'] = $environment;
        putenv('APP_ENV='.$environment);

        try {
            $this->refreshApplication();
        } finally {
            if ($hadEnvironment) {
                $_ENV['APP_ENV'] = $previous[0];
            } else {
                unset($_ENV['APP_ENV']);
            }

            if ($hadServer) {
                $_SERVER['APP_ENV'] = $previous[1];
            } else {
                unset($_SERVER['APP_ENV']);
            }

            if ($previous[2] === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV='.$previous[2]);
            }
        }
    }
}
