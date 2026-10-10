<?php

namespace App\Http\Requests\Ai;

use App\Domain\Ai\TriagePeople;
use App\Models\AiProduct;
use App\Models\AiProductIncident;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `IncidentStorePayload` of `ai.products.incidents.store` (C-IncidentForm).
 * Times are Bogotá wall time `YYYY-MM-DDTHH:mm`. Error keys: `title`,
 * `started_at`, `impact`, `description`, `ended_at`, `resolution`,
 * `notify_ids`. A failed store goes to `ai.products.incidents.create`, which
 * renders the product page with the drawer open, the errors and the old
 * input.
 */
class ProductIncidentRequest extends FormRequest
{
    public const TIMEZONE = 'America/Bogota';

    public const FORMAT = 'Y-m-d\TH:i';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $resolved = $this->input('status') === 'resolved';

        return [
            'title' => ['required', 'string', 'max:200'],
            'started_at' => ['required', 'string', $this->wallTime(), $this->notFuture()],
            'impact' => ['required', 'string', Rule::in(AiProductIncident::IMPACTS)],
            'description' => ['required', 'string', 'max:5000'],
            'status' => ['required', 'string', Rule::in(['open', 'resolved'])],
            'ended_at' => $resolved
                ? ['required', 'string', $this->wallTime(), $this->notFuture(), $this->afterStart()]
                : ['nullable'],
            'resolution' => $resolved ? ['required', 'string', 'max:5000'] : ['nullable', 'string', 'max:5000'],
            'notify_ids' => ['nullable', 'array', $this->teamOnly()],
            'notify_ids.*' => ['integer', 'distinct'],
            'notify_owner' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Escribe un título corto para el incidente.',
            'title.max' => 'Usa máximo 200 caracteres.',
            'started_at.required' => 'Escribe cuándo empezó.',
            'impact.required' => 'Elige el impacto.',
            'impact.in' => 'Elige el impacto.',
            'description.required' => 'Cuenta qué pasa.',
            'description.max' => 'Usa máximo 5.000 caracteres.',
            'status.required' => 'Elige si sigue abierto o ya se resolvió.',
            'status.in' => 'Elige si sigue abierto o ya se resolvió.',
            'ended_at.required' => 'Escribe cuándo terminó el incidente.',
            'resolution.required' => 'Cuenta qué lo causó y cómo se solucionó.',
            'resolution.max' => 'Usa máximo 5.000 caracteres.',
            'notify_ids.array' => 'Elige personas de la lista.',
            'notify_ids.*.integer' => 'Elige personas de la lista.',
            'notify_ids.*.distinct' => 'Elige personas de la lista.',
            'notify_owner.required' => 'Indica si se avisa al dueño.',
            'notify_owner.boolean' => 'Indica si se avisa al dueño.',
        ];
    }

    /**
     * The drawer reopens on the product page (deep link route).
     */
    protected function getRedirectUrl(): string
    {
        $product = $this->route('product');

        return $product instanceof AiProduct
            ? route('ai.products.incidents.create', $product)
            : parent::getRedirectUrl();
    }

    /**
     * `notify_ids.*` errors go under `notify_ids` (the key the drawer reads).
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();

        foreach ($errors->keys() as $key) {
            if (str_starts_with($key, 'notify_ids.')) {
                if (! $errors->has('notify_ids')) {
                    $errors->add('notify_ids', $errors->first($key));
                }
                $errors->forget($key);
            }
        }

        parent::failedValidation($validator);
    }

    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!'.self::FORMAT, $value, self::TIMEZONE);
        } catch (InvalidFormatException) {
            return null;
        }

        // Overflowing dates (month 13) roll over: refuse them.
        return $date instanceof CarbonImmutable && $date->format(self::FORMAT) === $value ? $date : null;
    }

    public function startedAt(): CarbonImmutable
    {
        /** @var CarbonImmutable $date */
        $date = self::parse($this->validated('started_at'));

        return $date;
    }

    public function endedAt(): ?CarbonImmutable
    {
        return $this->validated('status') === 'resolved' ? self::parse($this->validated('ended_at')) : null;
    }

    /**
     * @return list<int>
     */
    public function notifyIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->validated('notify_ids'))));
    }

    private function wallTime(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (self::parse($value) === null) {
                $fail($attribute === 'ended_at' ? 'Escribe cuándo terminó el incidente.' : 'Escribe cuándo empezó.');
            }
        };
    }

    private function notFuture(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $date = self::parse($value);

            // One minute of slack for the clock of the device.
            if ($date !== null && $date->greaterThan(now()->addMinute())) {
                $fail($attribute === 'ended_at' ? 'La hora final no puede ser futura.' : 'La hora de inicio no puede ser futura.');
            }
        };
    }

    private function afterStart(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $start = self::parse($this->input('started_at'));
            $end = self::parse($value);

            if ($start !== null && $end !== null && $end->lessThan($start)) {
                $fail('El final no puede ser antes del inicio.');
            }
        };
    }

    private function teamOnly(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $team = app(TriagePeople::class)->team()->modelKeys();

            foreach ((array) $value as $id) {
                if (! is_numeric($id) || ! in_array((int) $id, $team, true)) {
                    $fail('Elige personas de la lista.');

                    return;
                }
            }
        };
    }
}
