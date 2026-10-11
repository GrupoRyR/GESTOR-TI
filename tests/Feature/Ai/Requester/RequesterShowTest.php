<?php

namespace Tests\Feature\Ai\Requester;

use App\Enums\Ai\ApprovalDecision;
use App\Enums\Ai\ApprovalKind;
use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\RequestStatus;
use App\Models\AcceptanceCriterion;
use App\Models\AiRating;
use App\Models\AiRequestApproval;
use App\Models\AiRequestAttachment;
use App\Models\AiRequestComment;
use App\Models\AiRequestStatusChange;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * `Ai/Requests/Show` requester view (RequesterShowProps), "Seguir", the
 * public conversation and attachment downloads.
 */
class RequesterShowTest extends RequesterTestCase
{
    public function test_requester_view_has_exactly_the_contract_props(): void
    {
        $product = $this->product();
        $request = $this->request([
            'status' => RequestStatus::PendingApproval,
            'first_responded_at' => now(),
            'product_id' => $product->id,
            'product_version' => 'v1.8.2',
            'type' => 'improvement',
            'success_criteria' => 'Comparo en un minuto',
        ]);
        $request->followers()->attach($this->paola->id);
        AiRequestStatusChange::factory()->create(['ai_request_id' => $request->id, 'to_status' => RequestStatus::Received]);
        AiRequestStatusChange::factory()->create([
            'ai_request_id' => $request->id, 'from_status' => RequestStatus::Received, 'to_status' => RequestStatus::PendingApproval,
            'user_id' => $this->jhon->id, 'note' => 'Lista para aprobar',
        ]);
        AcceptanceCriterion::factory()->for($request, 'request')->create(['text' => 'Avisa por Telegram', 'note' => 'Depende de T4']);
        AiRequestComment::factory()->create(['ai_request_id' => $request->id, 'user_id' => $this->jhon->id, 'body' => 'Ya quedó evaluada.']);
        AiRequestComment::factory()->internal()->create(['ai_request_id' => $request->id, 'user_id' => $this->jhon->id, 'body' => 'Nota interna secreta']);
        $file = AiRequestAttachment::factory()->create(['ai_request_id' => $request->id, 'original_name' => 'captura.png']);
        AiRequestApproval::factory()->create([
            'ai_request_id' => $request->id, 'kind' => ApprovalKind::Project, 'user_id' => $this->edward->id,
            'decision' => ApprovalDecision::Pending, 'requested_by_id' => $this->jhon->id, 'requested_at' => now(),
            'estimate' => ['hours_total' => 40, 'due_date' => '2026-10-31', 'total_cost' => 6400000, 'external_costs' => 300000],
        ]);

        $this->actingAs($this->paola)
            ->get(route('ai.requests.show', $request))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Requests/Show', false)
                ->where('view', 'requester')
                ->has('request', fn (Assert $detail) => $detail
                    ->where('key', $request->key)
                    ->where('title', $request->title)
                    ->where('type', 'improvement')
                    ->where('status', 'pending_approval')
                    ->where('company_id', $this->segurex->id)
                    ->where('area', ['id' => $this->ventas->id, 'name' => 'Ventas'])
                    ->where('requester.id', $this->paola->id)
                    ->where('requester.name', 'Paola Ríos')
                    ->has('requester.job_title')
                    ->has('requester.avatar_url')
                    ->where('product', ['slug' => 'toma-pedidos', 'name' => 'Toma Pedidos SAP', 'version' => 'v1.8.2'])
                    ->where('hours_per_week', 3)
                    ->where('people', 2)
                    ->where('votes', 0)
                    ->where('rank', 1)
                    ->where('project_key', null)
                    ->where('similar_to', null)
                    ->has('size')->has('priority_suggested')->has('priority_final')->has('urgency')
                    ->has('first_response', fn (Assert $first) => $first->has('due_at')->has('answered_at'))
                    ->has('created_at')
                    ->has('answers', fn (Assert $answers) => $answers
                        ->where('success_criteria', 'Comparo en un minuto')
                        ->has('problem')->has('current_process')->has('expected_result')->has('systems')
                        ->has('systems_notes')->has('deadline')->has('deadline_reason')
                    )
                    ->has('attachments', 1, fn (Assert $attachment) => $attachment
                        ->where('id', $file->id)
                        ->where('name', 'captura.png')
                        ->where('size', 120000)
                        ->where('mime', 'image/png')
                        ->where('url', fn (string $url) => str_contains($url, 'signature='))
                    )
                    ->has('criteria', 1, fn (Assert $criterion) => $criterion
                        ->where('text', 'Avisa por Telegram')->where('validated', null)->where('note', 'Depende de T4')->etc()
                    )
                    ->has('timeline', 2)
                    ->has('timeline.0', fn (Assert $event) => $event
                        ->where('kind', 'status')->where('family', 'amber')->where('title', 'Pendiente de aprobación')
                        ->where('quote', 'Lista para aprobar')->where('actor', 'Jhon Castro')->etc()
                    )
                    ->has('comments', 1, fn (Assert $comment) => $comment
                        ->where('body', 'Ya quedó evaluada.')->where('is_it', true)->where('author.name', 'Jhon Castro')->etc()
                    )
                    ->missing('internal_notes')
                    ->where('estimate', ['due_date' => '2026-10-31', 'hours_total' => 40])
                    ->has('approval', fn (Assert $approval) => $approval
                        ->where('decision', 'pending')
                        ->where('approvers.0.name', 'Edward Ramanan')
                        ->where('requested_by.name', 'Jhon Castro')
                        ->where('decided_by', null)->where('decided_at', null)->where('comment', null)
                        ->where('is_reapproval', false)->has('requested_at')
                    )
                    ->where('rating', null)
                    ->where('followers_count', 1)
                )
                ->where('where.label', 'Esperando la aprobación de un aprobador de proyectos')
                ->has('where.explanation')
                ->where('roadmap_position', [
                    'rank' => 1,
                    'label' => 'Puesto 1 en la hoja de ruta de Ventas',
                    'detail' => 'Si se aprueba, es la siguiente de Ventas.',
                ])
                ->where('following', true)
                ->where('can_comment', true)
            );

        $this->assertEqualsCanonicalizing(
            [...self::SHARED, 'view', 'request', 'where', 'roadmap_position', 'following', 'can_comment'],
            $this->propKeys(route('ai.requests.show', $request)),
        );

        $json = json_encode($this->get(route('ai.requests.show', $request))->viewData('page')['props']);
        $this->assertStringNotContainsString('Nota interna secreta', $json);
        $this->assertStringNotContainsString('6400000', $json);
        $this->assertStringNotContainsString('total_cost', $json);
    }

    public function test_a_colleague_of_the_same_company_sees_the_public_view(): void
    {
        $request = $this->request(['status' => RequestStatus::Backlog, 'first_responded_at' => now()]);

        $this->actingAs($this->juan)
            ->get(route('ai.requests.show', $request))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'requester')
                ->where('following', false)
                ->where('can_comment', false)
                ->where('roadmap_position.label', 'Puesto 1 en la hoja de ruta de Ventas')
            );

        $this->actingAs($this->derivadosUser)->get(route('ai.requests.show', $request))->assertNotFound();
    }

    public function test_ai_managers_get_the_it_view(): void
    {
        $request = $this->request();

        $this->actingAs($this->jhon)
            ->get(route('ai.requests.show', $request))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('view', 'it'));
    }

    public function test_rating_appears_once_given(): void
    {
        $request = $this->inProduction();
        AiRating::create(['ai_request_id' => $request->id, 'user_id' => $this->paola->id, 'solved_need' => 5, 'ease_of_use' => 4, 'comment' => 'Muy útil', 'rated_at' => now()]);
        $request->hoursSavedReports()->create(['user_id' => $this->paola->id, 'hours_per_week' => 2.5, 'reported_at' => now()]);

        $this->actingAs($this->paola)
            ->get(route('ai.requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('request.rating.solved', 5)
                ->where('request.rating.ease', 4)
                ->where('request.rating.comment', 'Muy útil')
                ->where('request.rating.hours_saved_per_week', 2.5)
                ->has('request.rating.rated_at')
                ->where('where.label', 'Ya está en producción')
                ->where('roadmap_position', null)
            );
    }

    public function test_follow_and_unfollow(): void
    {
        $request = $this->request();

        $this->actingAs($this->juan)->post(route('ai.requests.follow', $request))->assertRedirect();
        $this->assertTrue($request->followers()->whereKey($this->juan->id)->exists());

        $this->actingAs($this->juan)
            ->get(route('ai.requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('following', true)->where('can_comment', true));

        $this->actingAs($this->juan)->delete(route('ai.requests.follow', $request))->assertRedirect();
        $this->assertFalse($request->followers()->whereKey($this->juan->id)->exists());

        $this->actingAs($this->derivadosUser)->post(route('ai.requests.follow', $request))->assertNotFound();
    }

    public function test_the_requester_answers_in_the_conversation(): void
    {
        Storage::fake('local');
        $request = $this->request(['status' => RequestStatus::NeedsInfo, 'first_responded_at' => now()]);

        $this->actingAs($this->paola)
            ->post(route('ai.requests.comments.store', $request), [
                'body' => 'Solo los 3 de tornillería.',
                'attachments' => [UploadedFile::fake()->image('lista.png')],
            ])
            ->assertRedirect();

        $comment = AiRequestComment::query()->sole();
        $this->assertSame(CommentVisibility::Public, $comment->visibility);
        $this->assertCount(1, $comment->attachments);
        $this->assertSame(RequestStatus::Evaluating, $request->refresh()->status);

        $this->actingAs($this->paola)
            ->post(route('ai.requests.comments.store', $request), ['body' => ''])
            ->assertSessionHasErrors(['body']);

        // A colleague who does not take part cannot write.
        $this->actingAs($this->juan)
            ->post(route('ai.requests.comments.store', $request), ['body' => 'Hola'])
            ->assertForbidden();
    }

    public function test_attachments_download_only_through_a_signed_authorized_url(): void
    {
        Storage::fake('local');
        $request = $this->request();
        $file = AiRequestAttachment::factory()->create(['ai_request_id' => $request->id, 'original_name' => 'captura.png']);
        Storage::disk('local')->put($file->path, 'png-bytes');
        $internal = AiRequestComment::factory()->internal()->create(['ai_request_id' => $request->id, 'user_id' => $this->jhon->id]);
        $secret = AiRequestAttachment::factory()->create(['ai_request_id' => $request->id, 'ai_request_comment_id' => $internal->id]);
        Storage::disk('local')->put($secret->path, 'secret');

        $signed = fn ($attachment) => URL::temporarySignedRoute('ai.requests.attachments.show', now()->addMinutes(30), [
            'request' => $request->key, 'attachment' => $attachment->id,
        ]);

        $this->actingAs($this->paola)->get($signed($file))->assertOk()->assertDownload('captura.png');
        $this->actingAs($this->paola)
            ->get(route('ai.requests.attachments.show', ['request' => $request->key, 'attachment' => $file->id]))
            ->assertForbidden();
        $this->actingAs($this->paola)->get($signed($secret))->assertForbidden();
        $this->actingAs($this->derivadosUser)->get($signed($file))->assertNotFound();
    }

    public function test_mine_lists_my_requests_with_filters_and_counts(): void
    {
        $asked = $this->request(['status' => RequestStatus::NeedsInfo, 'first_responded_at' => now()]);
        AiRequestComment::factory()->create(['ai_request_id' => $asked->id, 'user_id' => $this->jhon->id, 'body' => '¿Qué proveedores entran?']);
        $live = $this->inProduction();
        $closed = $this->request(['status' => RequestStatus::Closed, 'closed_at' => now()]);
        $this->request(['requester_id' => $this->juan->id, 'created_by_id' => $this->juan->id]);

        $this->actingAs($this->paola)
            ->get(route('ai.requests.mine'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Requests/Mine', false)
                ->where('filter', 'active')
                ->where('counts', ['active' => 2, 'done' => 1, 'all' => 3])
                ->has('requests.data', 2)
                ->has('requests.links')
                ->where('requests.meta.total', 2)
                ->has('requests.data.0', fn (Assert $row) => $row
                    ->where('key', $asked->key)
                    ->where('needs_reply', true)
                    ->where('question.text', '¿Qué proveedores entran?')
                    ->where('question.asked_by', 'Jhon Castro')
                    ->has('question.asked_at')
                    ->where('next_step', 'La evaluación sigue cuando respondas.')
                    ->where('can_rate', false)
                    ->etc()
                )
                ->has('requests.data.1', fn (Assert $row) => $row
                    ->where('key', $live->key)->where('can_rate', true)->where('needs_reply', false)->where('question', null)->etc()
                )
            );

        $this->actingAs($this->paola)
            ->get(route('ai.requests.mine', ['filter' => 'done']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filter', 'done')
                ->has('requests.data', 1)
                ->where('requests.data.0.key', $closed->key)
            );

        $this->actingAs($this->paola)
            ->get(route('ai.requests.mine', ['filter' => 'nonsense']))
            ->assertInertia(fn (Assert $page) => $page->where('filter', 'active'));

        $this->assertEqualsCanonicalizing(
            [...self::SHARED, 'requests', 'filter', 'counts'],
            $this->propKeys(route('ai.requests.mine')),
        );
    }
}
