<?php

namespace Tests\Feature\Assistant\Chat;

use App\Domain\Assistant\AiDraftParser;
use App\Domain\Assistant\DraftParser;
use App\Domain\Assistant\HeuristicDraftParser;
use App\Domain\Assistant\LearnDrafts;
use App\Models\AiInteraction;
use Illuminate\Support\Facades\Http;

/**
 * `/aprender` with the AI provider (C3): the model extracts type, title,
 * companies, area and fields as JSON; without a key, over the cap or on
 * any failure the C2 heuristic parser answers.
 */
class AiDraftParserTest extends ChatTestCase
{
    private const MESSAGE = 'El informe de cartera por asesor lo envía el Visualizador de cartera los lunes a las 7 a. m. a los asesores de Derivados';

    public function test_with_a_key_the_provider_extracts_the_card(): void
    {
        $this->assertInstanceOf(AiDraftParser::class, app(DraftParser::class));
        $this->provider($this->text(json_encode([
            'type' => 'report',
            'title' => 'Informe de cartera por asesor',
            'companies' => ['Derivados'],
            'area' => 'Ventas',
            'fields' => [
                'shows' => 'La cartera de cada asesor',
                'source_system' => 'Visualizador de cartera',
                'recipients' => 'Asesores de Derivados',
                'schedule' => 'Lunes 7 a. m.',
                'inventado' => 'no va',
            ],
        ], JSON_UNESCAPED_UNICODE)));

        $draft = app(LearnDrafts::class)->fromText($this->jhon, self::MESSAGE, 'app');

        $this->assertSame('report', $draft->proposed['type']);
        $this->assertSame('Informe de cartera por asesor', $draft->proposed['title']);
        $this->assertSame([$this->derivados->id], $draft->proposed['company_ids']);
        $this->assertSame('message', $draft->proposed['origins']['company']);
        $this->assertSame($this->derivadosVentas->id, $draft->proposed['area_id']);
        $this->assertSame('Visualizador de cartera', $draft->proposed['fields']['source_system']);
        $this->assertSame('La cartera de cada asesor', $draft->proposed['fields']['shows']);
        $this->assertArrayNotHasKey('inventado', $draft->proposed['fields']);
        $this->assertSame('it', $draft->proposed['visibility']);
        $this->assertSame(['owner'], $draft->missing);

        $this->assertSame('assistant_learn', AiInteraction::query()->sole()->feature);
        $body = $this->sentBodies()[0];
        $this->assertSame('system', $body['messages'][0]['role']);
        $this->assertSame(self::MESSAGE, $body['messages'][1]['content']);
        $this->assertArrayNotHasKey('tools', $body);
    }

    public function test_a_provider_failure_or_bad_json_falls_back_to_the_heuristic(): void
    {
        $this->provider(500, 500, $this->text('esto no es json'));

        $failed = app(LearnDrafts::class)->fromText($this->jhon, self::MESSAGE, 'app');
        $badJson = app(LearnDrafts::class)->fromText($this->jhon, self::MESSAGE, 'app');

        $heuristic = app(HeuristicDraftParser::class)->parse(self::MESSAGE, $this->jhon);
        foreach ([$failed, $badJson] as $draft) {
            $this->assertSame($heuristic['type'], $draft->proposed['type']);
            $this->assertSame($heuristic['title'], $draft->proposed['title']);
        }
    }

    public function test_without_a_key_the_heuristic_parser_is_bound(): void
    {
        config(['services.deepseek.api_key' => null]);
        Http::fake();

        $this->assertInstanceOf(HeuristicDraftParser::class, app(DraftParser::class));
        app(LearnDrafts::class)->fromText($this->jhon, self::MESSAGE, 'app');
        Http::assertNothingSent();
    }
}
