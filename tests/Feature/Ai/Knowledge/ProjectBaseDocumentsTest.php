<?php

namespace Tests\Feature\Ai\Knowledge;

use App\Domain\Ai\DocumentTemplates;
use App\Domain\Ai\SecretGuard;
use App\Enums\Ai\RequestStatus;
use App\Models\AiProject;
use Illuminate\Support\Facades\Notification;

class ProjectBaseDocumentsTest extends KnowledgeTestCase
{
    public function test_converting_a_request_leaves_the_base_documentation_ready(): void
    {
        Notification::fake();
        $request = $this->evaluated(['title' => 'Visualizador de cartera', 'expected_result' => 'Ver la cartera por asesor']);
        $request->criteria()->create(['position' => 1, 'text' => 'Filtra por asesor']);
        $this->sendToApproval($request, ['approver_ids' => [$this->edward->id]]);
        $this->actingAs($this->edward)->post(route('ai.approvals.approve', $request), ['comment' => null]);
        $this->assertSame(RequestStatus::Approved, $request->fresh()->status);

        $this->actingAs($this->jhon)
            ->post(route('ai.requests.convert', $request), ['name' => 'Visualizador · filtros', 'start_date' => '2026-10-12'])
            ->assertSessionHasNoErrors();

        $project = AiProject::query()->sole();
        $docs = $project->documents()->orderBy('id')->get();

        $this->assertSame(DocumentTemplates::PROJECT_BASE, $docs->pluck('template')->all());
        $this->assertSame(
            ['Ficha del proyecto', 'Requerimientos y criterios', 'Arquitectura y datos', 'Prompts y modelos', 'Registro de decisiones', 'Acta de entrega'],
            $docs->pluck('title')->all(),
        );
        $this->assertTrue($docs->every(fn ($doc) => $doc->version === 1 && $doc->revisions()->count() === 1 && $doc->author_id === $this->jhon->id));

        $sheet = $docs->firstWhere('template', 'project_sheet');
        $this->assertStringContainsString('# Ficha del proyecto · Visualizador · filtros', $sheet->body);
        $this->assertStringContainsString('Ver la cartera por asesor', $sheet->body);
        $this->assertSame('project', $sheet->visibility);

        $requirements = $docs->firstWhere('template', 'requirements');
        $this->assertStringContainsString('- [ ] Filtra por asesor', $requirements->body);
        $this->assertStringContainsString($request->key, $requirements->body);
    }

    public function test_every_template_has_a_title_a_visibility_and_no_secrets(): void
    {
        $guard = new SecretGuard;

        foreach (DocumentTemplates::KEYS as $key) {
            $this->assertNotSame('', DocumentTemplates::title($key));
            $this->assertContains(DocumentTemplates::visibility($key), ['company', 'it', 'project']);
            $this->assertNull($guard->find(DocumentTemplates::body($key, 'Producto')), "Template {$key} looks like a secret.");
        }

        $this->assertSame('', DocumentTemplates::body('blank', 'Producto'));
        $this->assertStringContainsString('Nunca escribas contraseñas', DocumentTemplates::body('architecture', 'Producto'));
    }
}
