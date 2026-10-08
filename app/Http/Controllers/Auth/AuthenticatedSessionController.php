<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();

        if ($user->hasRole('admin')) {
            return redirect()->route('admin.dashboard');
        }

        if ($user->hasRole(['vendedor', 'bodega', 'contador'])) {
            return redirect()->route('staff.placeholder');
        }

        if ($user->hasRole('cliente')) {
            // El modal de acceso manda `return_to` con la página en la que estaba
            // el invitado, para dejarlo ahí. Solo se honra si es una ruta local
            // segura; si no, se cae al destino de siempre.
            $returnTo = $this->safeReturnTo($request->input('return_to'));

            if ($returnTo !== null) {
                return redirect()->to($returnTo);
            }

            return redirect()->intended(route('account.index', absolute: false));
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Devuelve la ruta local de `return_to` solo si es segura para redirigir.
     *
     * Segura es una ruta con una sola «/» inicial (ni `//` ni `/\`), sin saltos
     * de línea ni caracteres de control, y que no apunte a las pantallas de
     * acceso (para no volver al formulario tras entrar). Cualquier URL absoluta
     * se descarta.
     */
    private function safeReturnTo(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || ! str_starts_with($url, '/')) {
            return null;
        }

        if (str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
            return null;
        }

        if (preg_match('/[\r\n\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';

        foreach (['/login', '/register', '/forgot-password', '/reset-password', '/verify-email', '/confirm-password'] as $access) {
            if ($path === $access || str_starts_with($path, $access.'/')) {
                return null;
            }
        }

        return $url;
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
