<?php

namespace Database\Factories;

use App\Models\AiRequest;
use App\Models\AiRequestAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiRequestAttachment>
 */
class AiRequestAttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_request_id' => AiRequest::factory(),
            'ai_request_comment_id' => null,
            'user_id' => null,
            'disk' => 'local',
            'path' => fn (array $attributes) => 'ai/requests/'.$attributes['ai_request_id'].'/'.Str::random(40).'.png',
            'original_name' => 'captura.png',
            'mime' => 'image/png',
            'size' => 120_000,
        ];
    }
}
