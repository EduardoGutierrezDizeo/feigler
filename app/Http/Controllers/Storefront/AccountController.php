<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\AccountTab;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccountProfileUpdateRequest;
use App\Http\Requests\DeleteAccountRequest;
use App\Services\Storefront\DeleteCustomerAccount;
use App\Support\ColombiaLocations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;

/**
 * Mi cuenta del cliente: la página con sus pestañas y la actualización del
 * perfil. El servidor renderiza la pestaña activa; el cliente solo edita los
 * datos que la tienda le deja tocar (nombre, apellido y teléfono en Perfil,
 * contraseña en Seguridad y direcciones en Direcciones y su baja en Eliminar
 * cuenta).
 */
class AccountController extends Controller
{
    public function __construct(private readonly DeleteCustomerAccount $deleteAccount) {}

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

        if ($data['activeTab'] === AccountTab::Eliminar) {
            $data['deleteAccountModalOpen'] = $this->deleteAccountModalOpen($request);
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
     * Borra o anonimiza la cuenta del cliente y cierra su sesión.
     *
     * La contraseña actual ya la verificó DeleteAccountRequest; los campos extra
     * se ignoran y la ruta solo opera sobre el usuario autenticado. El cierre de
     * sesión va antes del borrado: al rotar el token de recordar se guarda la
     * fila, y si el borrado ya hubiera quitado la fila, ese guardado volvería a
     * insertarla. Después de la baja se invalida la sesión y se regenera el
     * token, y el flash de la portada confirma la eliminación.
     */
    public function destroy(DeleteAccountRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::guard('web')->logout();

        $this->deleteAccount->delete($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', 'account-deleted');
    }

    /**
     * La pestaña Eliminar cuenta arranca con el modal abierto cuando el último
     * envío falló: la bolsa «deleteAccount» trae los errores de la contraseña.
     */
    private function deleteAccountModalOpen(Request $request): bool
    {
        $bag = $request->session()->get('errors');

        return $bag instanceof ViewErrorBag && $bag->getBag('deleteAccount')->isNotEmpty();
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
