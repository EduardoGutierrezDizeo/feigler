<?php

namespace App\Services\Storefront;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * La baja de la cuenta del cliente, con la contraseña ya verificada por el
 * formulario. Todo ocurre en una transacción: o la cuenta desaparece entera, o
 * se anonimiza entera.
 *
 * - Sin pedidos: la fila se borra (spatie limpia sus roles con el evento
 *   `deleted` de HasRoles), junto con las direcciones y los favoritos.
 * - Con pedidos: la fila se conserva y se anonimiza, porque los pedidos siguen
 *   enlazados a su id y son obligación contable de la tienda. Nunca se invoca
 *   `delete()` sobre el usuario en este camino.
 *
 * En ambos caminos se limpian los tokens de restablecimiento del correo y las
 * sesiones activas de la tabla `sessions`. Los movimientos de inventario
 * quedan a cargo de su clave foránea (`nullOnDelete` cuando se borra la fila;
 * intactos cuando la fila se conserva).
 */
class DeleteCustomerAccount
{
    public const NOMBRE_ANONIMO = 'Cliente eliminado';

    public const DOMINIO_ANONIMO = 'eliminado.invalid';

    /**
     * Borra o anonimiza según haya pedidos.
     *
     * @return string «deleted» cuando se borró la fila, «anonymized» cuando se anonimizó
     */
    public function delete(User $user): string
    {
        return DB::transaction(function () use ($user): string {
            $user->addresses()->delete();
            $user->wishlists()->delete();

            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $mode = $user->orders()->exists() ? 'anonymized' : 'deleted';

            if ($mode === 'deleted') {
                $user->delete();
            } else {
                $user->forceFill([
                    'name' => self::NOMBRE_ANONIMO,
                    'last_name' => null,
                    'phone' => null,
                    'email' => $this->anonymousEmail($user),
                    'password' => Str::random(64),
                    'is_active' => false,
                    'email_verified_at' => null,
                ])->save();
            }

            Log::info('account.deleted', [
                'user_id' => $user->id,
                'mode' => $mode,
            ]);

            return $mode;
        });
    }

    /**
     * Un correo imposible de usar y único: el id hace único el valor y el
     * dominio reservado .invalid no se puede registrar.
     */
    private function anonymousEmail(User $user): string
    {
        return sprintf(
            'eliminado-%d-%s@%s',
            $user->id,
            Str::lower(Str::random(24)),
            self::DOMINIO_ANONIMO,
        );
    }
}
