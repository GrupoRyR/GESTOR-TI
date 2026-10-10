<?php

namespace Tests\Feature\Ai\Projects;

use App\Enums\Ai\RequestStatus;
use App\Models\AiRequirement;
use App\Notifications\AiRequirementReminder;
use App\Notifications\AiRequirementRequested;
use App\Notifications\AiTaskUnblocked;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

class RequirementsTest extends ProjectsTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_requirements_page_returns_exactly_the_contract_props(): void
    {
        $project = $this->project();

        $this->assertSame(
            $this->withShared(['project', 'requirements', 'scope', 'my_projects', 'recipients', 'reminder_every_days', 'today', 'now']),
            $this->propKeys(route('ai.projects.requirements', $project)),
        );

        $this->actingAs($this->paola)->get(route('ai.projects.requirements', $project))->assertForbidden();
    }

    public function test_requirements_page_lists_open_by_due_date_then_answered(): void
    {
        $project = $this->project();
        $project->members()->create(['user_id' => $this->jhon->id, 'role' => 'Líder del proyecto']);
        $task = $this->task($project, 'todo', ['title' => 'Endpoint']);
        $this->task($project, 'done');
        $late = $this->requirement($project, ['title' => 'Vencido', 'due_date' => now()->subDays(2)->toDateString(), 'blocks_task_id' => $task->id, 'reminders_sent' => 2]);
        $soon = $this->requirement($project, ['title' => 'Pronto', 'due_date' => now()->addDay()->toDateString()]);
        $answered = $this->requirement($project, ['title' => 'Listo', 'status' => 'answered', 'answer' => 'Va', 'answered_at' => now()]);
        $other = $this->project(['company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id]);
        $other->members()->create(['user_id' => $this->jhon->id, 'role' => 'Desarrollo']);
        $this->requirement($other, ['title' => 'De otro proyecto', 'recipient_id' => $this->derivadosUser->id, 'recipient_label' => 'Diego Restrepo']);

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.requirements', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Projects/Requirements', false)
                ->where('scope', 'project')
                ->where('reminder_every_days', 2)
                ->has('requirements', 3)
                ->where('requirements.0.id', $late->id)
                ->where('requirements.1.id', $soon->id)
                ->where('requirements.2.id', $answered->id)
                ->has('requirements.0', fn (Assert $item) => $item
                    ->where('project_key', $project->key)
                    ->where('title', 'Vencido')
                    ->where('status', 'overdue')
                    ->where('recipient.name', 'Paola Ríos')
                    ->where('recipient_label', 'Paola Ríos')
                    ->where('area_name', 'Ventas')
                    ->where('company_id', $this->segurex->id)
                    ->where('reminders_sent', 2)
                    ->where('blocks_task', ['key' => $task->key, 'title' => 'Endpoint'])
                    ->where('answered_at', null)
                    ->where('answer', null)
                    ->etc()
                )
                ->where('my_projects.0.key', $project->key)
                ->where('my_projects.0.tasks', [['key' => $task->key, 'title' => 'Endpoint']])
                ->where('recipients', fn ($recipients) => collect($recipients)->contains(fn ($option) => $option['person']['name'] === 'Paola Ríos' && $option['area_name'] === 'Ventas'))
            );

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.requirements', [$project, 'scope' => 'all']))
            ->assertInertia(fn (Assert $page) => $page->where('scope', 'all')->has('requirements', 4)->has('my_projects', 2));
    }

    public function test_store_blocks_the_task_and_notifies_the_recipient(): void
    {
        Notification::fake();
        $project = $this->project();
        $task = $this->task($project, 'todo', [], [$this->superAdmin]);

        $this->actingAs($this->jhon)
            ->post(route('ai.requirements.store', $project), [
                'what' => "Acceso de lectura a la vista de cartera\nUn usuario que solo consulte.",
                'recipient_id' => $this->paola->id,
                'due_date' => now()->addDays(2)->toDateString(),
                'blocks_task' => $task->key,
            ])
            ->assertSessionHasNoErrors();

        $requirement = AiRequirement::query()->sole();
        $this->assertSame('Acceso de lectura a la vista de cartera', $requirement->title);
        $this->assertSame('Un usuario que solo consulte.', $requirement->detail);
        $this->assertSame('Ventas', $requirement->area_name);
        $this->assertSame($task->id, $requirement->blocks_task_id);
        Notification::assertSentTo($this->paola, AiRequirementRequested::class);

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.board', $project))
            ->assertInertia(fn (Assert $page) => $page->where('blocked_total', 1)->where('tasks.0.blocked_by.id', $requirement->id));

        // Unknown recipient: placeholder label with the project's area.
        $this->actingAs($this->jhon)->post(route('ai.requirements.store', $project), [
            'what' => 'Fotos de producto', 'recipient_id' => null, 'due_date' => now()->addDays(4)->toDateString(), 'blocks_task' => null,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ai_requirements', ['title' => 'Fotos de producto', 'recipient_id' => null, 'recipient_label' => '[RESPONSABLE VENTAS]']);

        $this->actingAs($this->jhon)->post(route('ai.requirements.store', $project), [
            'what' => '', 'recipient_id' => 999_999, 'due_date' => now()->subDay()->toDateString(), 'blocks_task' => 'T99',
        ])->assertSessionHasErrors(['what', 'recipient_id', 'due_date', 'blocks_task']);
    }

    public function test_respond_page_is_only_for_the_recipient_and_it(): void
    {
        $project = $this->project(['objective' => 'Ver la cartera por asesor']);
        $requirement = $this->requirement($project, ['title' => 'Lista de zonas', 'detail' => 'Con asesores']);

        $this->assertSame(
            $this->withShared(['requirement', 'reassign_candidates', 'suggested_user_id', 'max_upload_mb', 'today', 'now']),
            $this->propKeys(route('ai.requirements.respond', $requirement->id), $this->paola),
        );

        $this->actingAs($this->paola)
            ->get(route('ai.requirements.respond', $requirement->id))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Requirements/Respond', false)
                ->where('requirement', [
                    'id' => $requirement->id,
                    'title' => 'Lista de zonas',
                    'detail' => 'Con asesores',
                    'due_date' => $requirement->due_date->toDateString(),
                    'status' => 'pending',
                    'requested_by' => ['id' => $this->jhon->id, 'name' => 'Jhon Castro', 'job_title' => null, 'avatar_url' => null],
                    'project' => ['name' => $project->name, 'context' => 'Ver la cartera por asesor'],
                    'answered_at' => null,
                    'answer' => null,
                    'answer_attachment' => null,
                ])
                ->where('max_upload_mb', 10)
                ->where('suggested_user_id', $this->ventasApprover->id)
                ->where('reassign_candidates.0.person.id', $this->ventasApprover->id)
                ->where('reassign_candidates', fn ($candidates) => ! collect($candidates)->contains(fn ($option) => $option['person']['id'] === $this->paola->id))
            );

        $this->actingAs($this->juan)->get(route('ai.requirements.respond', $requirement->id))->assertForbidden();
        $this->actingAs($this->derivadosUser)->get(route('ai.requirements.respond', $requirement->id))->assertForbidden();
        $this->actingAs($this->jhon)->get(route('ai.requirements.respond', $requirement->id))->assertOk();

        // Notices link to `ai.requirements.show`: recipient → respond page, IT → project page.
        $this->actingAs($this->paola)->get(route('ai.requirements.show', $requirement->id))->assertRedirect(route('ai.requirements.respond', $requirement->id));
        $this->actingAs($this->jhon)->get(route('ai.requirements.show', $requirement->id))->assertRedirect(route('ai.projects.requirements', $project));
        $this->actingAs($this->juan)->get(route('ai.requirements.show', $requirement->id))->assertForbidden();
    }

    public function test_recipient_of_another_company_can_answer(): void
    {
        $project = $this->project();
        $requirement = $this->requirement($project, ['recipient_id' => $this->derivadosUser->id, 'recipient_label' => 'Diego Restrepo']);

        $this->actingAs($this->derivadosUser)->get(route('ai.requirements.respond', $requirement->id))->assertOk();
    }

    public function test_answer_clears_the_block_and_notifies_the_assignee(): void
    {
        Notification::fake();
        Storage::fake('local');
        $project = $this->project();
        $task = $this->task($project, 'todo', [], [$this->superAdmin]);
        $requirement = $this->requirement($project, ['blocks_task_id' => $task->id, 'title' => 'Acceso SAP']);

        $this->actingAs($this->juan)->post(route('ai.requirements.answer', $requirement->id), ['answer' => 'No'])->assertForbidden();

        $this->actingAs($this->paola)
            ->post(route('ai.requirements.answer', $requirement->id), [
                'answer' => 'Creé el usuario CONSULTA_CARTERA.',
                'attachment' => UploadedFile::fake()->create('permiso.png', 50, 'image/png'),
            ])
            ->assertSessionHasNoErrors();

        $requirement->refresh();
        $this->assertSame('answered', $requirement->status);
        $this->assertSame('Creé el usuario CONSULTA_CARTERA.', $requirement->answer);
        $this->assertSame($this->paola->id, $requirement->answered_by_id);
        $this->assertSame('permiso.png', $requirement->answer_name);
        Storage::disk('local')->assertExists($requirement->answer_path);
        Notification::assertSentTo($this->superAdmin, AiTaskUnblocked::class);
        $this->assertDatabaseHas('ai_project_activities', ['ai_project_id' => $project->id, 'kind' => 'requirement', 'user_id' => $this->paola->id, 'text' => 'respondió el requerimiento Acceso SAP']);

        $this->actingAs($this->jhon)
            ->get(route('ai.projects.board', $project))
            ->assertInertia(fn (Assert $page) => $page->where('blocked_total', 0)->where('tasks.0.blocked_by', null));

        $this->actingAs($this->paola)
            ->get(route('ai.requirements.respond', $requirement->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('requirement.status', 'answered')
                ->where('requirement.answer_attachment.name', 'permiso.png')
            );

        // Answering twice is refused.
        $this->actingAs($this->paola)->post(route('ai.requirements.answer', $requirement->id), ['answer' => 'Otra vez'])->assertSessionHas('error');
    }

    public function test_reassign_moves_it_to_a_coworker(): void
    {
        Notification::fake();
        $project = $this->project();
        $requirement = $this->requirement($project);

        $this->actingAs($this->paola)
            ->post(route('ai.requirements.reassign', $requirement->id), ['user_id' => $this->juan->id, 'note' => 'Juan lo tiene'])
            ->assertRedirect(route('home'));

        $requirement->refresh();
        $this->assertSame($this->juan->id, $requirement->recipient_id);
        $this->assertSame('Juan Pérez', $requirement->recipient_label);
        Notification::assertSentTo($this->juan, AiRequirementRequested::class);
        $this->actingAs($this->paola)->get(route('ai.requirements.respond', $requirement->id))->assertForbidden();
    }

    public function test_remind_now_and_the_daily_reminder_command(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-07 08:00', 'America/Bogota'));
        $project = $this->project();
        $soon = $this->requirement($project, ['due_date' => '2026-10-08']);
        $later = $this->requirement($project, ['due_date' => '2026-10-20', 'recipient_id' => $this->juan->id, 'recipient_label' => 'Juan Pérez']);
        $answered = $this->requirement($project, ['due_date' => '2026-10-01', 'status' => 'answered', 'answer' => 'Ok', 'answered_at' => now()]);
        $unknown = $this->requirement($project, ['due_date' => '2026-10-01', 'recipient_id' => null, 'recipient_label' => '[RESPONSABLE]']);

        $this->actingAs($this->jhon)->post(route('ai.requirements.remind', $later->id))->assertSessionHas('success');
        Notification::assertSentTo($this->juan, AiRequirementReminder::class);
        $this->assertSame(1, $later->fresh()->reminders_sent);
        $this->actingAs($this->jhon)->post(route('ai.requirements.remind', $unknown->id))->assertSessionHas('error');
        $this->actingAs($this->paola)->post(route('ai.requirements.remind', $soon->id))->assertForbidden();

        $this->artisan('ai:requirement-reminders')->assertSuccessful();
        Notification::assertSentToTimes($this->paola, AiRequirementReminder::class, 1);
        // Not near its due date yet.
        Notification::assertSentToTimes($this->juan, AiRequirementReminder::class, 1);
        $this->assertSame(1, $soon->fresh()->reminders_sent);
        $this->assertSame(0, $answered->fresh()->reminders_sent);

        // Next day: within the 2-day cadence, no new reminder.
        Carbon::setTestNow(Carbon::parse('2026-10-08 08:00', 'America/Bogota'));
        $this->artisan('ai:requirement-reminders')->assertSuccessful();
        Notification::assertSentToTimes($this->paola, AiRequirementReminder::class, 1);

        // Two days later (overdue): reminded again.
        Carbon::setTestNow(Carbon::parse('2026-10-09 08:00', 'America/Bogota'));
        $this->artisan('ai:requirement-reminders')->assertSuccessful();
        Notification::assertSentToTimes($this->paola, AiRequirementReminder::class, 2);
        $this->assertSame(2, $soon->fresh()->reminders_sent);
    }

    public function test_reminder_command_is_scheduled_daily_at_eight(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('ai:requirement-reminders')->assertSuccessful();
        $this->assertSame(RequestStatus::Planned, $this->project()->status);
    }
}
