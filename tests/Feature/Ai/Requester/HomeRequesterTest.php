<?php

namespace Tests\Feature\Ai\Requester;

use App\Enums\Ai\RequestStatus;
use App\Models\AiRating;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Home `requester` view (`types/pages/home.ts` RequesterRequest): the
 * person's open requests, newest activity first.
 */
class HomeRequesterTest extends RequesterTestCase
{
    public function test_requester_home_lists_my_open_requests(): void
    {
        $backlog = $this->request(['status' => RequestStatus::Backlog, 'first_responded_at' => now(), 'final_priority' => 10, 'updated_at' => now()->subDays(2)]);
        $this->request(['status' => RequestStatus::Backlog, 'first_responded_at' => now(), 'final_priority' => 90, 'requester_id' => $this->juan->id, 'created_by_id' => $this->juan->id]);
        $live = $this->inProduction(['updated_at' => now()->subDay()]);
        $rated = $this->inProduction(['updated_at' => now()->subDays(3)]);
        AiRating::create(['ai_request_id' => $rated->id, 'user_id' => $this->paola->id, 'solved_need' => 4, 'ease_of_use' => 4, 'rated_at' => now()]);
        $this->request(['status' => RequestStatus::Rejected, 'rejection_reason' => 'other', 'closed_at' => now()]);
        $fresh = $this->request();

        $this->actingAs($this->paola)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'requester')
                ->where('requests', fn ($rows) => collect($rows)->pluck('key')->all() === [$fresh->key, $live->key, $backlog->key, $rated->key])
                ->where('requests.0', [
                    'key' => $fresh->key,
                    'title' => $fresh->title,
                    'status' => 'received',
                    'next_step' => 'TI la revisa y te responde en la conversación.',
                    'can_rate' => false,
                    'roadmap_position' => null,
                ])
                ->where('requests.1.can_rate', true)
                ->where('requests.2.roadmap_position', 'Puesto 2 en la hoja de ruta de Ventas')
                ->where('requests.3.can_rate', false)
            );
    }
}
