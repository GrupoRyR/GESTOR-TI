<?php

namespace App\Http\Requests\Ai;

use App\Actions\Ai\ReleaseNotes;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `ReleaseNotesPayload` of `ai.releases.notes.update` and
 * `ai.releases.notify`: the user notes (Markdown, kept as typed) and the
 * AI suggestion the text started from (null when typed by hand). Errors
 * keyed `user_notes`; "notes required to notify" lives in
 * App\Actions\Ai\ReleaseNotes.
 */
class ReleaseNotesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_notes' => ['nullable', 'string', 'max:'.ReleaseNotes::MAX_LENGTH],
            'suggestion_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_notes.max' => 'Las notas pueden tener hasta '.number_format(ReleaseNotes::MAX_LENGTH, 0, ',', '.').' caracteres.',
            'user_notes.string' => 'Escribe las notas como texto.',
        ];
    }

    public function notes(): ?string
    {
        $notes = $this->validated('user_notes');

        return is_string($notes) ? $notes : null;
    }

    public function suggestionId(): ?int
    {
        $id = $this->validated('suggestion_id');

        return $id === null ? null : (int) $id;
    }
}
