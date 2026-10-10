<?php

namespace Tests\Unit\GitHub;

use App\Domain\GitHub\GiaKeys;
use PHPUnit\Framework\TestCase;

/**
 * Mirrors resources/js/lib/githubKeys.test.ts: both sides must find the same
 * request keys in GitHub titles, bodies and branch names.
 */
class GiaKeysTest extends TestCase
{
    public function test_it_finds_the_key_in_a_pr_title(): void
    {
        $this->assertSame(['GIA-139'], GiaKeys::extract(title: 'GIA-139 endpoint de zonas por asesor'));
    }

    public function test_it_finds_the_key_in_the_body_and_in_the_branch_case_insensitively(): void
    {
        $this->assertSame(['GIA-123'], GiaKeys::extract(body: 'Cierra gia-123 y revisa el filtro'));
        $this->assertSame(['GIA-123'], GiaKeys::extract(branch: 'gia-123-arreglar-filtro'));
        $this->assertSame(['GIA-77'], GiaKeys::extract(branch: 'feature/GIA-77-login'));
    }

    public function test_it_dedupes_across_sources_and_keeps_first_seen_order(): void
    {
        $this->assertSame(['GIA-139', 'GIA-112'], GiaKeys::extract(
            title: 'GIA-139 · T3 endpoint',
            body: 'Relacionado con GIA-112. Cierra gia-139.',
            branch: 'gia-139-endpoint-zonas',
        ));
    }

    public function test_it_keeps_zero_padding_as_written(): void
    {
        $this->assertSame(['GIA-095'], GiaKeys::extract(title: 'GIA-095 entrada con Microsoft'));
    }

    public function test_it_ignores_look_alikes_and_empty_sources(): void
    {
        $this->assertSame([], GiaKeys::extract(title: 'MEGIA-12 no cuenta', body: 'gia123 tampoco, ni GIA- ni xgia-5'));
        $this->assertSame([], GiaKeys::extract(title: null, body: null, branch: ''));
    }

    public function test_it_stops_at_the_digits(): void
    {
        $this->assertSame([], GiaKeys::extract(branch: 'gia-12a-prueba'));
        $this->assertSame(['GIA-148', 'GIA-150'], GiaKeys::extract(title: '(GIA-148), GIA-150.'));
    }

    public function test_the_suggested_branch_is_always_found_again(): void
    {
        // suggestedBranch('GIA-139', { key: 'T4' }, 'Consulta de cartera') in lib/githubKeys.ts.
        $this->assertSame(['GIA-139'], GiaKeys::extract(branch: 'gia-139-t4-consulta-de-cartera'));
    }

    public function test_normalize_upper_cases_a_valid_key_and_refuses_anything_else(): void
    {
        $this->assertSame('GIA-139', GiaKeys::normalize('gia-139'));
        $this->assertSame('GIA-7', GiaKeys::normalize(' GIA-7 '));
        $this->assertNull(GiaKeys::normalize('GIA139'));
    }
}
