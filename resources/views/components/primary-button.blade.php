{{-- `submit` es el default a propósito: casi todos sus usos están dentro de
     formularios POST (login, registro, perfil, verificación de email). Los
     call sites que sólo disparan una acción de Livewire pasan
     `type="button"` explícito. --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center gap-2 rounded-md bg-brand-green px-5 py-2.5 text-sm font-semibold text-cream transition-[background-color,box-shadow] duration-150 ease-in-out hover:bg-wood hover:shadow-lift active:opacity-90 focus:outline-2 focus:outline-offset-2 focus:outline-brand-green disabled:opacity-50']) }}>
    {{ $slot }}
</button>