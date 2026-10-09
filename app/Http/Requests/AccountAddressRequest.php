<?php

namespace App\Http\Requests;

use App\Support\ColombiaLocations;
use App\Support\ColombianPhone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base de los formularios de alta y edición de una dirección de Mi cuenta.
 *
 * Las reglas son las mismas para los dos verbos; solo cambia cuál los atiende.
 * Los errores siempre van a la bolsa «address» (propiedad errorBag del request)
 * para que la pestaña Direcciones reabra el modal en el modo correcto.
 */
abstract class AccountAddressRequest extends FormRequest
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
            'recipient_name' => ['required', 'string', 'max:100'],
            'phone' => ColombianPhone::rules(),
            'department_code' => ['required', 'string', 'max:2', function (string $attribute, mixed $value, \Closure $fail) {
                if (ColombiaLocations::department((string) $value) === null) {
                    $fail('El departamento elegido no es válido.');
                }
            }],
            'city_code' => ['required', 'string', 'max:5', function (string $attribute, mixed $value, \Closure $fail) {
                $department = (string) $this->input('department_code', '');

                if ($department === '' || ! ColombiaLocations::cityBelongsToDepartment((string) $value, $department)) {
                    $fail('La ciudad elegida no pertenece al departamento.');
                }
            }],
            'line1' => ['required', 'string', 'max:150'],
            'line2' => ['nullable', 'string', 'max:100'],
            'instructions' => ['nullable', 'string', 'max:200'],
            'label' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * Todos los errores van a la bolsa con nombre «address».
     */
    protected function prepareForValidation(): void
    {
        $this->errorBag = 'address';

        $this->merge([
            'recipient_name' => $this->trimmed('recipient_name'),
            'phone' => ColombianPhone::normalize($this->input('phone')),
            'department_code' => $this->trimmed('department_code'),
            'city_code' => $this->trimmed('city_code'),
            'label' => $this->trimmed('label'),
            'line1' => $this->trimmed('line1'),
            'line2' => $this->trimmed('line2'),
            'instructions' => $this->trimmed('instructions'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipient_name.required' => 'El nombre de quien recibe es obligatorio.',
            'recipient_name.max' => 'El nombre de quien recibe no puede tener más de 100 caracteres.',
            'phone.required' => 'El teléfono es obligatorio.',
            'phone.regex' => 'Escribe un celular colombiano de 10 dígitos que empiece por 3.',
            'department_code.required' => 'El departamento es obligatorio.',
            'city_code.required' => 'La ciudad es obligatoria.',
            'city_code.max' => 'La ciudad elegida no es válida.',
            'line1.required' => 'La dirección es obligatoria.',
            'line1.max' => 'La dirección no puede tener más de 150 caracteres.',
            'line2.max' => 'El complemento no puede tener más de 100 caracteres.',
            'instructions.max' => 'Las indicaciones no pueden tener más de 200 caracteres.',
            'label.max' => 'El nombre de la dirección no puede tener más de 30 caracteres.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'recipient_name' => 'nombre de quien recibe',
            'phone' => 'teléfono',
            'department_code' => 'departamento',
            'city_code' => 'ciudad',
            'line1' => 'dirección',
            'line2' => 'complemento',
            'instructions' => 'indicaciones',
            'label' => 'nombre de la dirección',
        ];
    }

    private function trimmed(string $field): mixed
    {
        $value = $this->input($field);

        return is_string($value) ? trim($value) : $value;
    }
}
