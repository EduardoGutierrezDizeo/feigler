{{-- Mensaje de estado de sesión (por ejemplo, enlace de recuperación enviado). --}}
@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'rounded-xl border border-arena bg-hueso/40 p-3 text-sm text-verde']) }}>
        {{ $status }}
    </div>
@endif
