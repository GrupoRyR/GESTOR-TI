<?php

namespace Tests\Feature\Ai\Suggestions;

use App\Domain\Suggestions\SuggestionGenerator;
use App\Enums\Ai\ProductVersionSource;
use App\Enums\Ai\RequestStatus;
use App\Jobs\GenerateSuggestions;
use App\Models\AiInteraction;
use App\Models\AiProduct;
use App\Models\AiProductVersion;
use App\Models\AiRequest;
use App\Models\AiSuggestion;
use App\Notifications\AiReleasePublished;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Ai\Knowledge\KnowledgeTestCase;
use Tests\Feature\GitHub\BuildsGitHubWork;

/**
 * The `release_notes` suggestion of `Ai/Releases/Show`: drafted when a
 * GitHub release arrives, regenerated on demand, credited by
 * `ai.releases.notes.update` / `ai.releases.notify` (`suggestion_id`) and
 * never sent to requesters as-is.
 */
class ReleaseNotesSuggestionTest extends KnowledgeTestCase
{
    use BuildsGitHubWork;
    use FakesAiProvider;

    private const TECHNICAL = "## Cambios\n- feat: buscador por referencia (#22)\n- fix: costo del servidor US\$ 40\n- chore: password=Sup3rS3creto99";

    private AiProduct $portafolio;

    private AiRequest $gia118;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.deepseek.api_key' => 'test-deepseek-key']);
        Http::preventStrayRequests();
        Notification::fake();
        $this->connectGitHub();

        $this->portafolio = $this->product(['slug' => 'portafolio', 'name' => 'Portafolio']);
        $this->gia118 = $this->request(['key' => 'GIA-118', 'title' => 'Buscador por referencia', 'status' => RequestStatus::InTesting]);
    }

    private function release(): AiProductVersion
    {
        Queue::fake();

        $version = AiProductVersion::factory()->create([
            'ai_product_id' => $this->portafolio->id,
            'version' => '1.8.2',
            'tag' => 'v1.8.2',
            'source' => ProductVersionSource::GithubRelease,
            'github_release_id' => 9001,
            'published_at' => now(),
            'technical_notes' => self::TECHNICAL,
        ]);
        $version->requests()->attach($this->gia118->id, ['found_in_pr' => 22]);

        return $version;
    }

    private function generate(): void
    {
        Queue::pushed(GenerateSuggestions::class)->last()->handle(app(SuggestionGenerator::class));
    }

    private function params(): array
    {
        return ['product' => 'portafolio', 'release' => 'v1.8.2'];
    }

    public function test_a_github_release_gets_a_draft_of_user_notes(): void
    {
        $version = $this->release();
        Queue::assertPushed(GenerateSuggestions::class, 1);
        $draft = AiSuggestion::query()->sole();
        $this->assertSame('release_notes', $draft->kind);
        $this->assertSame('generating', $draft->state);
        $this->assertTrue($draft->subject->is($version));
        $this->assertNull($draft->requested_by_id);

        $this->provider(['title' => 'Buscar por referencia', 'body' => 'Ahora puedes encontrar un producto escribiendo su referencia.']);
        $this->generate();

        $draft->refresh();
        $this->assertSame('ready', $draft->state);
        $this->assertSame('Buscar por referencia', $draft->payload['title']);
        $this->assertSame('Hecha con el título de GIA-118 y las notas técnicas del release. No usa costos ni notas internas.', $draft->payload['source_note']);
        $this->assertSame('suggestion.release_notes', AiInteraction::query()->sole()->feature);

        // Whitelist: titles and keys plus the technical notes without money or secrets.
        $prompt = $this->lastPrompt();
        $this->assertStringContainsString('GIA-118', $prompt);
        $this->assertStringContainsString('Buscador por referencia', $prompt);
        $this->assertStringContainsString('buscador por referencia (#22)', $prompt);
        $this->assertStringNotContainsString('40', $prompt);
        $this->assertStringNotContainsString('Sup3rS3creto99', $this->sentRaw());
        $this->assertStringNotContainsString($this->paola->name, $this->sentRaw());
        $this->assertStringNotContainsString((string) $this->paola->email, $this->sentRaw());

        // The page sends it; nothing is applied to the version.
        $this->actingAs($this->jhon)
            ->get(route('ai.releases.show', $this->params()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('suggestion.id', $draft->id)
                ->where('suggestion.kind', 'release_notes')
                ->where('suggestion.state', 'ready')
                ->where('suggestion.payload.body', 'Ahora puedes encontrar un producto escribiendo su referencia.')
                ->where('ai', 'ready')
            );
        $this->assertNull($version->fresh()->user_notes);
    }

    public function test_no_draft_without_a_key_and_the_page_hides_it_when_the_ai_is_off(): void
    {
        config(['services.deepseek.api_key' => null]);
        $this->release();
        Queue::assertNothingPushed();
        $this->assertSame(0, AiSuggestion::query()->count());

        config(['services.deepseek.api_key' => 'test-deepseek-key']);
        $version = AiProductVersion::query()->sole();
        AiSuggestion::factory()->for($version, 'subject')->create(['kind' => 'release_notes', 'state' => 'ready', 'payload' => ['title' => 't', 'body' => 'b', 'source_note' => 's']]);
        config(['services.deepseek.api_key' => null]);

        $this->actingAs($this->jhon)
            ->get(route('ai.releases.show', $this->params()))
            ->assertInertia(fn (Assert $page) => $page->where('suggestion', null)->where('ai', 'off_no_key'));
    }

    public function test_regenerate_discard_and_saved_notes_credit_the_suggestion(): void
    {
        $this->release();
        $this->provider(['title' => 'Uno', 'body' => 'Primera versión.'], ['title' => 'Dos', 'body' => 'Segunda versión.']);
        $this->generate();
        $first = AiSuggestion::query()->sole();

        $this->actingAs($this->jhon)->post(route('ai.suggestions.regenerate', $first))->assertRedirect()->assertSessionHasNoErrors();
        $this->generate();
        $second = AiSuggestion::query()->latest('id')->first();
        $this->assertSame('ready', $second->state);
        $this->assertNotNull($first->fresh()->superseded_at);

        $this->actingAs($this->jhon)
            ->put(route('ai.releases.notes.update', $this->params()), ['user_notes' => 'Segunda versión, editada.', 'suggestion_id' => $second->id])
            ->assertSessionHasNoErrors();
        $second->refresh();
        $this->assertSame('accepted', $second->state);
        $this->assertSame(['body' => 'Segunda versión, editada.'], $second->accepted_value);
        $this->assertSame($this->jhon->id, $second->accepted_by_id);

        // Only the confirmed notes reach the requester.
        $this->actingAs($this->jhon)
            ->post(route('ai.releases.notify', $this->params()), ['user_notes' => 'Segunda versión, editada.', 'suggestion_id' => $second->id])
            ->assertSessionHasNoErrors();
        Notification::assertSentTo($this->paola, AiReleasePublished::class, function (AiReleasePublished $notice): bool {
            return str_contains($notice->body(), 'Segunda versión, editada.') && ! str_contains($notice->body(), 'Segunda versión.');
        });

        // A suggestion of another release is refused.
        $this->actingAs($this->jhon)->post(route('ai.suggestions.discard', $first))->assertSessionHasNoErrors();
        $this->assertSame('discarded', $first->fresh()->state);
        $this->actingAs($this->paola)->post(route('ai.suggestions.regenerate', $second))->assertForbidden();
    }

    public function test_notes_with_a_foreign_suggestion_id_are_refused(): void
    {
        $this->release();
        $request = $this->request();
        $foreign = AiSuggestion::factory()->for($request, 'subject')->create(['kind' => 'summary', 'state' => 'ready', 'payload' => ['text' => 'x']]);

        $this->actingAs($this->jhon)
            ->put(route('ai.releases.notes.update', $this->params()), ['user_notes' => 'Notas', 'suggestion_id' => $foreign->id])
            ->assertSessionHasErrors('suggestion_id');
        $this->assertSame('ready', $foreign->fresh()->state);
    }
}
