<?php

namespace App\Livewire\Concerns;

/**
 * Re-asserts the `admin` role on every Livewire request of a panel component.
 *
 * The `/admin` route group already guards the first page load with `role:admin`,
 * but Livewire serves the later interactions through `/livewire/update`, where
 * that middleware — and its `:admin` argument — is not applied. Hooking the
 * trait's `bootRequiresAdmin()` hook (which runs on the initial mount and on
 * every subsequent request) keeps the panel closed even if the role is lost
 * mid-session or a non-admin gets hold of a snapshot and calls the methods by
 * hand. The Livewire convention of suffixing the hook with the trait name leaves
 * a plain `boot()` of the component itself free to run as well.
 */
trait RequiresAdmin
{
    public function bootRequiresAdmin(): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);
    }
}
