<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\AccountTab;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccountProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Mi cuenta del cliente: la página con sus pestañas y la actualización del
 * perfil. El servidor renderiza la pestaña activa; el cliente solo edita los
 * datos que la tienda le deja tocar (nombre, apellido y teléfono).
 */
class AccountController extends Controller
{
    /**
     * La página de Mi cuenta con la pestaña activa según `?tab=`.
     */
    public function index(Request $request): View
    {
        return view('storefront.account', [
            'user' => $request->user(),
            'activeTab' => AccountTab::fromRequest($request),
        ]);
    }

    /**
     * Actualiza los datos que el cliente puede editar. El correo, el rol y el
     * estado quedan fuera a propósito: no se tocan aunque lleguen en el envío.
     */
    public function updateProfile(AccountProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->fill([
            'name' => $request->validated('name'),
            'last_name' => $request->validated('last_name'),
            'phone' => $request->validated('phone'),
        ]);

        $user->save();

        return redirect()
            ->route('account.index', ['tab' => AccountTab::Perfil->value])
            ->with('status', 'profile-updated');
    }
}
