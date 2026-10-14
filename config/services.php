<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
        'tenant' => env('MICROSOFT_TENANT', 'organizations'),
    ],

    'emergency' => [
        'admin_email' => env('EMERGENCY_ADMIN_EMAIL'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        // Display name of the bot ("Ingenierito"); the @username stays in
        // TELEGRAM_BOT_USERNAME (null until the bot exists).
        'bot_name' => env('TELEGRAM_BOT_NAME', 'Ingenierito'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        // Also require the `X-Telegram-Bot-Api-Secret-Token` header (sent by
        // Telegram when `gestor:telegram-webhook` registered it). A header
        // that is present is always checked.
        'require_secret_header' => (bool) env('TELEGRAM_REQUIRE_SECRET_HEADER', false),
    ],

    // AI providers (Administración › Integraciones › Proveedor de IA, catalog
    // in config/gestor.php `ai.providers`). A provider is "on" only when its
    // key (Ollama: base URL) is set here; values never reach the frontend.
    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
    ],

    'mistral' => [
        'api_key' => env('MISTRAL_API_KEY'),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
    ],

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL'),
    ],

    'github' => [
        // Public install page of the GitHub App (Integraciones › GitHub).
        'install_url' => env('GITHUB_APP_INSTALL_URL'),
        'app_name' => env('GITHUB_APP_NAME', 'Gestor TI RYR'),
        'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),
        'app_id' => env('GITHUB_APP_ID'),
        'private_key_path' => env('GITHUB_PRIVATE_KEY_PATH'),
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
        'token' => env('GITHUB_TOKEN'),
        // Kind of the account that owns the repositories (`github.organization`
        // setting): `user` (GrupoRyR is a personal account) or `organization`.
        // It picks the installation lookup, the repository creation endpoint
        // and the "Administrar instalación" link.
        'account_type' => env('GITHUB_ACCOUNT_TYPE', 'user') === 'organization' ? 'organization' : 'user',
    ],

    'vault' => [
        // Own key of the vault (never APP_KEY): `base64:` + 32 random bytes.
        'key' => env('VAULT_KEY'),
        // aes-256-gcm (default) or aes-256-cbc.
        'cipher' => env('VAULT_CIPHER', 'aes-256-gcm'),
    ],

];
