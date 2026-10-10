<?php

namespace App\Http\Requests\Ai;

use App\Actions\Ai\LogTime;
use App\Models\AiProject;
use App\Models\AiTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `TimeEntryPayload` of "Agregar horas" (`ai.time-entries.store|update`):
 * rules and messages mirror useTimeEntryForm (TimeEntryFields.tsx). The
 * task must be a task of the project in the route; dates up to today
 * (Bogotá). Authorization stays in the controller (AiProjectPolicy).
 */
class TimeEntryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        $projectId = $project instanceof AiProject ? $project->id : 0;

        return [
            'task_key' => ['nullable', 'string', Rule::exists('ai_tasks', 'key')->where('ai_project_id', $projectId)],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Bogota')->toDateString()],
            'hours' => ['required', 'numeric', 'gt:0', 'max:'.LogTime::MAX_HOURS],
            'note' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'task_key.exists' => 'Elige una tarea de este proyecto.',
            'date.required' => 'Elige la fecha.',
            'date.date_format' => 'Elige la fecha.',
            'date.before_or_equal' => 'No se cargan horas de días que no han pasado.',
            'hours.required' => 'Pon cuántas horas fueron.',
            'hours.numeric' => 'Pon cuántas horas fueron.',
            'hours.gt' => 'Pon cuántas horas fueron.',
            'hours.max' => 'Máximo '.LogTime::MAX_HOURS.' horas en un día.',
            'note.required' => 'Cuenta en una línea qué hiciste.',
            'note.max' => 'Cuéntalo en máximo 255 caracteres.',
        ];
    }

    public function task(AiProject $project): ?AiTask
    {
        $key = $this->validated('task_key');

        return is_string($key) && $key !== '' ? $project->tasks()->where('key', $key)->first() : null;
    }
}
