<?php

namespace App\Livewire\Admin\Users;

use App\Livewire\Concerns\Notifies;
use App\Livewire\Concerns\RequiresAdmin;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::admin')]
#[Title('Usuarios')]
class Index extends Component
{
    use Notifies;
    use RequiresAdmin;

    /** @var list<string> */
    public const INTERNAL_ROLES = ['admin', 'vendedor', 'bodega', 'contador'];

    public string $search = '';

    public string $roleFilter = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $role = '';

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $user = $this->internalUser($id);

        $this->resetForm();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $user->getRoleNames()->first() ?? '';
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetValidation();
    }

    public function mount(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->name = '';
        $this->email = '';
        $this->role = '';
        $this->resetValidation();
    }

    public function save(): void
    {
        if ($this->editingId !== null) {
            $this->editingId = (int) $this->editingId;
        }

        $validated = $this->validate([
            'name' => ['required', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'role' => ['required', Rule::in(self::INTERNAL_ROLES)],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede superar los 255 caracteres.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'Ingresa un correo electrónico válido.',
            'email.unique' => 'Ya existe un usuario con ese correo electrónico.',
            'role.required' => 'Selecciona un rol.',
            'role.in' => 'El rol seleccionado no es válido.',
        ]);

        if ($this->editingId !== null) {
            $user = $this->internalUser($this->editingId);

            $user->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
            ]);

            $user->syncRoles([$validated['role']]);

            $this->notifySuccess('Usuario actualizado correctamente.');
            $this->resetForm();

            return;
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Str::random(40),
            'is_active' => true,
        ]);

        $user->assignRole($validated['role']);

        $this->sendInvitation($user);

        $this->notifySuccess('Usuario creado correctamente. Se envió un correo para que defina su contraseña.');
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $user = $this->internalUser($id);

        if ($user->id === auth()->id() && $user->is_active) {
            $this->notifyError('No puedes desactivar tu propia cuenta de administrador.');

            return;
        }

        $user->update(['is_active' => ! $user->is_active]);

        $this->notifySuccess($user->is_active
            ? "Cuenta de «{$user->name}» activada correctamente."
            : "Cuenta de «{$user->name}» desactivada correctamente.");
    }

    public function resendInvitation(int $id): void
    {
        $user = $this->internalUser($id);

        Password::deleteToken($user);

        $this->sendInvitation($user);

        $this->notifySuccess("Se reenvió el correo de definición de contraseña a «{$user->email}».");
    }

    public function render()
    {
        $search = mb_strtolower(trim($this->search));

        $users = User::query()
            ->role(self::INTERNAL_ROLES)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(fn ($query) => $query
                    ->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]));
            })
            ->when($this->roleFilter !== '', fn ($query) => $query->role($this->roleFilter))
            ->orderBy('name')
            ->get();

        return view('livewire.admin.users.index', [
            'users' => $users,
            'internalRoles' => self::INTERNAL_ROLES,
        ]);
    }

    /**
     * Resolve the user the panel is allowed to act on. Anyone outside the internal
     * roles does not exist as far as this panel goes, so a customer id — or a user
     * with no role — answers 404 just like an unknown id would.
     */
    private function internalUser(int $id): User
    {
        return User::query()
            ->role(self::INTERNAL_ROLES)
            ->findOrFail($id);
    }

    private function sendInvitation(User $user): void
    {
        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_LINK_SENT) {
            return;
        }

        throw new \RuntimeException('No se pudo enviar el correo de definición de contraseña.');
    }

    private function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->name = '';
        $this->email = '';
        $this->role = '';
        $this->resetValidation();
    }
}
