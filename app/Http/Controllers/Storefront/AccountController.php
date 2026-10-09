<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\AccountTab;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccountProfileUpdateRequest;
use App\Support\ColombiaLocations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;

/**
 * Mi cuenta del cliente: la página con sus pestañas y la actualización del
 * perfil. El servidor renderiza la pestaña activa; el cliente solo edita los
 * datos que la tienda le deja tocar (nombre, apellido y teléfono en Perfil,
 * contraseña en Seguridad y direcciones en Direcciones).
 */
class AccountController extends Controller
{
    /**
     * La página de Mi cuenta con la pestaña activa según `?tab=`.
     */
    public function index(Request $request): View
    {
        $data = [
            'user' => $request->user(),
            'activeTab' => AccountTab::fromRequest($request),
        ];

        // Los datos de ubicación y las direcciones solo se entregan a la vista
        // cuando la pestaña activa es Direcciones, y no en las demás pestañas
        // ni en el resto de la tienda.
        if ($data['activeTab'] === AccountTab::Direcciones) {
            $data += $this->direccionesData($request);
        }

        return view('storefront.account', $data);
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

    /**
     * @return array<string, mixed>
     */
    private function direccionesData(Request $request): array
    {
        $user = $request->user();

        // El modal arranca abierto y en el modo correcto cuando el último envío
        // falló: el campo oculto «address_mode» vale «create» o el id a editar.
        $bag = $request->session()->get('errors');
        $open = $bag instanceof ViewErrorBag && $bag->getBag('address')->isNotEmpty();

        $mode = $open ? (string) $request->old('address_mode', 'create') : 'create';
        $editingId = ctype_digit($mode) ? (int) $mode : null;
        $mode = $editingId !== null ? 'edit' : $mode;

        return [
            'addresses' => $user->addresses()->latest('id')->get(),
            'departments' => ColombiaLocations::departments(),
            'cities' => ColombiaLocations::citiesByDepartment(),
            'addressModalOpen' => $open,
            'addressModalMode' => $mode,
            'addressModalId' => $editingId,
            'addressModalOld' => $open ? $this->oldAddress($request) : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function oldAddress(Request $request): array
    {
        return [
            'recipient_name' => (string) $request->old('recipient_name', ''),
            'phone' => (string) $request->old('phone', ''),
            'department_code' => (string) $request->old('department_code', ''),
            'city_code' => (string) $request->old('city_code', ''),
            'label' => (string) $request->old('label', ''),
            'line1' => (string) $request->old('line1', ''),
            'line2' => (string) $request->old('line2', ''),
            'instructions' => (string) $request->old('instructions', ''),
        ];
    }
}
