<?php

namespace App\Domain\Ai;

use InvalidArgumentException;

/**
 * Preloaded documentation templates (PROMPT 6.4.4). Keys mirror the
 * design's `DocTemplate` (resources/js/types/ai.ts). Each template has a
 * default title, a default visibility (`Doc.visibility`) and a Markdown
 * body; `{name}` is replaced by the project or product name and the
 * project placeholders by `projectBody()`.
 *
 * Functional documentation (user manual, delivery record) is visible to
 * the company; project working docs to the team; technical ones to IT.
 * Technical documentation lives in the repository (Stage D reads it).
 */
final class DocumentTemplates
{
    /** @var list<string> */
    public const KEYS = [
        'project_sheet', 'requirements', 'architecture', 'prompts', 'decisions',
        'user_manual', 'technical_manual', 'delivery_record', 'blank',
    ];

    /**
     * Documentation a new project starts with (PROMPT 6.4.3 "Plantilla de
     * proyecto nuevo"), in this order.
     *
     * @var list<string>
     */
    public const PROJECT_BASE = ['project_sheet', 'requirements', 'architecture', 'prompts', 'decisions', 'delivery_record'];

    /**
     * @var array<string, array{title: string, visibility: string}>
     */
    private const META = [
        'project_sheet' => ['title' => 'Ficha del proyecto', 'visibility' => 'project'],
        'requirements' => ['title' => 'Requerimientos y criterios', 'visibility' => 'project'],
        'architecture' => ['title' => 'Arquitectura y datos', 'visibility' => 'it'],
        'prompts' => ['title' => 'Prompts y modelos', 'visibility' => 'it'],
        'decisions' => ['title' => 'Registro de decisiones', 'visibility' => 'project'],
        'user_manual' => ['title' => 'Manual de usuario', 'visibility' => 'company'],
        'technical_manual' => ['title' => 'Manual técnico y de despliegue', 'visibility' => 'it'],
        'delivery_record' => ['title' => 'Acta de entrega', 'visibility' => 'company'],
        'blank' => ['title' => 'Documento en blanco', 'visibility' => 'it'],
    ];

    public static function exists(string $key): bool
    {
        return isset(self::META[$key]);
    }

    public static function title(string $key): string
    {
        return self::meta($key)['title'];
    }

    public static function visibility(string $key): string
    {
        return self::meta($key)['visibility'];
    }

    /**
     * Position of the template in KEYS (tree order).
     */
    public static function order(string $key): int
    {
        $index = array_search($key, self::KEYS, true);

        return $index === false ? count(self::KEYS) : $index;
    }

    /**
     * Markdown body for a project or product called `$name`.
     */
    public static function body(string $key, string $name): string
    {
        return str_replace("[ORIGEN]\n\n", '', self::raw($key, $name));
    }

    /**
     * Body for a new project: the generic template plus what the project
     * already knows (objective, origin request and its criteria).
     *
     * @param  list<string>  $criteria
     */
    public static function projectBody(string $key, string $name, ?string $objective, ?string $requestKey, array $criteria): string
    {
        $body = self::raw($key, $name);
        $objective = $objective !== null && trim($objective) !== '' ? trim($objective) : '[OBJETIVO]';
        $origin = $requestKey !== null ? "Nace de la solicitud {$requestKey}." : 'Proyecto creado directamente por TI.';
        $checklist = $criteria === []
            ? '- [ ] [CRITERIO]'
            : implode("\n", array_map(fn (string $criterion) => '- [ ] '.trim($criterion), $criteria));

        return str_replace(['[OBJETIVO]', '[ORIGEN]', '- [ ] [CRITERIO]'], [$objective, $origin, $checklist], $body);
    }

    /**
     * Body with the project placeholders still in it.
     */
    private static function raw(string $key, string $name): string
    {
        self::meta($key);

        return str_replace('{name}', $name, self::bodies()[$key]);
    }

    /**
     * @return array{title: string, visibility: string}
     */
    private static function meta(string $key): array
    {
        if (! isset(self::META[$key])) {
            throw new InvalidArgumentException("Unknown document template [{$key}].");
        }

        return self::META[$key];
    }

    /**
     * @return array<string, string>
     */
    private static function bodies(): array
    {
        return [
            'project_sheet' => <<<'MD'
                # Ficha del proyecto · {name}

                ## Objetivo
                [OBJETIVO]

                [ORIGEN]

                ## Alcance
                - Lo que sí incluye.

                ## Lo que queda fuera
                - Lo que no se hará en este proyecto.

                ## Equipo y áreas
                - Líder del proyecto:
                - Área que lo usa:

                ## Fechas
                - Inicio:
                - Entrega estimada:

                MD,
            'requirements' => <<<'MD'
                # Requerimientos y criterios · {name}

                [ORIGEN]

                ## Lo que necesita el área
                Describe con las palabras del área qué debe poder hacer.

                ## Criterios de aceptación
                - [ ] [CRITERIO]

                ## Datos y accesos que hay que pedir
                - Qué dato o acceso, a quién y para cuándo.

                MD,
            'architecture' => <<<'MD'
                # Arquitectura y datos · {name}

                > Nunca escribas contraseñas, llaves ni tokens en este documento: van en la bóveda del Gestor IA.

                ## Fuentes de datos
                - Sistema, tabla o servicio y qué se lee de cada uno.

                ## Accesos que necesita
                - Qué acceso, para qué y quién lo autorizó (sin el valor).

                ## Componentes
                - Dónde corre, con qué tecnología y cómo se conectan.

                ## Diagrama
                Enlace o descripción del flujo de datos.

                MD,
            'prompts' => <<<'MD'
                # Prompts y modelos · {name}

                ## Modelo
                - Proveedor y modelo:
                - Versión:
                - Costo estimado al mes:

                ## Prompt de sistema
                ```
                Pega aquí el prompt de sistema vigente.
                ```

                ## Cambios del prompt
                | Fecha | Cambio | Motivo |
                |---|---|---|
                |  |  |  |

                Si esta solución no usa IA, marca el documento como «No aplica».

                MD,
            'decisions' => <<<'MD'
                # Registro de decisiones · {name}

                | Fecha | Decisión | Motivo |
                |---|---|---|
                |  |  |  |

                MD,
            'user_manual' => <<<'MD'
                # Manual de usuario · {name}

                ## Para qué sirve
                Qué problema resuelve y quién la usa.

                ## Cómo entrar
                Dirección y con qué usuario se entra.

                ## Paso a paso
                1. Primer paso.
                2. Segundo paso.

                ## Preguntas frecuentes
                - ¿Qué hago si algo no funciona? Usa el botón «Pedir mejora» o escribe a TI.

                MD,
            'technical_manual' => <<<'MD'
                # Manual técnico y de despliegue · {name}

                > La documentación técnica vive en el repositorio (`README`, `CLAUDE.md`, `docs/`). Aquí va el resumen y los enlaces.

                ## Cómo correrlo
                Pasos para levantarlo en un equipo nuevo.

                ## Cómo se publica
                Dónde está publicado y cómo se despliega una versión nueva.

                ## Variables de entorno
                Solo los nombres; los valores están en la bóveda.

                MD,
            'delivery_record' => <<<'MD'
                # Acta de entrega · {name}

                [ORIGEN]

                ## Qué se entrega
                - Versión:
                - Fecha:

                ## Criterios revisados
                - [ ] [CRITERIO]

                ## Recibido por el área
                - Nombre:
                - Cargo:
                - Fecha:

                MD,
            'blank' => '',
        ];
    }
}
