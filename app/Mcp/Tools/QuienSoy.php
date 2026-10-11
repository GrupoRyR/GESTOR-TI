<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Identity of the token owner: name, email, roles and company context.
 * Harmless on purpose (E1 proves identity end to end).
 */
#[Name('quien_soy')]
#[Description('Muestra con qué usuario del Gestor TI está conectado Claude: nombre, correo, roles y empresa.')]
#[IsReadOnly]
#[IsIdempotent]
class QuienSoy extends Tool
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('No hay un usuario autenticado.');
        }

        $company = $user->company;

        return Response::json([
            'nombre' => $user->name,
            'correo' => $user->email,
            'roles' => $user->getRoleNames()->values()->all(),
            'empresa' => $company === null ? null : ['id' => $company->id, 'nombre' => $company->name],
            've_todas_las_empresas' => $this->currentCompany->canViewAll($user),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
