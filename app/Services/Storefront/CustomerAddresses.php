<?php

namespace App\Services\Storefront;

use App\Models\Address;
use App\Models\User;
use App\Support\ColombiaLocations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Las reglas de negocio de las direcciones del cliente.
 *
 * Todo pase por aquí: el controlador y los formularios no deciden ni el límite
 * ni la predeterminada. El nombre de departamento y de ciudad que se guarda
 * siempre sale de ColombiaLocations a partir de los códigos DANE, nunca del
 * texto que envía el cliente.
 */
class CustomerAddresses
{
    /**
     * Cuántas direcciones puede guardar cada cliente.
     */
    public const LIMITE = 5;

    /**
     * Guarda una dirección nueva; la primera queda predeterminada sola.
     *
     * La sexta se rechaza con un error de validación visible en la bolsa
     * «address», como las reglas de los formularios.
     *
     * @param  array<string, mixed>  $datos  los campos validados del formulario
     */
    public function store(User $user, array $datos): Address
    {
        return DB::transaction(function () use ($user, $datos) {
            if ($user->addresses()->count() >= self::LIMITE) {
                throw ValidationException::withMessages([
                    'direcciones' => 'Puedes guardar hasta 5 direcciones.',
                ])->errorBag('address');
            }

            $address = new Address($this->campos($datos));

            $address->user_id = $user->id;
            $address->is_default = ! $user->addresses()->exists();

            $address->save();

            return $address;
        });
    }

    /**
     * Actualiza una dirección propia del cliente.
     *
     * @param  array<string, mixed>  $datos  los campos validados del formulario
     */
    public function update(Address $address, array $datos): Address
    {
        $address->forceFill($this->campos($datos))->save();

        return $address;
    }

    /**
     * Deja una única predeterminada: la cambia de forma atómica.
     */
    public function makeDefault(Address $address): void
    {
        DB::transaction(function () use ($address) {
            $address->user->addresses()
                ->whereKeyNot($address->getKey())
                ->update(['is_default' => false]);

            $address->is_default = true;
            $address->save();
        });
    }

    /**
     * Elimina una dirección. Si era la predeterminada, la más reciente de las
     * que quedan pasa a serlo; si era la única, el cliente se queda sin ninguna.
     */
    public function delete(Address $address): void
    {
        DB::transaction(function () use ($address) {
            $address->delete();

            if (! $address->is_default) {
                return;
            }

            $restante = $address->user->addresses()->latest('id')->first();

            if ($restante !== null) {
                $restante->is_default = true;
                $restante->save();
            }
        });
    }

    /**
     * Reduce los campos validados a las columnas que guarda la dirección, con
     * el nombre oficial de departamento y ciudad desde sus códigos DANE.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function campos(array $datos): array
    {
        $departamento = ColombiaLocations::department((string) $datos['department_code']);
        $ciudad = ColombiaLocations::city((string) $datos['city_code']);

        return [
            'recipient_name' => $datos['recipient_name'],
            'phone' => $datos['phone'],
            'department_code' => (string) $datos['department_code'],
            'department' => $departamento['name'] ?? null,
            'city_code' => (string) $datos['city_code'],
            'city' => $ciudad['name'] ?? null,
            'label' => $datos['label'] ?? null,
            'line1' => $datos['line1'],
            'line2' => $datos['line2'] ?? null,
            'instructions' => $datos['instructions'] ?? null,
        ];
    }
}
