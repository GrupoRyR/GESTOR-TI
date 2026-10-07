<?php

namespace App\Http\Requests\Profile;

use App\Services\Notifications\NotificationPreferences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body: `preferences` = {event_type: channels[]}. Event types must exist in
 * config/notifications.php; an empty list turns the event off.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $preferences = app(NotificationPreferences::class);
        $events = array_keys($preferences->catalog());

        return [
            'preferences' => ['required', 'array', 'min:1', function (string $attribute, mixed $value, \Closure $fail) use ($events): void {
                $unknown = array_diff(array_keys((array) $value), $events);

                if ($unknown !== []) {
                    $fail('Tipo de notificación desconocido: '.implode(', ', $unknown).'.');
                }
            }],
            'preferences.*' => ['present', 'array'],
            'preferences.*.*' => ['string', 'distinct', Rule::in($preferences->supportedChannels())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'preferences.required' => 'Envía al menos una preferencia.',
            'preferences.*.*.in' => 'Canal no válido.',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function preferences(): array
    {
        return array_map(
            fn ($channels) => array_values((array) $channels),
            (array) $this->validated('preferences'),
        );
    }
}
