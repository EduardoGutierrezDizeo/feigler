<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Storefront\CartService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;

/**
 * Deja el carrito del visitante en manos de su cuenta cuando inicia sesión.
 *
 * Es el único momento en que un invitado de la tienda pasa a cliente: la cookie
 * `cart_token` que trae la petición señala su carrito, y CartService lo entrega
 * a la cuenta recién autenticada. El personal interno queda fuera a propósito:
 * no recibe carrito ni se le toca la cookie. Una cuenta desactivada tampoco,
 * aunque a esta altura ya no debería llegar (el propio login la rechaza); se
 * compara contra `false` exacto porque el modelo de un cliente recién
 * registrado no trae `is_active` cargado en memoria —lo puso el default de la
 * base— y un `null` no debe contar como desactivada. Un fallo en la fusión se
 * registra en el log y nunca corta el inicio de sesión: lo que se perdió es
 * solo un carrito de visita, nunca la entrada a la cuenta.
 */
class MergeVisitorCartOnLogin
{
    public function __construct(private readonly CartService $cartService) {}

    /**
     * Handle the event.
     */
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        if (! $user->hasRole('cliente') || $user->is_active === false) {
            return;
        }

        try {
            $capped = $this->cartService->mergeVisitorCartIntoAccount($user);

            if ($capped) {
                app('request')->session()->put(
                    CartService::FUSION_NOTICE_SESSION_KEY,
                    CartService::MESSAGE_FUSION_CAPPED,
                );
            }
        } catch (\Throwable $exception) {
            Log::warning('No se pudo unir el carrito del visitante con su cuenta: '.$exception->getMessage(), [
                'user_id' => $user->getKey(),
            ]);
        }
    }
}
