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
        'app_id' => env('GITHUB_APP_ID'),
        'private_key_path' => env('GITHUB_PRIVATE_KEY_PATH'),
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
        'token' => env('GITHUB_TOKEN'),
    ],

    'vault' => [
        'key' => env('VAULT_KEY'),
    ],

];
