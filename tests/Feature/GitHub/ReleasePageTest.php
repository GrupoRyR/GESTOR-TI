<?php

namespace Tests\Feature\GitHub;

use App\Enums\Ai\ProductVersionSource;
use App\Enums\Ai\RequestStatus;
use App\Models\AiInteraction;
use App\Models\AiProduct;
use App\Models\AiProductVersion;
use App\Models\AiRequest;
use App\Models\GitHubRepository;
use App\Models\TelegramLink;
use App\Notifications\AiRatingRequested;
use App\Notifications\AiReleasePublished;
use App\Notifications\AiRequestStatusChanged;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Ai\Knowledge\KnowledgeTestCase;

/**
 * Release page `Ai/Releases/Show` (types/pages/ai-github.ts), the user
 * notes and "Avisar a N solicitantes" (`ai.releases.notify`).
 */
class ReleasePageTest extends KnowledgeTestCase
{
    use BuildsGitHubWork;

    private const TECHNICAL = "## Cambios\n- feat: buscador por referencia (#22)\n- fix: costo del servidor US\$ 40";

    private const NOTES = "Ahora puedes encontrar un producto escribiendo su referencia en el buscador.\n\nLos resultados aparecen mientras escribes.";

    private AiProduct $portafolio;

    private GitHubRepository $repo;

    private AiProductVersion $release;

    private AiRequest $gia118;

    private AiRequest $gia119;

    private AiRequest $gia120;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(16, 10));
        $this->connectGitHub();
        config(['services.deepseek.api_key' => null]);

        $this->segurex->forceFill(['short_name' => 'Segurex'])->save();
        $this->derivados->forceFill(['short_name' => 'Derivados'])->save();
        $this->jhon->forceFill(['github_login' => 'jhonc'])->save();

        $this->portafolio = $this->product(['slug' => 'portafolio', 'name' => 'Portafolio']);
        $this->repo = $this->githubRepo('portafolio', ['github_id' => 501]);
        $this->repo->linkTo($this->portafolio, 'main', $this->jhon);

        $this->version('1.8.1', 'v1.8.1', '2026-09-20 10:00:00');
        $this->release = $this->version('1.8.2', 'v1.8.2', '2026-10-02 15:58:00', [
            'summary' => 'Buscador por referencia',
            'technical_notes' => self::TECHNICAL,
            'author_login' => 'jhonc',
        ]);

        $this->gia118 = $this->request(['key' => 'GIA-118', 'title' => 'Buscador por referencia', 'status' => RequestStatus::InTesting]);
        $this->gia119 = $this->request(['key' => 'GIA-119', 'title' => 'Filtro por marca', 'status' => RequestStatus::InTesting]);
        $this->gia120 = $this->request([
            'key' => 'GIA-120', 'title' => 'Exportar a Excel', 'status' => RequestStatus::InProduction,
            'company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id,
            'requester_id' => $this->derivadosUser->id, 'created_by_id' => $this->derivadosUser->id,
        ]);

        $this->release->requests()->attach([
            $this->gia118->id => ['found_in_pr' => 22],
            $this->gia119->id => ['found_in_pr' => 23],
            $this->gia120->id => ['found_in_pr' => null],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function version(string $name, string $tag, string $publishedAt, array $attributes = []): AiProductVersion
    {
        return AiProductVersion::factory()->create([
            'ai_product_id' => $this->portafolio->id,
            'version' => $name,
            'tag' => $tag,
            'summary' => "Versión {$tag}",
            'source' => ProductVersionSource::GithubRelease,
            'published_at' => $publishedAt,
            'released_at' => substr($publishedAt, 0, 10),
            'release_url' => "https://github.com/GrupoRyR/portafolio/releases/tag/{$tag}",
            'github_repository_id' => $this->repo->id,
            ...$attributes,
        ]);
    }

    /**
     * @return array{product: string, release: string}
     */
    private function params(?AiProductVersion $version = null): array
    {
        return ['product' => 'portafolio', 'release' => ($version ?? $this->release)->tag];
    }

    public function test_release_page_has_the_release_and_its_included_requests(): void
    {
        $this->actingAs($this->jhon)
            ->get(route('ai.releases.show', $this->params()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ai/Releases/Show')
                ->where('product', ['slug' => 'portafolio', 'name' => 'Portafolio', 'company_id' => $this->segurex->id])
                ->where('release.tag', 'v1.8.2')
                ->where('release.summary', 'Buscador por referencia')
                ->where('release.published_at', '2026-10-02T15:58:00-05:00')
                ->where('release.request_keys', ['GIA-118', 'GIA-119', 'GIA-120'])
                ->where('release.notified_count', 0)
                ->where('release.url', 'https://github.com/GrupoRyR/portafolio/releases/tag/v1.8.2')
                ->where('release.repo', 'GrupoRyR/portafolio')
                ->where('release.author', ['name' => 'Jhon Castro', 'login' => '@jhonc'])
                ->has('release.included', 3)
                ->where('release.included.0', [
                    'key' => 'GIA-118',
                    'title' => 'Buscador por referencia',
                    'status' => 'in_testing',
                    'requester' => ['name' => 'Paola Ríos', 'company' => 'Segurex', 'area' => 'Ventas'],
                    'found_in_pr' => 22,
                ])
                ->where('release.included.2', [
                    'key' => 'GIA-120',
                    'title' => 'Exportar a Excel',
                    'status' => 'in_production',
                    'requester' => ['name' => 'Diego Restrepo', 'company' => 'Derivados', 'area' => 'Ventas'],
                    'found_in_pr' => null,
                ])
                ->where('release.technical_notes', self::TECHNICAL)
                ->where('release.user_notes', null)
                ->where('release.version_created', true)
                ->where('release.notified_at', null)
                ->where('release.compare', [
                    'label' => 'v1.8.1...v1.8.2',
                    'url' => 'https://github.com/GrupoRyR/portafolio/compare/v1.8.1...v1.8.2',
                ])
                ->where('suggestion', null)
                ->where('ai', 'off_no_key')
                ->where('ai_usage', null)
                ->where('now', '2026-10-02T16:10:00-05:00')
                ->etc());
    }

    public function test_first_release_has_no_compare_and_unknown_author_shows_the_login(): void
    {
        $first = AiProductVersion::query()->where('tag', 'v1.8.1')->firstOrFail();
        $first->forceFill(['author_login' => 'otra-persona'])->save();

        $this->actingAs($this->jhon)
            ->get(route('ai.releases.show', $this->params($first)))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('release.compare', null)
                ->where('release.author', ['name' => 'otra-persona', 'login' => '@otra-persona'])
                ->where('release.included', [])
                ->where('release.technical_notes', ''));
    }

    public function test_release_page_needs_ai_manage_and_an_existing_release_of_that_product(): void
    {
        $this->actingAs($this->paola)->get(route('ai.releases.show', $this->params()))->assertForbidden();
        $this->actingAs($this->paola)->put(route('ai.releases.notes.update', $this->params()), ['user_notes' => 'x'])->assertForbidden();
        $this->actingAs($this->paola)->post(route('ai.releases.notify', $this->params()), ['user_notes' => 'x'])->assertForbidden();

        $this->actingAs($this->jhon)->get(route('ai.releases.show', ['product' => 'portafolio', 'release' => 'v9.9.9']))->assertNotFound();

        $other = $this->product(['slug' => 'otro', 'name' => 'Otro'], $this->derivados);
        $this->actingAs($this->jhon)->get(route('ai.releases.show', ['product' => $other->slug, 'release' => 'v1.8.2']))->assertNotFound();
    }

    public function test_ai_availability_follows_the_provider_key_and_the_monthly_cap(): void
    {
        config(['services.deepseek.api_key' => 'sk-test']);

        $this->actingAs($this->jhon)->get(route('ai.releases.show', $this->params()))
            ->assertInertia(fn (Assert $page) => $page->where('ai', 'ready')->where('ai_usage', null));

        // Default cap (US$ 10, confirmed by Jhon): whole amounts without decimals.
        AiInteraction::query()->create(['provider' => 'deepseek', 'model' => 'deepseek-chat', 'feature' => 'assistant', 'status' => 'ok', 'cost' => '10.5']);

        $this->actingAs($this->jhon)->get(route('ai.releases.show', $this->params()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('ai', 'off_cap')
                ->where('ai_usage', ['spent_label' => 'US$ 10,50', 'cap_label' => 'US$ 10', 'resumes_on' => '1 de noviembre']));
    }

    public function test_saving_the_user_notes_keeps_them_and_audits_without_notifying(): void
    {
        $this->actingAs($this->jhon)
            ->from(route('ai.releases.show', $this->params()))
            ->put(route('ai.releases.notes.update', $this->params()), ['user_notes' => self::NOTES, 'suggestion_id' => null])
            ->assertRedirect(route('ai.releases.show', $this->params()))
            ->assertSessionHas('success', 'Notas guardadas.');

        $this->release->refresh();
        $this->assertSame(self::NOTES, $this->release->user_notes);
        $this->assertNull($this->release->notified_at);
        $this->assertSame(RequestStatus::InTesting, $this->gia118->fresh()->status);
        Notification::assertNothingSent();

        $audit = Activity::query()->where('event', 'ai_release_notes_updated')->sole();
        $this->assertSame($this->jhon->id, $audit->causer_id);
        $this->assertSame('v1.8.2', $audit->properties['tag']);

        $this->actingAs($this->jhon)
            ->put(route('ai.releases.notes.update', $this->params()), ['user_notes' => str_repeat('a', 5001)])
            ->assertSessionHasErrors('user_notes');
        $this->assertSame(self::NOTES, $this->release->fresh()->user_notes);
    }

    public function test_notify_sends_one_notice_per_requester_with_only_the_confirmed_notes(): void
    {
        $this->actingAs($this->jhon)
            ->from(route('ai.releases.show', $this->params()))
            ->post(route('ai.releases.notify', $this->params()), ['user_notes' => self::NOTES, 'suggestion_id' => null])
            ->assertRedirect(route('ai.releases.show', $this->params()))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Se avisó a 2 solicitantes.');

        Notification::assertSentToTimes($this->paola, AiReleasePublished::class, 1);
        Notification::assertSentToTimes($this->derivadosUser, AiReleasePublished::class, 1);
        Notification::assertNotSentTo([$this->paola, $this->derivadosUser], AiRatingRequested::class);
        Notification::assertNotSentTo([$this->paola, $this->derivadosUser], AiRequestStatusChanged::class);
        Notification::assertCount(2);

        Notification::assertSentTo($this->paola, AiReleasePublished::class, function (AiReleasePublished $notice): bool {
            $this->assertSame('Portafolio v1.8.2 · Ya está disponible', $notice->title());
            $this->assertSame(
                "Hola, Paola. Ya está disponible lo que pediste: Buscador por referencia (GIA-118) y Filtro por marca (GIA-119), en Portafolio v1.8.2.\n\n"
                .self::NOTES."\n\n¿Cómo te fue? Califica la entrega en un minuto.",
                $notice->body(),
            );
            $this->assertStringNotContainsString('feat:', $notice->body());
            $this->assertStringNotContainsString('US$', $notice->body());
            $this->assertSame(route('ai.ratings.create', 'GIA-118'), $notice->actionUrl());

            return true;
        });

        $this->release->refresh();
        $this->assertSame(self::NOTES, $this->release->user_notes);
        $this->assertNotNull($this->release->notified_at);

        foreach ([$this->gia118, $this->gia119] as $request) {
            $request->refresh();
            $this->assertSame(RequestStatus::InProduction, $request->status);
            $this->assertNotNull($request->rating_requested_at);
            $this->assertSame('Publicada en Portafolio v1.8.2.', $request->statusChanges()->latest('id')->first()->note);
        }
        $this->assertSame(RequestStatus::InProduction, $this->gia120->fresh()->status);
        $this->assertSame(0, $this->gia120->statusChanges()->count());
        $this->assertSame(1, Activity::query()->where('event', 'ai_release_notified')->count());

        $this->actingAs($this->jhon)->get(route('ai.releases.show', $this->params()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('release.notified_count', 2)
                ->where('release.notified_at', '2026-10-02T16:10:00-05:00')
                ->where('release.included.0.status', 'in_production'));

        $this->actingAs($this->jhon)->get(route('ai.products.show', $this->portafolio))
            ->assertInertia(fn (Assert $page) => $page->where('github.releases.0.notified_count', 2));
    }

    public function test_notify_is_refused_a_second_time_and_notes_cannot_change_after_it(): void
    {
        $this->actingAs($this->jhon)->post(route('ai.releases.notify', $this->params()), ['user_notes' => self::NOTES])->assertSessionHasNoErrors();
        Notification::assertCount(2);

        $this->actingAs($this->jhon)
            ->post(route('ai.releases.notify', $this->params()), ['user_notes' => 'Otro texto'])
            ->assertSessionHasErrors(['user_notes' => 'Ya se avisó a los solicitantes de esta versión.']);
        $this->actingAs($this->jhon)
            ->put(route('ai.releases.notes.update', $this->params()), ['user_notes' => 'Otro texto'])
            ->assertSessionHasErrors(['user_notes' => 'Ya se avisó a los solicitantes de esta versión.']);

        Notification::assertCount(2);
        $this->assertSame(self::NOTES, $this->release->fresh()->user_notes);
    }

    public function test_notify_needs_user_notes_and_included_requests(): void
    {
        $this->actingAs($this->jhon)
            ->post(route('ai.releases.notify', $this->params()), ['user_notes' => '   ', 'suggestion_id' => null])
            ->assertSessionHasErrors(['user_notes' => 'Escribe las notas para los usuarios antes de avisar.']);

        $first = AiProductVersion::query()->where('tag', 'v1.8.1')->firstOrFail();
        $this->actingAs($this->jhon)
            ->post(route('ai.releases.notify', $this->params($first)), ['user_notes' => self::NOTES])
            ->assertSessionHasErrors(['user_notes' => 'Esta versión no incluye solicitudes: no hay a quién avisar.']);

        Notification::assertNothingSent();
        $this->assertNull($this->release->fresh()->notified_at);
        $this->assertNull($first->fresh()->notified_at);
        $this->assertSame(RequestStatus::InTesting, $this->gia118->fresh()->status);
    }

    public function test_release_notice_goes_by_telegram_and_the_bell_per_preferences(): void
    {
        $this->assertSame(['telegram', 'database'], config('notifications.events')['ai.release.published']['channels']);

        $notice = AiReleasePublished::for($this->release->load('product'), $this->paola, [$this->gia118], self::NOTES);
        $this->assertSame(['database'], $notice->via($this->paola));

        TelegramLink::query()->create(['user_id' => $this->paola->id, 'chat_id' => '777', 'linked_at' => now()]);
        $this->assertSame(['telegram', 'database'], $notice->via($this->paola->fresh()));

        $this->assertSame('ai.release.published', $notice->toArray($this->paola)['event']);
    }
}
