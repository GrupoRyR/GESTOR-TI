<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\ValidateAcceptance;
use App\Enums\Ai\AcceptanceResult;
use App\Enums\Ai\RequestStatus;
use App\Models\AcceptanceCriterion;
use App\Models\AiRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ValidateAcceptanceTest extends AiTestCase
{
    /**
     * @return array{0: AiRequest, 1: AcceptanceCriterion, 2: AcceptanceCriterion}
     */
    private function inTesting(): array
    {
        $request = $this->request(['status' => RequestStatus::InTesting, 'first_responded_at' => now()]);
        $first = AcceptanceCriterion::factory()->for($request, 'request')->create(['position' => 1, 'text' => 'Avisa por Telegram']);
        $second = AcceptanceCriterion::factory()->for($request, 'request')->create(['position' => 2, 'text' => 'Dice el cliente']);

        return [$request, $first, $second];
    }

    public function test_the_requester_validates_each_criterion(): void
    {
        Storage::fake('local');
        [$request, $first, $second] = $this->inTesting();

        app(ValidateAcceptance::class)->handle($request, $this->paola, [
            ['id' => $first->id, 'result' => 'pass', 'comment' => null],
            ['id' => $second->id, 'result' => 'fail', 'comment' => 'No dice el valor', 'photo' => UploadedFile::fake()->image('error.png')],
        ]);

        $first->refresh();
        $second->refresh();
        $this->assertSame(AcceptanceResult::Pass, $first->result);
        $this->assertSame($this->paola->id, $first->validated_by_id);
        $this->assertNotNull($first->validated_at);
        $this->assertSame(AcceptanceResult::Fail, $second->result);
        $this->assertSame('No dice el valor', $second->result_comment);
        $this->assertNotNull($second->attachment_id);
        Storage::disk('local')->assertExists($second->attachment->path);
    }

    public function test_only_the_requester_validates(): void
    {
        [$request, $first] = $this->inTesting();

        $this->expectException(AuthorizationException::class);
        app(ValidateAcceptance::class)->handle($request, $this->juan, [['id' => $first->id, 'result' => 'pass']]);
    }

    public function test_only_while_in_testing(): void
    {
        [$request, $first] = $this->inTesting();
        $request->forceFill(['status' => RequestStatus::InDevelopment])->save();

        $this->expectException(AuthorizationException::class);
        app(ValidateAcceptance::class)->handle($request, $this->paola, [['id' => $first->id, 'result' => 'pass']]);
    }

    public function test_criteria_of_another_request_are_refused(): void
    {
        [$request] = $this->inTesting();
        $foreign = AcceptanceCriterion::factory()->create();

        $this->expectException(ValidationException::class);
        app(ValidateAcceptance::class)->handle($request, $this->paola, [['id' => $foreign->id, 'result' => 'pass']]);
    }

    public function test_a_failed_criterion_needs_a_comment(): void
    {
        [$request, $first] = $this->inTesting();

        $this->expectException(ValidationException::class);
        app(ValidateAcceptance::class)->handle($request, $this->paola, [['id' => $first->id, 'result' => 'fail', 'comment' => '']]);
    }
}
