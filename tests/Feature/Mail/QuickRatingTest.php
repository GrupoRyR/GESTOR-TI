<?php

namespace Tests\Feature\Mail;

use App\Enums\Ai\RequestStatus;
use App\Models\AiRating;
use App\Models\AiRequest;
use App\Support\Mail\RequestEmails;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Ai\AiTestCase;

/**
 * One-click rating links of the `delivered` email (`ai.ratings.quick`).
 */
class QuickRatingTest extends AiTestCase
{
    private AiRequest $delivered;

    protected function setUp(): void
    {
        parent::setUp();

        $this->delivered = $this->request(['status' => RequestStatus::InProduction]);
    }

    private function link(int $score): string
    {
        return RequestEmails::ratingLinks($this->delivered, $this->paola)["rating_url_{$score}"];
    }

    public function test_a_signed_link_saves_the_score_without_signing_in(): void
    {
        $this->get($this->link(4))
            ->assertRedirect(route('ai.requests.show', $this->delivered->key))
            ->assertSessionHas('success');

        $rating = AiRating::query()->sole();
        $this->assertSame($this->paola->id, $rating->user_id);
        $this->assertSame($this->delivered->id, $rating->ai_request_id);
        $this->assertSame(4, $rating->solved_need);
        $this->assertNull($rating->ease_of_use);
    }

    public function test_it_is_idempotent(): void
    {
        $this->get($this->link(2))->assertRedirect();
        $this->get($this->link(5))->assertRedirect(route('ai.requests.show', $this->delivered->key));
        $this->actingAs($this->paola)->get($this->link(3))->assertRedirect();

        $this->assertSame(1, AiRating::query()->count());
        $this->assertSame(2, AiRating::query()->sole()->solved_need);
    }

    public function test_a_tampered_link_is_refused(): void
    {
        $this->get(str_replace('/rating/4?', '/rating/5?', $this->link(4)))->assertForbidden();
        $this->get(str_replace("user={$this->paola->id}", "user={$this->juan->id}", $this->link(4)))->assertForbidden();
        $this->get(route('ai.ratings.quick', ['request' => $this->delivered->key, 'score' => 4, 'user' => $this->paola->id]))->assertForbidden();

        $this->assertSame(0, AiRating::query()->count());
    }

    public function test_an_expired_link_is_refused(): void
    {
        $link = $this->link(4);

        $this->travel(31)->days();

        $this->get($link)->assertForbidden();
        $this->assertSame(0, AiRating::query()->count());
    }

    public function test_a_link_for_someone_who_is_not_the_requester_is_refused(): void
    {
        $link = URL::temporarySignedRoute('ai.ratings.quick', now()->addDay(), [
            'request' => $this->delivered->key,
            'score' => 5,
            'user' => $this->juan->id,
        ]);

        $this->get($link)->assertForbidden();
        $this->assertSame(0, AiRating::query()->count());
    }

    public function test_a_request_no_longer_in_production_is_not_rated(): void
    {
        $this->delivered->forceFill(['status' => RequestStatus::Closed])->save();

        $this->get($this->link(5))
            ->assertRedirect(route('ai.requests.show', $this->delivered->key))
            ->assertSessionHas('error');
        $this->assertSame(0, AiRating::query()->count());
    }

    public function test_the_link_lives_thirty_days(): void
    {
        $link = $this->link(1);

        $this->travel(29)->days();

        $this->get($link)->assertRedirect();
        $this->assertSame(1, AiRating::query()->count());
    }
}
