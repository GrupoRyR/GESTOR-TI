<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests must not depend on a compiled Vite manifest.
        $this->withoutVite();

        // A local .env key must never turn the AI provider on in tests:
        // tests that need it set a fake key and fake the HTTP endpoint.
        config(['services.deepseek.api_key' => null]);
    }
}
