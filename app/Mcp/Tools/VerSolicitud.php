<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\RequestDetail;
use App\Mcp\Tools\Concerns\InteractsWithGestor;
use App\Models\AiRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * One request with its documentation (App\Mcp\Support\RequestDetail).
 * Unknown, out-of-scope and forbidden keys all read "No encontré…".
 */
#[Name('ver_solicitud')]
#[Description('Muestra una solicitud del Gestor IA por su clave (GIA-n): problema, proceso actual, resultado esperado, criterios de aceptación, evaluación (tamaño y prioridad), proyecto y tareas vinculadas con sus enlaces de GitHub, comentarios públicos y la documentación en Markdown. Las notas internas y los costos solo aparecen si el usuario puede verlos en la aplicación. Nunca incluye la bóveda ni credenciales.')]
#[IsReadOnly]
#[IsIdempotent]
class VerSolicitud extends Tool
{
    use InteractsWithGestor;

    public function handle(Request $request, RequestDetail $detail): Response
    {
        $user = $this->actor($request);
        $data = $request->validate(
            ['clave' => ['required', 'string', 'regex:/^\s*GIA-\d+\s*$/i']],
            ['clave.*' => 'Escribe la clave de la solicitud, por ejemplo GIA-139.'],
        );

        $key = strtoupper(trim($data['clave']));
        $found = AiRequest::query()->where('key', $key)->first();

        if (! $found instanceof AiRequest || Gate::forUser($user)->denies('view', $found)) {
            return Response::error("No encontré la solicitud {$key}.");
        }

        return Response::json($detail->build($found, $user));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'clave' => $schema->string()->description('Clave de la solicitud, por ejemplo GIA-139.')->required(),
        ];
    }
}
