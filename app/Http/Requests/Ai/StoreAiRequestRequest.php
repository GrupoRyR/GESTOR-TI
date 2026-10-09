<?php

namespace App\Http\Requests\Ai;

use App\Domain\Ai\ProductResolver;
use App\Enums\Ai\RequestType;
use App\Enums\Ai\Urgency;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * `ai.requests.store` (multipart `RequestStorePayload`): the wizard of
 * `Ai/Requests/Create`. Rules and messages mirror resources/js/lib/wizard.ts
 * so error keys land on the right step (`product_slug`, `attachments.0`…).
 * Domain rules (area of the requester's company, files) stay in
 * App\Actions\Ai\CreateRequest; `ai.requests.create` is checked by the route.
 * `requester_id` (not in the design contract yet) files it on behalf of a
 * colleague: CreateRequest requires `ai.manage` for that.
 */
class StoreAiRequestRequest extends FormRequest
{
    public const TEXT_MAX = 2000;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $today = now('America/Bogota')->toDateString();

        return [
            'type' => ['required', Rule::enum(RequestType::class)],
            'product_slug' => [
                'nullable', 'string', 'max:100',
                Rule::requiredIf(fn () => in_array($this->input('type'), [RequestType::Improvement->value, RequestType::Bug->value], true)),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (filled($value) && app(ProductResolver::class)->forUser((string) $value, $this->requester() ?? $this->user()) === null) {
                        $fail('Escoge una app de tu empresa.');
                    }
                },
            ],
            'problem' => ['required', 'string', 'min:20', 'max:'.self::TEXT_MAX],
            'hours_per_week' => ['nullable', 'required_unless:type,idea', 'numeric', 'between:0.5,60'],
            'people' => ['nullable', 'required_unless:type,idea', 'integer', 'between:1,500'],
            'expected_result' => ['required', 'string', 'max:'.self::TEXT_MAX],
            'success_criteria' => ['nullable', 'string', 'max:'.self::TEXT_MAX],
            'systems' => ['nullable', 'array', 'max:20'],
            'systems.*' => ['string', 'max:100'],
            'systems_notes' => ['nullable', 'string', 'max:'.self::TEXT_MAX],
            'urgency' => ['required', Rule::enum(Urgency::class)],
            'deadline' => ['nullable', 'date_format:Y-m-d', 'after:'.$today],
            'deadline_reason' => ['nullable', 'required_with:deadline', 'string', 'max:'.self::TEXT_MAX],
            'area_id' => ['required', 'integer'],
            'attachments' => ['nullable', 'array'],
            // IT files it on behalf of a colleague (`ai.manage`, checked by CreateRequest).
            'requester_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('active', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Escoge qué necesitas.',
            'type.enum' => 'Escoge qué necesitas.',
            'product_slug.required' => 'Escoge la app.',
            'problem.required' => 'Cuéntalo en una o dos frases (mínimo 20 caracteres).',
            'problem.min' => 'Cuéntalo en una o dos frases (mínimo 20 caracteres).',
            'problem.max' => 'Es muy largo: resúmelo en menos de 2.000 caracteres.',
            'hours_per_week.*' => 'Escribe entre media hora y 60 horas por semana.',
            'people.*' => 'Escribe cuántas personas, de 1 a 500.',
            'expected_result.required' => 'Cuéntanos qué esperas que pase.',
            'expected_result.max' => 'Es muy largo: resúmelo en menos de 2.000 caracteres.',
            'urgency.*' => 'Escoge para cuándo lo necesitas.',
            'deadline.after' => 'La fecha límite tiene que ser después de hoy.',
            'deadline.date_format' => 'Escribe una fecha válida.',
            'deadline_reason.required_with' => 'Cuéntanos por qué esa fecha.',
            'area_id.*' => 'Escoge tu área.',
            'requester_id.*' => 'Escoge a la persona que lo pide.',
        ];
    }

    /**
     * Data for App\Actions\Ai\CreateRequest. Each non-empty line of
     * "Cómo sabría que quedó bien" becomes an acceptance criterion.
     *
     * @return array<string, mixed>
     */
    public function toActionData(): array
    {
        $data = $this->validated();
        $criteria = preg_split('/\R/', (string) ($data['success_criteria'] ?? '')) ?: [];

        return [
            'type' => $data['type'],
            'product' => $data['product_slug'] ?? null,
            'problem' => $data['problem'],
            'hours_per_week' => $data['hours_per_week'] ?? null,
            'people' => $data['people'] ?? null,
            'expected_result' => $data['expected_result'],
            'success_criteria' => $data['success_criteria'] ?? null,
            'systems' => array_values(array_unique(array_filter(array_map('trim', (array) ($data['systems'] ?? [])), 'filled'))),
            'systems_notes' => $data['systems_notes'] ?? null,
            'urgency' => $data['urgency'],
            'deadline' => $data['deadline'] ?? null,
            'deadline_reason' => $data['deadline_reason'] ?? null,
            'area_id' => (int) $data['area_id'],
            'criteria' => array_values(array_filter(array_map('trim', $criteria), 'filled')),
        ];
    }

    /**
     * The colleague the request is filed for (`requester_id`), or null when
     * the signed-in user asks for themselves.
     */
    public function requester(): ?User
    {
        $id = $this->input('requester_id');

        if (blank($id) || (int) $id === $this->user()?->id) {
            return null;
        }

        return User::query()->find((int) $id);
    }

    /**
     * @return list<UploadedFile>
     */
    public function attachments(): array
    {
        return array_values(array_filter((array) $this->file('attachments', [])));
    }
}
