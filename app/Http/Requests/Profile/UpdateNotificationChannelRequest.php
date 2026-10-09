<?php

namespace App\Http\Requests\Profile;

use App\Models\User;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One checkbox of the Perfil matrix (`profile.notifications.update`):
 * `{event, channel, enabled}`. The event must be in config/notifications.php;
 * required channels cannot be turned off and Telegram cannot be turned on
 * without a linked chat.
 */
class UpdateNotificationChannelRequest extends FormRequest
{
    /** Destination phrase per channel for the required-channel message. */
    public const CHANNEL_PHRASES = ['mail' => 'al correo', 'telegram' => 'a Telegram', 'database' => 'a la campana'];

    public const TELEGRAM_NOT_LINKED_MESSAGE = 'Vincula Telegram para recibir avisos por ese canal.';

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

        return [
            'event' => ['required', 'string', Rule::in(array_keys($preferences->catalog()))],
            'channel' => ['required', 'string', Rule::in($preferences->supportedChannels())],
            'enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $event = (string) $this->input('event');
            $channel = (string) $this->input('channel');

            if ($this->boolean('enabled')) {
                /** @var User $user */
                $user = $this->user();
                $link = $user->telegramLink()->first();

                if ($channel === 'telegram' && ! ($link?->isLinked() ?? false)) {
                    $validator->errors()->add('channel', self::TELEGRAM_NOT_LINKED_MESSAGE);
                }

                return;
            }

            if (in_array($channel, app(NotificationPreferences::class)->requiredChannels($event), true)) {
                $phrase = self::CHANNEL_PHRASES[$channel] ?? 'por ese canal';
                $validator->errors()->add('channel', "Este aviso siempre llega {$phrase}; no se puede desactivar.");
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'event.required' => 'Indica el aviso.',
            'event.in' => 'Tipo de aviso desconocido.',
            'channel.required' => 'Indica el canal.',
            'channel.in' => 'Canal no válido.',
            'enabled.required' => 'Indica si el canal queda activado.',
            'enabled.boolean' => 'El valor debe ser verdadero o falso.',
        ];
    }
}
