<?php

namespace App\Providers;

use App\Services\Storefront\CartService;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * The image manager is bound here and not injected by the package because
     * `intervention/image` is used without its Laravel wrapper: the one thing
     * this application asks of it is the thumbnails of the pictures, and that
     * runs on GD, which is compiled with WebP.
     *
     * `CartService` atiende la petición que el contenedor tiene en curso (qué
     * persona y qué cookie), así que tiene que ser una instancia nueva por
     * petición y la misma durante toda ella: eso es exactamente lo que `scoped`
     * resuelve.
     */
    public function register(): void
    {
        $this->app->bind(ImageManager::class, fn (): ImageManager => new ImageManager(new GdDriver));
        $this->app->scoped(CartService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
