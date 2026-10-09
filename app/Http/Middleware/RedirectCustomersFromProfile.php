<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectCustomersFromProfile
{
    /**
     * Cierra /profile para los clientes: leer lleva a Mi cuenta y las
     * mutaciones responden 403, porque el correo y la baja de la cuenta solo
     * los gestiona la tienda. El resto de usuarios sigue como hasta ahora.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->hasRole('cliente')) {
            return $request->isMethod('GET')
                ? redirect()->route('account.index')
                : abort(403);
        }

        return $next($request);
    }
}
