<?php

namespace Tests\Unit\Assistant;

use App\Domain\Assistant\Tools\PrivacyFilter;
use App\Domain\Assistant\Tools\SchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * What tools send to the AI provider (PROMPT 6.8.3, §8): the privacy filter
 * strips credentials, vault content, rates, salaries and costs; arguments
 * are checked against each tool's JSON schema.
 */
class ToolSafetyTest extends TestCase
{
    public function test_the_privacy_filter_strips_sensitive_keys_at_any_depth(): void
    {
        $clean = PrivacyFilter::clean([
            'key' => 'K-031',
            'title' => 'Informe',
            'password' => 'x1',
            'Secret' => 'x2',
            'api_token' => 'x3',
            'value' => 'x4',
            'secret_ciphertext' => 'x5',
            'monthly_cost' => '120.00',
            'hourly_rate' => 50000,
            'salary' => 1,
            'salario' => 1,
            'tarifa' => 1,
            'costo_mensual' => 1,
            'nested' => ['items' => [['name' => 'Office', 'password' => 'x6', 'cost' => 3, 'generate_report' => 'sí']]],
        ]);

        $this->assertSame([
            'key' => 'K-031',
            'title' => 'Informe',
            'nested' => ['items' => [['name' => 'Office', 'generate_report' => 'sí']]],
        ], $clean);
    }

    public function test_the_privacy_filter_masks_secrets_inside_text(): void
    {
        $clean = PrivacyFilter::clean(['description' => 'Entra con usuario admin y contraseña: Hola123*', 'title' => 'Sin secretos']);

        $this->assertStringNotContainsString('Hola123*', $clean['description']);
        $this->assertSame('Sin secretos', $clean['title']);
    }

    public function test_the_schema_validator_checks_required_types_enums_lengths_and_extra_keys(): void
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['texto'],
            'properties' => [
                'texto' => ['type' => 'string', 'maxLength' => 10],
                'tipo' => ['type' => 'string', 'enum' => ['report', 'system']],
                'limite' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'clave' => ['type' => 'string', 'pattern' => '^K-\d+$'],
            ],
        ];

        $this->assertSame([], SchemaValidator::errors(['texto' => 'hola', 'tipo' => 'report', 'limite' => 3, 'clave' => 'K-031'], $schema));
        $this->assertSame([
            'Falta «texto».',
            '«tipo» debe ser uno de: report, system.',
            '«limite» debe ser como máximo 5.',
            '«clave» no tiene el formato esperado.',
            '«otra» no es un argumento de esta herramienta.',
        ], SchemaValidator::errors(['tipo' => 'x', 'limite' => 9, 'clave' => 'abc', 'otra' => 1], $schema));
        $this->assertSame(['«texto» debe ser texto.'], SchemaValidator::errors(['texto' => 12], $schema));
        $this->assertSame(['«texto» admite máximo 10 caracteres.'], SchemaValidator::errors(['texto' => str_repeat('a', 11)], $schema));
        $this->assertSame(['«limite» debe ser un número entero.'], SchemaValidator::errors(['texto' => 'a', 'limite' => '2'], $schema));
    }
}
