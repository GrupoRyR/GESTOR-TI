<?php

namespace App\Http\Requests\Auth;

use App\Actions\Auth\AttemptEmergencyLogin;
use Illuminate\Foundation\Http\FormRequest;

class EmergencyLoginRequest extends FormRequest
{
    /**
     * 404 (before validation) while the emergency login is not configured.
     */
    public function authorize(): bool
    {
        abort_unless(AttemptEmergencyLogin::enabled(), 404);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'username' => 'usuario',
            'password' => 'contraseña',
        ];
    }
}
