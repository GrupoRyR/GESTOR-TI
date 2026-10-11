<?php

namespace Tests\Feature\Mcp;

use App\Models\AiDocument;
use App\Models\AiProject;
use App\Models\AiTaskComment;
use Spatie\Activitylog\Models\Activity;

/**
 * `agregar_nota` (project feed or task comment) and
 * `adjuntar_documento` (App\Actions\Ai\Documents, with a revision).
 */
class NoteAndDocumentToolsTest extends McpToolsTestCase
{
    public function test_a_task_note_is_a_task_comment_with_its_feed_entry(): void
    {
        $result = $this->toolResult($this->tool('agregar_nota', [
            'proyecto' => $this->project->key,
            'tarea' => $this->t1->key,
            'nota' => 'Quedó lista la consulta por asesor; falta probar con datos reales.',
        ]));

        $comment = AiTaskComment::query()->sole();
        $this->assertSame($this->t1->id, $comment->ai_task_id);
        $this->assertSame($this->jhon->id, $comment->user_id);
        $this->assertSame('Quedó lista la consulta por asesor; falta probar con datos reales.', $comment->body);
        $this->assertDatabaseHas('ai_project_activities', [
            'ai_project_id' => $this->project->id, 'kind' => 'comment', 'text' => "comentó en {$this->t1->key} {$this->t1->title}",
        ]);
        $this->assertSame(route('ai.tasks.show', [$this->project->key, $this->t1->key]), $result['url']);

        $audit = Activity::query()->where('event', 'mcp_progress_note')->sole();
        $this->assertSame('api', $audit->properties['origin']);
        $this->assertTrue($audit->subject->is($this->t1));
    }

    public function test_a_project_note_goes_to_the_activity_feed(): void
    {
        $result = $this->toolResult($this->tool('agregar_nota', [
            'proyecto' => $this->project->key,
            'nota' => 'Demo con cartera el viernes.',
        ]));

        $this->assertDatabaseHas('ai_project_activities', [
            'ai_project_id' => $this->project->id, 'kind' => 'comment', 'user_id' => $this->jhon->id,
            'text' => 'nota de avance: Demo con cartera el viernes.',
        ]);
        $this->assertSame(route('ai.projects.show', $this->project->key), $result['url']);
        $this->assertSame(1, Activity::query()->where('event', 'mcp_progress_note')->count());
    }

    public function test_notes_are_validated_and_refuse_credentials(): void
    {
        $this->assertStringContainsString('200', $this->toolError($this->tool('agregar_nota', [
            'proyecto' => $this->project->key, 'nota' => str_repeat('a', 201),
        ])));
        $this->assertStringContainsString('nota', $this->toolError($this->tool('agregar_nota', ['proyecto' => $this->project->key])));
        $this->assertStringContainsString('bóveda', $this->toolError($this->tool('agregar_nota', [
            'proyecto' => $this->project->key, 'tarea' => $this->t1->key, 'nota' => 'La clave es contraseña: Cartera2026!',
        ])));
        $this->assertSame(0, AiTaskComment::query()->count());

        $viewer = $this->connectorUser('Conector lector', ['companies.view_all', 'ai.requests.create']);
        $this->assertStringContainsString('permiso', $this->toolError($this->tool('agregar_nota', [
            'proyecto' => $this->project->key, 'nota' => 'Hola',
        ], $viewer)));
        $this->assertSame(0, Activity::query()->where('event', 'mcp_progress_note')->count());
    }

    public function test_adjuntar_documento_creates_a_project_document_with_its_first_version(): void
    {
        $result = $this->toolResult($this->tool('adjuntar_documento', [
            'proyecto' => $this->project->key,
            'titulo' => 'Notas de la reunión con cartera',
            'contenido' => "# Reunión\n\n- Filtrar por zona primero.",
        ]));

        /** @var AiDocument $document */
        $document = AiDocument::query()->where('title', 'Notas de la reunión con cartera')->sole();
        $this->assertSame($this->project->id, $document->ai_project_id);
        $this->assertSame('blank', $document->template);
        $this->assertSame("# Reunión\n\n- Filtrar por zona primero.", $document->body);
        $this->assertSame(1, $document->version);
        $this->assertSame(1, $document->revisions()->count());
        $this->assertSame(['id' => $document->id, 'titulo' => 'Notas de la reunión con cartera', 'version' => 1, 'url' => route('ai.docs.edit', $document->id)], $result);

        $audit = Activity::query()->where('event', 'mcp_document_created')->sole();
        $this->assertSame('api', $audit->properties['origin']);
        $this->assertTrue($audit->subject->is($document));
    }

    public function test_adjuntar_documento_publishes_a_new_version_of_an_existing_document(): void
    {
        $result = $this->toolResult($this->tool('adjuntar_documento', [
            'documento_id' => $this->requirements->id,
            'contenido' => "# Requerimientos\n\nFiltro por asesor, zona y fecha.",
            'resumen' => 'Agregué el filtro por fecha.',
        ]));

        $document = $this->requirements->fresh();
        $this->assertSame(2, $document->version);
        $this->assertSame("# Requerimientos\n\nFiltro por asesor, zona y fecha.", $document->body);
        $this->assertSame('Agregué el filtro por fecha.', $document->revisions()->first()->summary);
        $this->assertSame(2, $result['version']);
        $this->assertSame(1, Activity::query()->where('event', 'mcp_document_updated')->count());
    }

    public function test_adjuntar_documento_validates_permissions_size_and_secrets(): void
    {
        config(['gestor.mcp.document_max_chars' => 50]);
        $other = AiProject::factory()->create(['company_id' => $this->derivados->id]);
        $foreignDoc = $this->document($other, 'blank', 'Documento de Derivados', 'it', '# Derivados');
        $restricted = $this->connectorUser('TI Segurex', ['ai.manage', 'ai.docs.edit']);
        $noEdit = $this->connectorUser('TI sin edición', ['ai.manage', 'companies.view_all']);

        $this->assertStringContainsString('50', $this->toolError($this->tool('adjuntar_documento', [
            'proyecto' => $this->project->key, 'titulo' => 'Largo', 'contenido' => str_repeat('a', 51),
        ])));
        $this->assertStringContainsString('bóveda', $this->toolError($this->tool('adjuntar_documento', [
            'proyecto' => $this->project->key, 'titulo' => 'Accesos', 'contenido' => 'password=Cartera2026!',
        ])));
        $this->assertStringContainsString('proyecto o un producto', $this->toolError($this->tool('adjuntar_documento', [
            'titulo' => 'Sin dueño', 'contenido' => 'Hola',
        ])));
        $this->assertSame("No encontré el documento {$foreignDoc->id}.", $this->toolError($this->tool('adjuntar_documento', [
            'documento_id' => $foreignDoc->id, 'contenido' => 'Hola',
        ], $restricted)));
        $this->assertStringContainsString('permiso', $this->toolError($this->tool('adjuntar_documento', [
            'documento_id' => $this->requirements->id, 'contenido' => 'Hola',
        ], $noEdit)));

        $this->assertSame(1, $this->requirements->fresh()->version);
        $this->assertSame(2, AiDocument::query()->count());
        $this->assertSame(0, Activity::query()->whereIn('event', ['mcp_document_created', 'mcp_document_updated'])->count());
    }
}
