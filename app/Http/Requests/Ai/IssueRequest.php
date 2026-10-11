<?php

namespace App\Http\Requests\Ai;

use App\Domain\GitHub\IssueText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `IssuePayload` of "Crear issue en GitHub" (`ai.tasks.issue.store`).
 * Errors keyed `title`, `body`, `labels`, `assignee_id`; the content rules
 * (no money, credentials or internal notes) live in App\Domain\GitHub\IssueText.
 */
class IssueRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.IssueText::TITLE_MAX],
            'body' => ['required', 'string', 'max:60000'],
            'labels' => ['array', 'max:10'],
            'labels.*' => ['string', 'max:50'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Escribe el título del issue.',
            'title.max' => 'El título puede tener hasta '.IssueText::TITLE_MAX.' caracteres.',
            'body.required' => 'Escribe la descripción del issue.',
            'labels.max' => 'Usa hasta 10 etiquetas.',
            'assignee_id.exists' => 'Esa persona no existe.',
        ];
    }

    /**
     * @return array{title: string, body: string, labels: list<string>, assignee_id: ?int}
     */
    public function payload(): array
    {
        $assignee = $this->validated('assignee_id');

        return [
            'title' => (string) $this->validated('title'),
            'body' => (string) $this->validated('body'),
            'labels' => array_values(array_map('strval', (array) $this->validated('labels', []))),
            'assignee_id' => $assignee === null ? null : (int) $assignee,
        ];
    }
}
