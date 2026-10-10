<?php

namespace App\Console\Commands;

use App\Models\Cart;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Borra los carritos que llevan demasiado tiempo sin usarse.
 *
 * Un carrito olvidado es puro espacio ocupado: no reserva stock ni guarda
 * precio, así que no le debe nada a nadie y puede desaparecer sin aviso. Se
 * limpian por separado los de visitante (30 días por defecto) y los de cuenta
 * (90 días), porque el carrito de una cuenta que vuelve a entrar vale más que
 * el de una cookie que nadie vuelve a abrir. La actividad de un carrito es su
 * `last_activity_at`, que refresca cada operación; el que nunca la registró
 * (recién creado para una cuenta) se juzga por su `updated_at` y, si tampoco,
 * por su `created_at`. Al final se imprime cuántos se borraron de cada tipo.
 */
class CartsPrune extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'carts:prune
                            {--guest-days=30 : borra los carritos de visitante sin uso desde hace tantos días}
                            {--user-days=90 : borra los carritos de cuenta sin uso desde hace tantos días}';

    /**
     * The console command description.
     */
    protected $description = 'Borra los carritos que llevan demasiado tiempo sin actividad';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $guestDays = $this->days('guest-days');
        $userDays = $this->days('user-days');

        if ($guestDays === null) {
            $this->components->error('--guest-days debe ser un entero mayor o igual que cero.');

            return self::INVALID;
        }

        if ($userDays === null) {
            $this->components->error('--user-days debe ser un entero mayor o igual que cero.');

            return self::INVALID;
        }

        $guests = $this->deleteOlderThan(Cart::query()->whereNull('user_id'), $guestDays);
        $users = $this->deleteOlderThan(Cart::query()->whereNotNull('user_id'), $userDays);

        $this->components->info(sprintf('Carritos de visitante borrados: %d.', $guests));
        $this->components->info(sprintf('Carritos de cuenta borrados: %d.', $users));

        return self::SUCCESS;
    }

    /**
     * La edad en días de una opción, o `null` si no es un entero válido.
     */
    private function days(string $option): ?int
    {
        $value = filter_var($this->option($option), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $value === false ? null : (int) $value;
    }

    /**
     * Borrar los carritos de la consulta sin actividad desde hace `$days` días.
     *
     * La actividad es `last_activity_at`; cuando la fila no la tiene, se cae al
     * `updated_at` y, si tampoco lo tiene, al `created_at` (el `COALESCE` del
     * SQL). Devuelve cuántos se borraron.
     */
    private function deleteOlderThan(Builder $query, int $days): int
    {
        $cutoff = Carbon::now()->subDays($days);

        return $query
            ->where(function (Builder $q) use ($cutoff): void {
                $q->whereNotNull('last_activity_at')
                    ->where('last_activity_at', '<', $cutoff)
                    ->orWhere(function (Builder $q2) use ($cutoff): void {
                        $q2->whereNull('last_activity_at')
                            ->whereRaw('COALESCE(updated_at, created_at) < ?', [$cutoff]);
                    });
            })
            ->delete();
    }
}
