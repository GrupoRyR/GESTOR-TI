<?php

namespace App\Mcp\Tools;

use App\Actions\Ai\Documents;
use App\Domain\Ai\DocumentTemplates;
use App\Mcp\Tools\Concerns\InteractsWithGestor;
use App\Models\AiDocument;
use App\Models\AiProduct;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Markdown documentation through App\Actions\Ai\Documents (SecretGuard,
 * revisions): `documento_id` saves the working copy and publishes the
 * next version (AiDocumentPolicy::update); otherwise a new document of a
 * project or product, published as version 1 (AiDocumentPolicy::create).
 * Only text: no binary uploads through the connector. Size limit
 * `gestor.mcp.document_max_chars` (100 000 by default).
 */
#[Name('adjuntar_documento')]
#[Description('Crea o actualiza un documento en Markdown de la documentación de un proyecto (P-n) o de un producto (identificador del producto) en el Gestor IA, y publica una versión. Para actualizar, envía documento_id (lo da ver_solicitud) y el contenido completo nuevo. Solo acepta texto o Markdown: no sube archivos binarios. No acepta contraseñas ni llaves (van en la bóveda).')]
#[IsDestructive(false)]
class AdjuntarDocumento extends Tool
{
    use InteractsWithGestor;

    public const DEFAULT_MAX_CHARS = 100000;

    public const DEFAULT_SUMMARY = 'Actualizado desde Claude.';

    public function handle(Request $request, Documents $documents): Response
    {
        $user = $this->actor($request);
        $data = $request->validate([
            'proyecto' => ['nullable', 'string', 'max:30'],
            'producto' => ['nullable', 'string', 'max:100'],
            'documento_id' => ['nullable', 'integer', 'min:1'],
            'titulo' => ['nullable', 'string', 'max:160'],
            'plantilla' => ['nullable', Rule::in(DocumentTemplates::KEYS)],
            'contenido' => ['required', 'string'],
            'resumen' => ['nullable', 'string', 'max:255'],
        ], [
            'proyecto.*' => 'Indica la clave del proyecto, por ejemplo P-3.',
            'producto.*' => 'Indica el identificador del producto.',
            'documento_id.*' => 'El documento_id es el número del documento.',
            'titulo.*' => 'El título puede tener hasta 160 caracteres.',
            'plantilla.*' => 'Esa plantilla no existe. Usa: '.implode(', ', DocumentTemplates::KEYS).'.',
            'contenido.*' => 'Escribe el contenido del documento en Markdown.',
            'resumen.*' => 'El resumen del cambio puede tener hasta 255 caracteres.',
        ]);

        $max = (int) config('gestor.mcp.document_max_chars', self::DEFAULT_MAX_CHARS);

        if (mb_strlen($data['contenido']) > $max) {
            return Response::error("El documento puede tener hasta {$max} caracteres. Divídelo en varios documentos.");
        }

        if (isset($data['documento_id'])) {
            return $this->update($documents, $user, (int) $data['documento_id'], $data['contenido'], $data['resumen'] ?? null);
        }

        return $this->create($documents, $user, $data);
    }

    private function update(Documents $documents, User $user, int $id, string $body, ?string $summary): Response
    {
        $document = AiDocument::query()->visibleTo($user)->find($id);

        if (! $document instanceof AiDocument) {
            return Response::error("No encontré el documento {$id}.");
        }

        if (Gate::forUser($user)->denies('update', $document)) {
            return Response::error('No tienes permiso para editar ese documento.');
        }

        $document = DB::transaction(function () use ($documents, $document, $body, $summary, $user): AiDocument {
            $draft = $documents->saveDraft($document, $body, $user);

            return $documents->publish($draft, filled($summary) ? (string) $summary : self::DEFAULT_SUMMARY, $user);
        });

        $this->audit($document, $user, 'mcp_document_updated', "Documento {$document->title}: versión {$document->version} desde Claude", [
            'document_id' => $document->id,
            'version' => $document->version,
        ]);

        return $this->summary($document);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function create(Documents $documents, User $user, array $data): Response
    {
        $owner = match (true) {
            filled($data['proyecto'] ?? null) => $this->project((string) $data['proyecto']),
            filled($data['producto'] ?? null) => AiProduct::query()->visibleTo($user)->where('slug', trim((string) $data['producto']))->first()
                ?? $this->fail('No encontré el producto '.trim((string) $data['producto']).'.'),
            default => $this->fail('Indica el proyecto o un producto donde va el documento, o el documento_id para actualizar uno.'),
        };

        if (! filled($data['titulo'] ?? null)) {
            return Response::error('Escribe el título del documento.');
        }

        if (Gate::forUser($user)->denies('create', AiDocument::class)) {
            return Response::error('No tienes permiso para crear documentos.');
        }

        $document = $documents->create($owner, $data['plantilla'] ?? 'blank', (string) $data['titulo'], $user, $data['contenido']);

        $this->audit($document, $user, 'mcp_document_created', "Documento {$document->title} creado desde Claude", [
            'document_id' => $document->id,
            'owner' => $owner instanceof AiProduct ? $owner->slug : $owner->key,
        ]);

        return $this->summary($document);
    }

    private function summary(AiDocument $document): Response
    {
        return Response::json([
            'id' => $document->id,
            'titulo' => $document->title,
            'version' => $document->version,
            'url' => route('ai.docs.edit', $document->id),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'proyecto' => $schema->string()->description('Clave del proyecto dueño del documento nuevo, por ejemplo P-3.'),
            'producto' => $schema->string()->description('Identificador (slug) del producto dueño del documento nuevo, si no es de un proyecto.'),
            'documento_id' => $schema->integer()->min(1)->description('Número del documento a actualizar (publica una versión nueva).'),
            'titulo' => $schema->string()->max(160)->description('Título del documento nuevo.'),
            'plantilla' => $schema->string()->enum(DocumentTemplates::KEYS)->description('Tipo del documento nuevo (por defecto blank).'),
            'contenido' => $schema->string()->description('Contenido completo en Markdown.')->required(),
            'resumen' => $schema->string()->max(255)->description('Qué cambió (para la versión publicada al actualizar).'),
        ];
    }
}
