<?php

namespace Tests\Unit;

use App\Support\Audit\ActivityPropertiesSanitizer;
use PHPUnit\Framework\TestCase;

class ActivityPropertiesSanitizerTest extends TestCase
{
    public function test_it_removes_sensitive_keys_at_every_level(): void
    {
        $properties = [
            'attributes' => [
                'name' => 'Ana',
                'password' => 'x',
                'Client_Secret' => 'x',
                'api_token' => 'x',
                'value' => 'x',
                'group_key' => 'x',
                'nested' => ['PASSWORD_HASH' => 'x', 'kept' => 1, 'deeper' => ['tokenized' => 'x', 'ok' => true]],
            ],
            'old' => ['name' => 'Ana María', 'secret' => 'x'],
            'password_changed' => true,
            'channel' => 'web',
        ];

        $this->assertSame([
            'attributes' => [
                'name' => 'Ana',
                'nested' => ['kept' => 1, 'deeper' => ['ok' => true]],
            ],
            'old' => ['name' => 'Ana María'],
            'channel' => 'web',
        ], ActivityPropertiesSanitizer::sanitize($properties));
    }

    public function test_lists_keep_their_items_and_are_sanitized_inside(): void
    {
        $this->assertSame(
            ['ai.manage', ['name' => 'x']],
            ActivityPropertiesSanitizer::sanitize(['ai.manage', ['name' => 'x', 'token' => 'y']]),
        );
        $this->assertSame([], ActivityPropertiesSanitizer::sanitize([]));
    }
}
