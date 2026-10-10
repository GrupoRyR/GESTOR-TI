<?php

namespace Tests\Unit\Ai;

use App\Domain\Ai\Highlighter;
use App\Domain\Ai\LineDiff;
use App\Domain\Ai\SecretGuard;
use PHPUnit\Framework\TestCase;

/**
 * Pure text helpers of the documentation and the search: the line diff of
 * `DocCompare.lines`, the secrets guard and the UTF-16 highlight ranges.
 */
class KnowledgeTextTest extends TestCase
{
    public function test_line_diff_marks_same_removed_and_added_lines(): void
    {
        $this->assertSame([
            ['kind' => 'same', 'old_line' => 1, 'new_line' => 1, 'text' => '# Manual'],
            ['kind' => 'remove', 'old_line' => 2, 'new_line' => null, 'text' => 'viejo'],
            ['kind' => 'add', 'old_line' => null, 'new_line' => 2, 'text' => 'nuevo'],
            ['kind' => 'same', 'old_line' => 3, 'new_line' => 3, 'text' => 'fin'],
            ['kind' => 'add', 'old_line' => null, 'new_line' => 4, 'text' => 'extra'],
        ], LineDiff::lines("# Manual\nviejo\nfin\n", "# Manual\nnuevo\nfin\nextra"));

        $this->assertSame([], LineDiff::lines('', ''));
        $this->assertSame([['kind' => 'add', 'old_line' => null, 'new_line' => 1, 'text' => 'a']], LineDiff::lines('', 'a'));
        $this->assertSame([['kind' => 'same', 'old_line' => 1, 'new_line' => 1, 'text' => 'a']], LineDiff::lines("a\r\n", 'a'));
    }

    public function test_secret_guard_finds_obvious_secrets_only(): void
    {
        $guard = new SecretGuard;

        $this->assertNotNull($guard->find('PASSWORD=abc123'));
        $this->assertNotNull($guard->find('contraseña: Segura2026'));
        $this->assertNotNull($guard->find('"api_key": "sk-1234567890abcdef"'));
        $this->assertNotNull($guard->find('-----BEGIN OPENSSH PRIVATE KEY-----'));
        $this->assertNotNull($guard->find('AKIAIOSFODNN7EXAMPLE'));
        $this->assertNotNull($guard->find('token sk-abcdefghijklmnopqrstuvwx'));

        $this->assertNull($guard->find('Nunca escribas contraseñas aquí: van en la bóveda.'));
        $this->assertNull($guard->find('La variable `DEEPSEEK_API_KEY` vive en el .env.'));
        $this->assertNull($guard->find('El campo password del formulario se valida en el servidor.'));
    }

    public function test_highlights_are_whole_word_prefixes_in_utf16_offsets(): void
    {
        $this->assertSame([[0, 7], [12, 18]], Highlighter::ranges('Cartera por asesores', ['cartera', 'asesor']));
        // Accents and case do not matter; "Gestión" keeps its length.
        $this->assertSame([[4, 11]], Highlighter::ranges('Más gestión', ['gestion']));
        // Inside a word is not a match.
        $this->assertSame([], Highlighter::ranges('Subcartera', ['cartera']));
        // An emoji takes two UTF-16 units.
        $this->assertSame([[3, 10]], Highlighter::ranges('😀 cartera', ['cartera']));
    }
}
