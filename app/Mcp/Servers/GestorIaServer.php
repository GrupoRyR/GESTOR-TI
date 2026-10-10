<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ActualizarTarea;
use App\Mcp\Tools\AdjuntarDocumento;
use App\Mcp\Tools\AgregarNota;
use App\Mcp\Tools\BuscarSolicitudes;
use App\Mcp\Tools\CrearTarea;
use App\Mcp\Tools\QuienSoy;
use App\Mcp\Tools\VerSolicitud;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Gestor IA connector for Claude (Stage E, PROMPT §6.4.7 step 3), served at
 * /mcp (routes/ai.php) as plain JSON per POST: no tool streams, so it
 * runs on shared hosting.
 *
 * Every request is authenticated with the user's own personal token
 * (`read` lists only the read tools) or OAuth token (AuthenticateMcp),
 * audited per `tools/call` (AuditMcpCalls) and checked for `ai.mcp.use`
 * (EnsureMcpAccess), which also stamps writes with audit origin `api`. Tools reuse the app's
 * policies, actions and company scope (App\Mcp\Tools).
 */
#[Name('Gestor IA')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
    Conector del Gestor IA del Grupo Empresarial RYR (Segurex Latam, Soluciones y Derivados de Colombia).
    El Gestor IA administra las solicitudes de proyectos de inteligencia artificial (claves GIA-n), sus proyectos (P-n), tareas (T-n), avances y documentación.
    Actúas en nombre de la persona que autorizó el conector y solo ves lo que ella ve en la aplicación (empresa, permisos y políticas).

    Herramientas:
    - `quien_soy`: usuario, roles y empresa con los que estás conectado.
    - `buscar_solicitudes`: busca solicitudes por texto y filtros (estado, producto, área, empresa).
    - `ver_solicitud`: detalle de una solicitud con criterios, evaluación, proyecto, tareas y documentación.
    - `crear_tarea` y `actualizar_tarea`: tareas del tablero de un proyecto.
    - `agregar_nota`: nota de avance en un proyecto o una tarea.
    - `adjuntar_documento`: crea o actualiza documentación en Markdown (solo texto).
    Con un token de solo lectura únicamente están disponibles las herramientas de consulta.

    Nunca escribas contraseñas, llaves ni tokens: van en la bóveda y el conector los rechaza. La bóveda, las credenciales, las tarifas, los costos y los salarios no están disponibles por aquí.
    Responde siempre en español.
    MARKDOWN)]
class GestorIaServer extends Server
{
    protected array $tools = [
        QuienSoy::class,
        BuscarSolicitudes::class,
        VerSolicitud::class,
        CrearTarea::class,
        ActualizarTarea::class,
        AgregarNota::class,
        AdjuntarDocumento::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
