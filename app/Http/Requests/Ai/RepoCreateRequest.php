<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `RepoCreatePayload` of "Crear repositorio con este CLAUDE.md"
 * (`ai.projects.repository.create`). GitHub repository names: letters,
 * digits, `.`, `-` and `_`, not `.git` at the end. Errors keyed `name`
 * and `claude_md`.
 */
class RepoCreateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/', 'not_regex:/\.git$/i', 'not_in:.,..'],
            'claude_md' => ['required', 'string', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre del repositorio.',
            'name.max' => 'El nombre puede tener hasta 100 caracteres.',
            'name.regex' => 'Usa solo letras sin tildes, números, puntos, guiones y guiones bajos.',
            'name.not_regex' => 'El nombre no puede terminar en .git.',
            'name.not_in' => 'Ese nombre no es válido.',
            'claude_md.required' => 'El CLAUDE.md no puede quedar vacío.',
            'claude_md.max' => 'El CLAUDE.md es demasiado largo.',
        ];
    }
}
