<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `RepoLinkPayload` of "Vincular repositorio" (`ai.products.repository.store`,
 * `ai.projects.repository.store`). Errors keyed `repo` and `branch`; the
 * repository and branch rules live in App\Actions\Ai\LinkRepository.
 */
class RepoLinkRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'repo' => ['required', 'string', 'max:255'],
            'branch' => ['required', 'string', 'max:255'],
            'read_docs' => ['boolean'],
            'receive_events' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'repo.required' => 'Elige el repositorio.',
            'branch.required' => 'Elige la rama principal.',
        ];
    }

    /**
     * @return array{repo: string, branch: string, read_docs: bool, receive_events: bool}
     */
    public function payload(): array
    {
        return [
            'repo' => (string) $this->validated('repo'),
            'branch' => (string) $this->validated('branch'),
            'read_docs' => $this->boolean('read_docs', true),
            'receive_events' => $this->boolean('receive_events', true),
        ];
    }
}
