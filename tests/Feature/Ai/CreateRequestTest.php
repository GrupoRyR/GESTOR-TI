<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\CreateRequest;
use App\Enums\Ai\AreaApprovalStatus;
use App\Enums\Ai\RequestStatus;
use App\Enums\Ai\RequestType;
use App\Enums\Ai\Urgency;
use App\Models\AiRequest;
use App\Models\User;
use App\Notifications\AiApprovalRequested;
use App\Support\AreaApproval;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CreateRequestTest extends AiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function data(array $overrides = []): array
    {
        return array_replace([
            'type' => RequestType::Improvement->value,
            'product_id' => null,
            'product_version' => 'v1.8.2',
            'problem' => 'Cuando un pedido queda bloqueado por cartera el asesor no se entera. Lo descubre el cliente.',
            'who_suffers' => 'Asesores comerciales',
            'current_process' => 'Revisamos SAP cada hora.',
            'hours_per_week' => 6,
            'people' => 8,
            'expected_result' => 'Un aviso al asesor apenas el pedido quede bloqueado.',
            'success_criteria' => 'El asesor recibe el aviso en menos de 5 minutos.',
            'systems' => ['SAP Business One', 'Excel'],
            'systems_notes' => null,
            'urgency' => Urgency::High->value,
            'deadline' => null,
            'deadline_reason' => null,
        ], $overrides);
    }

    private function create(array $overrides = [], array $attachments = [], ?User $actor = null, ?User $requester = null): AiRequest
    {
        return app(CreateRequest::class)->handle($actor ?? $this->paola, $this->data($overrides), $attachments, $requester);
    }

    public function test_it_creates_a_received_request_with_key_area_and_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 10:00', 'America/Bogota'));

        $request = $this->create();

        $this->assertMatchesRegularExpression('/^GIA-\d+$/', $request->key);
        $this->assertSame(RequestStatus::Received, $request->status);
        $this->assertSame($this->segurex->id, $request->company_id);
        $this->assertSame($this->ventas->id, $request->area_id, 'area proposed from the employee');
        $this->assertSame($this->paola->id, $request->requester_id);
        $this->assertSame($this->paola->id, $request->created_by_id);
        $this->assertSame(['SAP Business One', 'Excel'], $request->systems);
        $this->assertSame(8, $request->people_count);
        $this->assertSame('6.0', (string) $request->hours_per_week);
        $this->assertNotSame('', $request->title);
        $this->assertLessThanOrEqual(120, mb_strlen($request->title));
        $this->assertNull($request->priority_score, 'no score until evaluated');

        // Thursday 10:00 + 3 business days = Tuesday 10:00 (Bogotá).
        $this->assertSame('2026-10-13 10:00', $request->first_response_due_at->timezone('America/Bogota')->format('Y-m-d H:i'));

        $history = $request->statusChanges()->get();
        $this->assertCount(1, $history);
        $this->assertNull($history[0]->from_status);
        $this->assertSame(RequestStatus::Received, $history[0]->to_status);

        $this->assertTrue($request->benefitedAreas()->wherePivot('is_primary', true)->whereKey($this->ventas->id)->exists());
        $this->assertDatabaseHas('activity_log', ['log_name' => 'ai', 'subject_id' => $request->id]);
    }

    public function test_the_first_response_window_comes_from_settings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00', 'America/Bogota'));
        app(Settings::class)->set('ai.first_response_business_days', 5);

        $request = $this->create();

        $this->assertSame('2026-10-12 08:00', $request->first_response_due_at->timezone('America/Bogota')->format('Y-m-d H:i'));
    }

    public function test_the_requester_can_pick_another_area_of_their_company_but_not_of_another(): void
    {
        $request = $this->create(['area_id' => $this->compras->id]);
        $this->assertSame($this->compras->id, $request->area_id);

        $this->expectException(ValidationException::class);
        $this->create(['area_id' => $this->derivadosVentas->id]);
    }

    public function test_benefited_areas_are_stored_with_the_primary_one(): void
    {
        $request = $this->create(['benefited_area_ids' => [$this->compras->id]]);

        $areas = $request->benefitedAreas()->get()->mapWithKeys(fn ($area) => [$area->id => (bool) $area->pivot->is_primary])->all();
        $this->assertSame([$this->ventas->id => true, $this->compras->id => false], $areas);
    }

    public function test_acceptance_criteria_are_created_in_order(): void
    {
        $request = $this->create(['criteria' => ['Avisa por Telegram', 'Dice el cliente y el valor']]);

        $this->assertSame(['Avisa por Telegram', 'Dice el cliente y el valor'], $request->criteria()->pluck('text')->all());
        $this->assertSame([1, 2], $request->criteria()->pluck('position')->all());
    }

    public function test_jhon_can_create_on_behalf_of_another_person(): void
    {
        $request = $this->create(actor: $this->jhon, requester: $this->juan);

        $this->assertSame($this->juan->id, $request->requester_id);
        $this->assertSame($this->jhon->id, $request->created_by_id);
        $this->assertSame($this->segurex->id, $request->company_id);
        $this->assertSame($this->ventas->id, $request->area_id);
    }

    public function test_a_requester_cannot_create_on_behalf_of_someone_else(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->create(actor: $this->paola, requester: $this->juan);
    }

    public function test_attachments_are_stored_on_the_private_disk(): void
    {
        $file = UploadedFile::fake()->image('captura.png', 400, 300);
        $pdf = UploadedFile::fake()->create('ejemplo.pdf', 200, 'application/pdf');

        $request = $this->create(attachments: [$file, $pdf]);

        $attachments = $request->attachments()->get();
        $this->assertCount(2, $attachments);
        $this->assertSame('captura.png', $attachments[0]->original_name);
        $this->assertSame('local', $attachments[0]->disk);
        $this->assertStringStartsWith('ai/requests/'.$request->id.'/', $attachments[0]->path);
        Storage::disk('local')->assertExists($attachments[0]->path);
        Storage::disk('local')->assertExists($attachments[1]->path);
    }

    public function test_attachments_outside_the_allow_list_are_rejected(): void
    {
        $file = UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload');

        try {
            $this->create(attachments: [$file]);
            $this->fail('Expected a validation error');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attachments.0', $exception->errors());
        }

        $this->assertSame(0, AiRequest::count(), 'nothing is created when a file is invalid');
    }

    public function test_attachments_over_the_size_limit_are_rejected(): void
    {
        $file = UploadedFile::fake()->create('grande.pdf', 11 * 1024, 'application/pdf');

        $this->expectException(ValidationException::class);
        $this->create(attachments: [$file]);
    }

    public function test_no_more_than_the_configured_number_of_attachments(): void
    {
        $files = array_map(fn (int $i) => UploadedFile::fake()->image("f{$i}.png"), range(1, 11));

        $this->expectException(ValidationException::class);
        $this->create(attachments: $files);
    }

    public function test_with_area_approval_enabled_the_request_waits_for_the_area_approver(): void
    {
        app(AreaApproval::class)->set($this->segurex->id, true);

        $request = $this->create();

        $this->assertSame(AreaApprovalStatus::Pending, $request->area_approval_status);
        $this->assertDatabaseHas('ai_request_approvals', [
            'ai_request_id' => $request->id,
            'kind' => 'area',
            'user_id' => $this->ventasApprover->id,
            'decision' => 'pending',
        ]);
        Notification::assertSentTo($this->ventasApprover, AiApprovalRequested::class);
    }

    public function test_without_area_approval_no_area_step_is_created(): void
    {
        $request = $this->create();

        $this->assertNull($request->area_approval_status);
        $this->assertSame(0, $request->approvals()->count());
        // Stage F: only the "received" / "new request" notices go out.
        Notification::assertSentTimes(AiApprovalRequested::class, 0);
    }

    public function test_an_area_without_approver_skips_the_area_step_even_when_enabled(): void
    {
        app(AreaApproval::class)->set($this->segurex->id, true);

        $request = $this->create(['area_id' => $this->compras->id]);

        $this->assertNull($request->area_approval_status);
    }
}
