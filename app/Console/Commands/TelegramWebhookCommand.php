<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

/**
 * Registers the bot webhook: `{APP_URL}/api/telegram/webhook/{secret}`, and
 * the same secret as `secret_token` (Telegram sends it back in the
 * `X-Telegram-Bot-Api-Secret-Token` header) when it only uses the allowed
 * characters (A-Z, a-z, 0-9, _ and -). Run once per deploy or secret change.
 */
class TelegramWebhookCommand extends Command
{
    protected $signature = 'gestor:telegram-webhook';

    protected $description = 'Registra el webhook del bot de Telegram en {APP_URL}/api/telegram/webhook/{secreto}';

    public function handle(TelegramClient $client): int
    {
        $secret = (string) config('services.telegram.webhook_secret');

        if ($secret === '') {
            $this->error('Falta TELEGRAM_WEBHOOK_SECRET en el .env.');

            return self::FAILURE;
        }

        if (! $client->isConfigured()) {
            $this->error('Falta TELEGRAM_BOT_TOKEN en el .env.');

            return self::FAILURE;
        }

        $base = rtrim((string) config('app.url'), '/').'/api/telegram/webhook/';
        $headerSecret = preg_match('/^[A-Za-z0-9_-]{1,256}$/', $secret) === 1 ? $secret : null;

        if (! $client->setWebhook($base.rawurlencode($secret), $headerSecret)) {
            $this->error('Telegram rechazó el webhook (revisa el log).');

            return self::FAILURE;
        }

        $this->info('Webhook de Telegram registrado: '.$base.'***');

        if ($headerSecret === null) {
            $this->warn('El secreto tiene caracteres no admitidos por Telegram en la cabecera: solo se valida el de la URL.');
        }

        return self::SUCCESS;
    }
}
