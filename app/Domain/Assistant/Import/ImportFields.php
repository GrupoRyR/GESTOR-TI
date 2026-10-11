<?php

namespace App\Domain\Assistant\Import;

use App\Domain\Assistant\KnowledgeTypes;
use Illuminate\Support\Str;

/**
 * Card fields an Excel column can feed, per sheet type: the server copy of
 * resources/js/lib/importMapping.ts (labels, required, header synonyms) so
 * the server repeats the match the page shows. Matching ignores accents,
 * case and punctuation.
 *
 * Server addition: the optional `key` column ("Clave", "K-031") lets a row
 * update that exact card; without it rows match cards by title.
 */
final class ImportFields
{
    /**
     * key => [label, required, synonyms].
     *
     * @var array<string, array{0: string, 1: bool, 2: list<string>}>
     */
    private const COMMON = [
        'title' => ['Título', true, ['titulo', 'nombre', 'nombre de la ficha', 'ficha']],
        'type' => ['Tipo de ficha (fijo)', false, ['tipo', 'tipo de ficha']],
        'company' => ['Empresa', true, ['empresa', 'compania', 'empresas']],
        'area' => ['Área', false, ['area', 'departamento', 'proceso']],
        'description' => ['Descripción', false, ['descripcion', 'detalle', 'resumen']],
        'visibility' => ['Visibilidad', true, ['visibilidad', 'quien la ve', 'alcance']],
        'owner' => ['Dueño (por nombre de usuario)', false, ['dueno', 'responsable', 'propietario']],
        'key' => ['Clave de la ficha (K-031)', false, ['clave', 'clave de la ficha', 'codigo', 'id', 'key']],
    ];

    /** @var array<string, array<string, array{0: string, 1: bool, 2: list<string>}>> */
    private const BY_TYPE = [
        'system' => [
            'what_is' => ['Qué es', false, ['que es', 'para que sirve']],
            'runs_on' => ['Dónde corre', false, ['donde corre', 'servidor', 'hosting', 'ubicacion']],
            'access' => ['Acceso', false, ['acceso', 'como entro', 'como se entra']],
            'version' => ['Versión', false, ['version']],
        ],
        'report' => [
            'shows' => ['Qué muestra', false, ['que muestra', 'contenido']],
            'source_system' => ['Sistema de origen', false, ['sistema de origen', 'sistema origen', 'origen', 'quien lo envia']],
            'recipients' => ['Quién lo recibe', false, ['quien lo recibe', 'destinatarios', 'destinatario', 'recibe', 'para']],
            'schedule' => ['Horario de envío', false, ['horario de envio', 'horario', 'hora', 'frecuencia']],
            'format' => ['Formato', false, ['formato', 'como llega']],
        ],
        'data_flow' => [
            'origin_destination' => ['Origen y destino', false, ['origen y destino', 'origen destino']],
            'transport' => ['Cómo viaja', false, ['como viaja', 'medio', 'integracion']],
            'frequency' => ['Frecuencia', false, ['frecuencia', 'cada cuanto']],
            'on_failure' => ['Qué pasa si falla', false, ['que pasa si falla', 'si falla', 'falla']],
        ],
        'procedure' => [
            'steps' => ['Pasos numerados', false, ['pasos', 'pasos numerados', 'procedimiento']],
            'who_can' => ['Quién lo puede hacer', false, ['quien lo puede hacer', 'quien lo hace', 'quien puede']],
        ],
        'glossary' => [
            'term' => ['Término', false, ['termino', 'palabra']],
            'definition' => ['Definición', false, ['definicion', 'significado']],
            'also_called' => ['También se dice', false, ['tambien se dice', 'sinonimos', 'sinonimo']],
        ],
    ];

    /** Spellings of each type in a "Tipo" column or a sheet name (normalized). */
    private const TYPE_NAMES = [
        'system' => ['sistema', 'sistemas'],
        'report' => ['informe', 'informes', 'reporte', 'reportes'],
        'data_flow' => ['flujo', 'flujos', 'flujo de datos', 'flujos de datos', 'integracion', 'integraciones'],
        'procedure' => ['procedimiento', 'procedimientos', 'pregunta frecuente', 'preguntas frecuentes'],
        'glossary' => ['glosario', 'termino', 'terminos'],
    ];

    /** "Informe", "Sistema"… (messages). */
    public const TYPE_LABELS = [
        'system' => 'Sistema',
        'report' => 'Informe',
        'data_flow' => 'Flujo de datos',
        'procedure' => 'Procedimiento',
        'glossary' => 'Glosario',
    ];

    /** Sheets of the template ("plantilla de importación"). */
    public const SHEET_NAMES = [
        'system' => 'Sistemas',
        'report' => 'Informes',
        'data_flow' => 'Flujos de datos',
        'procedure' => 'Procedimientos',
        'glossary' => 'Glosario',
    ];

    /** Type when neither a "Tipo" column nor the sheet name tells (the form's default). */
    public const DEFAULT_TYPE = 'report';

    /** Fields that are not type fields. */
    public const COMMON_KEYS = ['title', 'type', 'company', 'area', 'description', 'visibility', 'owner', 'key'];

    /**
     * Lowercase, no accents, no punctuation, single spaces.
     */
    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text))));
    }

    /**
     * `ImportField[]` of a sheet type (common ones first).
     *
     * @return list<array{key: string, label: string, required: bool}>
     */
    public static function fieldsFor(string $type): array
    {
        $fields = [];

        foreach (self::specs($type) as $key => [$label, $required]) {
            $fields[] = ['key' => $key, 'label' => $label, 'required' => $required];
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    public static function keys(string $type): array
    {
        return array_keys(self::specs($type));
    }

    public static function label(string $type, string $key): string
    {
        return self::specs($type)[$key][0] ?? $key;
    }

    /**
     * Required keys a mapping lacks.
     *
     * @param  list<?string>  $mapped
     * @return list<string>
     */
    public static function missingRequired(string $type, array $mapped): array
    {
        $missing = [];

        foreach (self::specs($type) as $key => [, $required]) {
            if ($required && ! in_array($key, $mapped, true)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Field each header matches by name (first come; unknown = null).
     *
     * @param  list<string>  $headers
     * @return list<?string>
     */
    public static function autoMap(array $headers, string $type): array
    {
        $specs = self::specs($type);
        $taken = [];
        $fields = [];

        foreach ($headers as $header) {
            $normalized = self::normalize($header);
            $found = null;

            foreach ($specs as $key => [$label, , $synonyms]) {
                if (! isset($taken[$key]) && (self::normalize($label) === $normalized || in_array($normalized, $synonyms, true))) {
                    $found = $key;
                    break;
                }
            }

            if ($found !== null) {
                $taken[$found] = true;
            }
            $fields[] = $found;
        }

        return $fields;
    }

    /**
     * `ImportMatchKind` of a column given the automatic field.
     */
    public static function matchKind(?string $field, ?string $automatic): string
    {
        return match (true) {
            $field === null => 'skip',
            $field === 'type' => 'fixed',
            $field === $automatic => 'auto',
            default => 'manual',
        };
    }

    /**
     * Type named by a "Tipo" cell or a sheet name, or null.
     */
    public static function typeFromName(string $name): ?string
    {
        $normalized = self::normalize($name);
        if ($normalized === '') {
            return null;
        }

        foreach (self::TYPE_NAMES as $type => $names) {
            if (in_array($normalized, $names, true)) {
                return $type;
            }
        }

        // Sheet names like "Informes 2026" or "Mapa de sistemas".
        foreach (self::TYPE_NAMES as $type => $names) {
            foreach ($names as $spelling) {
                if (preg_match('/\b'.preg_quote($spelling, '/').'\b/', $normalized) === 1) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: list<string>}>
     */
    private static function specs(string $type): array
    {
        $byType = self::BY_TYPE[$type] ?? [];

        // Keep the order of KnowledgeTypes::FIELDS for the type fields.
        $ordered = [];
        foreach (array_keys(KnowledgeTypes::fields($type)) as $key) {
            if (isset($byType[$key])) {
                $ordered[$key] = $byType[$key];
            }
        }

        return [...self::COMMON, ...$ordered];
    }
}
