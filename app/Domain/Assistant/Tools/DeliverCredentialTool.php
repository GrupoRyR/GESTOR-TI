<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\CredentialDelivery;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * `entregar_credencial` (PROMPT 6.8.2): the model says whose and which
 * system; App\Domain\Assistant\CredentialDelivery answers the person in the
 * chat by itself. The model hears only the outcome ("credencial entregada",
 * "no encontrada"…), never a value, a user name or a vault item.
 */
final class DeliverCredentialTool implements AssistantTool
{
    private const OUTCOMES = [
        'refused' => 'rechazada: esta persona no tiene permiso para recibir contraseñas. Ya se le dijo en el chat.',
        'delivered' => 'credencial entregada directamente a la persona, por aparte. No digas nada más sobre el valor.',
        'choose' => 'hay varias personas con ese nombre: se le pidió escoger con botones en el chat.',
        'vault' => 'esa credencial está en la bóveda: se le dio el enlace a la bóveda en el chat.',
        'not_found' => 'no encontrada: no hay un acceso activo con ese nombre y ese sistema.',
    ];

    public function __construct(private readonly CredentialDelivery $delivery) {}

    public function name(): string
    {
        return 'entregar_credencial';
    }

    public function description(): string
    {
        return 'Entrega una contraseña de un colaborador (o de un activo) a la persona que pregunta, directamente y sin pasar por ti. '
            .'Úsala cuando pidan una contraseña o un acceso, por ejemplo "la contraseña de Office de Juan Pérez". Solo te dice si se entregó.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'colaborador' => $schema->string()->description('Nombre del colaborador (o del activo) dueño de la credencial, por ejemplo "Juan Pérez".')->max(120)->required(),
            'sistema' => $schema->string()->description('Sistema de la credencial, por ejemplo "Office", "SAP" o "VPN".')->max(80)->required(),
            'tipo' => $schema->string()->description('colaborador (por defecto) o activo (equipos como un NVR o una impresora).')->enum(['colaborador', 'activo']),
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        [$outcome, $message] = $this->delivery->request(
            $user,
            $context->conversation,
            (string) $arguments['colaborador'],
            (string) $arguments['sistema'],
            (string) ($arguments['tipo'] ?? 'colaborador'),
        );

        if ($message !== null) {
            $context->answered($message);
        }

        return ToolResult::action(['resultado' => self::OUTCOMES[$outcome]]);
    }
}
