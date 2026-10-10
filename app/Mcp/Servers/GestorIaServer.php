<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\QuienSoy;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Gestor IA connector for Claude (Stage E, PROMPT §6.4.7 step 3), served at
 * /mcp/gestor-ia (routes/ai.php) as plain JSON per POST: no tool streams,
 * so it runs on shared hosting.
 *
 * Every request is authenticated with the user's own OAuth token and
 * checked for `ai.mcp.use` (App\Http\Middleware\EnsureMcpAccess). E1 only
 * proves identity (`quien_soy`); E2 adds the Gestor IA tools.
 */
#[Name('Gestor IA')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
    Conector del Gestor IA del Grupo Empresarial RYR (Segurex Latam, Soluciones y Derivados de Colombia).
    El Gestor IA administra las solicitudes de proyectos de inteligencia artificial, sus tareas, avances y documentación.
    Actúas en nombre de la persona que autorizó el conector y solo ves lo que ella ve en la aplicación (empresa, permisos y políticas).
    Usa `quien_soy` para confirmar con qué usuario, roles y empresa estás conectado.
    Responde siempre en español.
    MARKDOWN)]
class GestorIaServer extends Server
{
    protected array $tools = [
        QuienSoy::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
