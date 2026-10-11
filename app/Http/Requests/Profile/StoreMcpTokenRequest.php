<?php

namespace App\Http\Requests\Profile;

use App\Http\Middleware\EnsureMcpAccess;
use App\Models\McpToken;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `profile.mcp.tokens.store`: `{ name (1..60), scope, expires_in_days }`
 * (types/pages/profile-mcp.ts). The name is unique among the user's
 * active tokens (case-insensitive); a revoked or expired name is free.
 */
class StoreMcpTokenRequest extends FormRequest
{
    public const DUPLICATE = 'Ya tienes un token activo con ese nombre.';

    public function authorize(): bool
    {
        return $this->user()?->can(EnsureMcpAccess::PERMISSION) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => McpToken::normalizeName($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'min:1', 'max:60', function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                $taken = McpToken::query()->active()->where('user_id', $user->id)->pluck('name')
                    ->contains(fn (string $name) => mb_strtolower($name) === mb_strtolower((string) $value));

                if ($taken) {
                    $fail(self::DUPLICATE);
                }
            }],
            'scope' => ['required', Rule::in(McpToken::SCOPES)],
            'expires_in_days' => ['required', 'integer', Rule::in(McpToken::EXPIRY_DAYS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Ponle un nombre al token, por ejemplo “Claude · portátil”.',
            'name.max' => 'El nombre admite hasta 60 caracteres.',
            'scope.*' => 'Elige “Solo lectura” o “Lectura y escritura”.',
            'expires_in_days.*' => 'Elige 30, 90 o 180 días.',
        ];
    }
}
