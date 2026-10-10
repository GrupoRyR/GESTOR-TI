<?php

namespace App\Domain\GitHub\Events;

use App\Models\GitHubWebhookEvent;
use RuntimeException;

/**
 * An event that cannot be processed until someone fixes the data (link the
 * repository, give the project a product…). Retrying alone would fail the
 * same way, so the queue does not retry it: the event stays `failed` with
 * `error_code` and a Spanish `error` until "Reprocesar".
 */
final class EventFailure extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function repoNotLinked(): self
    {
        return new self(GitHubWebhookEvent::ERROR_REPO_NOT_LINKED, 'El repositorio no está vinculado a un producto ni a un proyecto.');
    }

    public static function noProduct(): self
    {
        return new self('no_product', 'El proyecto vinculado no tiene un producto donde crear la versión.');
    }
}
