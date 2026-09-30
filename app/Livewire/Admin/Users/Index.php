<?php

namespace App\Livewire\Admin\Users;

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
    /** @var list<string> */
    public const INTERNAL_ROLES = ['admin', 'vendedor', 'bodega', 'contador'];

    public string $search = '';

    public string $roleFilter = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $role = '';

    public ?string $notice = null;

    public string $noticeType = 'success';

    public function create(): void
    {
        $this->notice = null;
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(User $user): void
    {
        $this->notice = null;
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

    public function save(): void
    {
        $this->notice = null;

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
            $user = User::query()->findOrFail($this->editingId);

            $user->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
            ]);

            $user->syncRoles([$validated['role']]);

            $this->notice = 'Usuario actualizado correctamente.';
            $this->noticeType = 'success';
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

        $this->notice = 'Usuario creado correctamente. Se envió un correo para que defina su contraseña.';
        $this->noticeType = 'success';
        $this->resetForm();
    }

    public function toggleActive(User $user): void
    {
        $this->notice = null;

        if ($user->id === auth()->id() && $user->is_active) {
            $this->notice = 'No puedes desactivar tu propia cuenta de administrador.';
            $this->noticeType = 'error';

            return;
        }

        $user->update(['is_active' => ! $user->is_active]);

        $this->notice = $user->is_active
            ? "Cuenta de «{$user->name}» activada correctamente."
            : "Cuenta de «{$user->name}» desactivada correctamente.";
        $this->noticeType = 'success';
    }

    public function resendInvitation(User $user): void
    {
        $this->notice = null;

        Password::deleteToken($user);

        $this->sendInvitation($user);

        $this->notice = "Se reenvió el correo de definición de contraseña a «{$user->email}».";
        $this->noticeType = 'success';
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
