<?php

namespace Tests\Feature\Ai\Requester;

use App\Enums\Ai\AcceptanceResult;
use App\Enums\Ai\RequestStatus;
use App\Models\AcceptanceCriterion;
use App\Models\AiHoursSavedReport;
use App\Models\AiRating;
use App\Models\AiRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * `Ai/Requests/Validate` (acceptance while "En pruebas"), `Ai/Ratings/Create`
 * and `Ai/Ratings/HoursSaved`.
 */
class ValidateAndRateTest extends RequesterTestCase
{
    /**
     * @return array{0: AiRequest, 1: AcceptanceCriterion, 2: AcceptanceCriterion}
     */
    private function inTesting(): array
    {
        $product = $this->product(attributes: ['url' => 'https://pruebas.tomapedidos.segurex.com']);
        $request = $this->request(['status' => RequestStatus::InTesting, 'first_responded_at' => now(), 'product_id' => $product->id]);
        $first = AcceptanceCriterion::factory()->for($request, 'request')->create(['position' => 1, 'text' => 'Avisa por Telegram']);
        $second = AcceptanceCriterion::factory()->for($request, 'request')->create([
            'position' => 2, 'text' => 'Dice el cliente', 'result' => AcceptanceResult::Fail, 'result_comment' => 'No dice el valor',
        ]);

        return [$request, $first, $second];
    }

    public function test_validate_page_has_exactly_the_contract_props(): void
    {
        [$request, $first, $second] = $this->inTesting();

        $this->actingAs($this->paola)
            ->get(route('ai.requests.validation.edit', $request))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Requests/Validate', false)
                ->where('request', [
                    'key' => $request->key,
                    'title' => $request->title,
                    'product' => ['name' => 'Toma Pedidos SAP', 'url' => 'https://pruebas.tomapedidos.segurex.com'],
                ])
                ->where('criteria', [
                    ['id' => $first->id, 'text' => 'Avisa por Telegram', 'result' => null, 'comment' => null],
                    ['id' => $second->id, 'text' => 'Dice el cliente', 'result' => 'fail', 'comment' => 'No dice el valor'],
                ])
            );

        $this->assertEqualsCanonicalizing([...self::SHARED, 'request', 'criteria'], $this->propKeys(route('ai.requests.validation.edit', $request)));

        $this->actingAs($this->juan)->get(route('ai.requests.validation.edit', $request))->assertForbidden();
    }

    public function test_all_criteria_pass_keeps_it_in_testing_for_ti(): void
    {
        [$request, $first, $second] = $this->inTesting();

        $this->actingAs($this->paola)
            ->put(route('ai.requests.validation.update', $request), ['criteria' => [
                ['id' => $first->id, 'result' => 'pass', 'comment' => null],
                ['id' => $second->id, 'result' => 'pass', 'comment' => null],
            ]])
            ->assertRedirect(route('ai.requests.show', $request))
            ->assertSessionHas('success');

        $this->assertSame(RequestStatus::InTesting, $request->refresh()->status);
        $this->assertSame(AcceptanceResult::Pass, $second->refresh()->result);
    }

    public function test_a_failed_criterion_sends_it_back_to_development(): void
    {
        Storage::fake('local');
        [$request, $first, $second] = $this->inTesting();

        $this->actingAs($this->paola)
            ->put(route('ai.requests.validation.update', $request), ['criteria' => [
                ['id' => $first->id, 'result' => 'pass', 'comment' => null],
                ['id' => $second->id, 'result' => 'fail', 'comment' => 'Sigue sin el valor', 'photo' => UploadedFile::fake()->image('error.png')],
            ]])
            ->assertRedirect(route('ai.requests.show', $request));

        $request->refresh();
        $this->assertSame(RequestStatus::InDevelopment, $request->status);
        $this->assertSame(RequestStatus::InDevelopment, $request->statusChanges()->get()->last()->to_status);
        $this->assertNotNull($second->refresh()->attachment_id);
    }

    public function test_validation_errors_and_access(): void
    {
        [$request, $first] = $this->inTesting();

        $this->actingAs($this->paola)
            ->put(route('ai.requests.validation.update', $request), ['criteria' => [['id' => $first->id, 'result' => 'fail', 'comment' => '']]])
            ->assertSessionHasErrors(['criteria.0.comment']);

        $this->actingAs($this->paola)
            ->put(route('ai.requests.validation.update', $request), ['criteria' => [['id' => $first->id, 'result' => 'maybe']]])
            ->assertSessionHasErrors(['criteria.0.result']);

        $this->actingAs($this->juan)
            ->put(route('ai.requests.validation.update', $request), ['criteria' => [['id' => $first->id, 'result' => 'pass']]])
            ->assertForbidden();

        $request->forceFill(['status' => RequestStatus::InDevelopment])->save();
        $this->actingAs($this->paola)->get(route('ai.requests.validation.edit', $request))->assertForbidden();
    }

    public function test_rating_page_and_store_once(): void
    {
        $product = $this->product();
        $request = $this->inProduction(['product_id' => $product->id]);

        $this->actingAs($this->paola)
            ->get(route('ai.ratings.create', $request))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Ratings/Create', false)
                ->where('request', [
                    'key' => $request->key,
                    'title' => $request->title,
                    'product_name' => 'Toma Pedidos SAP',
                    'in_production_since' => now('America/Bogota')->toDateString(),
                ])
            );
        $this->assertEqualsCanonicalizing([...self::SHARED, 'request'], $this->propKeys(route('ai.ratings.create', $request)));

        $this->actingAs($this->paola)
            ->post(route('ai.ratings.store', $request), ['need_score' => 6, 'ease_score' => null])
            ->assertSessionHasErrors(['need_score', 'ease_score']);

        $this->actingAs($this->paola)
            ->post(route('ai.ratings.store', $request), ['need_score' => 5, 'ease_score' => 4, 'comment' => 'Ahorra mucho'])
            ->assertRedirect(route('ai.requests.mine'))
            ->assertSessionHas('success');

        $rating = AiRating::query()->sole();
        $this->assertSame([5, 4, 'Ahorra mucho'], [$rating->solved_need, $rating->ease_of_use, $rating->comment]);

        // Only once.
        $this->actingAs($this->paola)->get(route('ai.ratings.create', $request))->assertForbidden();
        $this->actingAs($this->paola)
            ->post(route('ai.ratings.store', $request), ['need_score' => 1, 'ease_score' => 1])
            ->assertForbidden();
        $this->assertSame(1, AiRating::query()->count());
    }

    public function test_only_the_requester_rates_and_only_in_production(): void
    {
        $request = $this->inProduction();

        $this->actingAs($this->juan)->get(route('ai.ratings.create', $request))->assertForbidden();
        $this->actingAs($this->juan)->post(route('ai.ratings.store', $request), ['need_score' => 5, 'ease_score' => 5])->assertForbidden();

        $testing = $this->request(['status' => RequestStatus::InTesting, 'first_responded_at' => now()]);
        $this->actingAs($this->paola)->get(route('ai.ratings.create', $testing))->assertForbidden();
    }

    public function test_hours_saved_page_and_store(): void
    {
        $product = $this->product();
        $request = $this->inProduction(['product_id' => $product->id, 'hours_per_week' => 3]);

        $this->actingAs($this->paola)
            ->get(route('ai.ratings.hours.create', $request))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Ratings/HoursSaved', false)
                ->where('request.key', $request->key)
                ->where('request.product_name', 'Toma Pedidos SAP')
                ->where('estimated_hours_per_week', 3)
            );
        $this->assertEqualsCanonicalizing(
            [...self::SHARED, 'request', 'estimated_hours_per_week'],
            $this->propKeys(route('ai.ratings.hours.create', $request)),
        );

        $this->actingAs($this->paola)
            ->post(route('ai.ratings.hours.store', $request), ['hours_per_week' => 61])
            ->assertSessionHasErrors(['hours_per_week']);
        $this->actingAs($this->paola)
            ->post(route('ai.ratings.hours.store', $request), ['hours_per_week' => 2.3])
            ->assertSessionHasErrors(['hours_per_week']);

        $this->actingAs($this->paola)
            ->post(route('ai.ratings.hours.store', $request), ['hours_per_week' => 2.5])
            ->assertRedirect(route('ai.requests.mine'));

        $this->assertSame('2.5', AiHoursSavedReport::query()->sole()->hours_per_week);
        $this->actingAs($this->paola)->post(route('ai.ratings.hours.store', $request), ['hours_per_week' => 1])->assertForbidden();
        $this->actingAs($this->juan)->get(route('ai.ratings.hours.create', $request))->assertForbidden();
    }

    public function test_products_average_their_ratings_and_hours_saved(): void
    {
        $product = $this->product();
        $this->assertNull($product->averageRating());
        $this->assertNull($product->hoursSaved());

        $first = $this->inProduction(['product_id' => $product->id]);
        $second = $this->inProduction(['product_id' => $product->id, 'requester_id' => $this->juan->id, 'created_by_id' => $this->juan->id]);
        AiRating::create(['ai_request_id' => $first->id, 'user_id' => $this->paola->id, 'solved_need' => 5, 'ease_of_use' => 3, 'rated_at' => now()]);
        AiRating::create(['ai_request_id' => $second->id, 'user_id' => $this->juan->id, 'solved_need' => 4, 'ease_of_use' => 4, 'rated_at' => now()]);
        $first->hoursSavedReports()->create(['user_id' => $this->paola->id, 'hours_per_week' => 3, 'reported_at' => now()]);
        $second->hoursSavedReports()->create(['user_id' => $this->juan->id, 'hours_per_week' => 1.5, 'reported_at' => now()]);

        $this->assertSame(4.5, $product->averageRating());
        $this->assertSame(2, $product->ratingsCount());
        // 4.5 h/week × 52 / 12 = 19.5 h/month.
        $this->assertSame(19.5, $product->hoursSaved());
    }
}
