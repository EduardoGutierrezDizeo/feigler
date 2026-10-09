<?php

namespace App\Http\Requests;

use App\Support\ColombianPhone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AccountProfileUpdateRequest extends FormRequest
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
            'phone' => ColombianPhone::rules(),
        ];
    }

    /**
     * Normalize the input before it is validated, the same way the registration
     * does: trim the names and reduce the phone to the ten digits of a
     * Colombian mobile number.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->trimmed('name'),
            'last_name' => $this->trimmed('last_name'),
            'phone' => ColombianPhone::normalize($this->input('phone')),
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
            'phone.required' => 'El teléfono es obligatorio.',
            'phone.regex' => 'Escribe un celular colombiano de 10 dígitos que empiece por 3.',
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
            'phone' => 'teléfono',
        ];
    }

    private function trimmed(string $field): mixed
    {
        $value = $this->input($field);

        return is_string($value) ? trim($value) : $value;
    }
}
