<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\AccountTab;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAddressRequest;
use App\Http\Requests\UpdateAddressRequest;
use App\Models\Address;
use App\Services\Storefront\CustomerAddresses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Las direcciones del cliente desde la pestaña Direcciones de Mi cuenta.
 *
 * Cada dirección ajena al cliente autenticado responde 404 sin tocar nada:
 * todas las mutaciones resuelven el modelo acotado a $request->user().
 */
class AddressController extends Controller
{
    /**
     * Guarda una dirección nueva y vuelve a la pestaña Direcciones.
     */
    public function store(StoreAddressRequest $request, CustomerAddresses $addresses): RedirectResponse
    {
        $addresses->store($request->user(), $request->validated());

        return $this->redirectWith('address-stored');
    }

    /**
     * Actualiza una dirección propia del cliente.
     */
    public function update(UpdateAddressRequest $request, string $address, CustomerAddresses $addresses): RedirectResponse
    {
        $addresses->update($this->ownAddress($request, $address), $request->validated());

        return $this->redirectWith('address-updated');
    }

    /**
     * Deja esta dirección como la predeterminada del cliente.
     */
    public function makeDefault(Request $request, string $address, CustomerAddresses $addresses): RedirectResponse
    {
        $addresses->makeDefault($this->ownAddress($request, $address));

        return $this->redirectWith('address-default');
    }

    /**
     * Elimina una dirección del cliente.
     */
    public function destroy(Request $request, string $address, CustomerAddresses $addresses): RedirectResponse
    {
        $addresses->delete($this->ownAddress($request, $address));

        return $this->redirectWith('address-deleted');
    }

    /**
     * La dirección del cliente autenticado; una que no sea suya da 404.
     */
    private function ownAddress(Request $request, string $address): Address
    {
        return $request->user()->addresses()->findOrFail((int) $address);
    }

    private function redirectWith(string $status): RedirectResponse
    {
        return redirect()
            ->route('account.index', ['tab' => AccountTab::Direcciones->value])
            ->with('status', $status);
    }
}
