<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Password::defaults()],
            'phone' => ['required', 'string', 'regex:/^3\d{9}$/'],
            'terms' => ['accepted'],
        ];
    }

    /**
     * Normalize the input before it is validated: trim the names, lowercase the
     * email and reduce the phone to the ten digits of a Colombian mobile number.
     */
    protected function prepareForValidation(): void
    {
        $this->errorBag = $this->accessErrorBag();

        $phone = $this->input('phone');

        if (is_string($phone)) {
            $phone = preg_replace('/\D+/', '', $phone);

            if (strlen($phone) === 12 && str_starts_with($phone, '57')) {
                $phone = substr($phone, 2);
            }
        }

        $this->merge([
            'name' => $this->trimmed('name'),
            'last_name' => $this->trimmed('last_name'),
            'email' => $this->lowercasedEmail(),
            'phone' => $phone,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede tener más de 100 caracteres.',
            'last_name.required' => 'El apellido es obligatorio.',
            'last_name.max' => 'El apellido no puede tener más de 100 caracteres.',
            'email.required' => 'El correo electrónico es obligatorio.',
            'email.email' => 'Escribe un correo electrónico válido.',
            'email.max' => 'El correo electrónico no puede tener más de 255 caracteres.',
            'email.unique' => 'Ya existe una cuenta con este correo electrónico.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'phone.required' => 'El teléfono es obligatorio.',
            'phone.regex' => 'Escribe un celular colombiano de 10 dígitos que empiece por 3.',
            'terms.accepted' => 'Debes autorizar el tratamiento de tus datos personales.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'last_name' => 'apellido',
            'email' => 'correo electrónico',
            'password' => 'contraseña',
            'phone' => 'teléfono',
            'terms' => 'autorización de tratamiento de datos',
        ];
    }

    private function trimmed(string $field): mixed
    {
        $value = $this->input($field);

        return is_string($value) ? trim($value) : $value;
    }

    /**
     * La bolsa del modal cuando el registro viene del modal de acceso; si no, la
     * bolsa por defecto, para no cambiar el comportamiento de la página /register.
     */
    private function accessErrorBag(): string
    {
        return in_array($this->input('access_modal'), ['login', 'register', 'forgot'], true)
            ? $this->input('access_modal')
            : 'default';
    }

    private function lowercasedEmail(): mixed
    {
        $value = $this->input('email');

        return is_string($value) ? Str::lower(trim($value)) : $value;
    }
}
