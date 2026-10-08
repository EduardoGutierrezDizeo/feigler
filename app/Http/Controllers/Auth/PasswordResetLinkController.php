<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $bag = $this->accessErrorBag($request);

        $request->validateWithBag($bag, [
            'email' => ['required', 'email'],
        ]);

        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status == Password::RESET_LINK_SENT) {
            $response = back()->with('status', __($status));

            // Solo cuando el envío viene del modal se marca la vista de
            // recuperación: así un status por la página no reabre el modal más tarde.
            if ($bag === 'forgot') {
                $response->with('access_modal', 'forgot');
            }

            return $response;
        }

        return back()->withInput($request->only('email'))
            ->withErrors(['email' => __($status)], $bag);
    }

    /**
     * La bolsa del modal cuando la solicitud viene del modal de acceso; si no, la
     * bolsa por defecto, para no cambiar el comportamiento de la página.
     */
    private function accessErrorBag(Request $request): string
    {
        return in_array($request->input('access_modal'), ['login', 'register', 'forgot'], true)
            ? $request->input('access_modal')
            : 'default';
    }
}
