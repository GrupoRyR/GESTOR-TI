<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\ReleaseNotes;
use App\Domain\GitHub\ReleaseDetail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ReleaseNotesRequest;
use App\Models\AiProduct;
use App\Models\AiProductVersion;
use App\Models\User;
use App\Support\Ai\AiSuggestionAvailability;
use App\Support\Ai\Presenters\SuggestionPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Release page (Stage D4, types/pages/ai-github.ts `ReleasesShowProps`),
 * `ai.manage` + AiProductPolicy. `{release}` is the tag of a version of
 * that product (404 otherwise).
 *
 * - `ai.releases.show`: the release, its included requests and the notice
 *   preview data. `suggestion`: the latest `release_notes` suggestion
 *   (Stage D6, drafted when the GitHub release arrived; null when the AI
 *   is off or none exists); `ai` / `ai_usage` from AiSuggestionAvailability.
 * - `ai.releases.notes.update`: "Guardar notas" (App\Actions\Ai\ReleaseNotes::save).
 * - `ai.releases.notify`: "Avisar a N solicitantes" (ReleaseNotes::notify).
 */
class ReleaseController extends Controller
{
    public function __construct(
        private readonly ReleaseDetail $detail,
        private readonly AiSuggestionAvailability $ai,
        private readonly SuggestionPresenter $suggestions,
    ) {}

    public function show(Request $http, AiProduct $product, string $release): Response
    {
        $user = $this->user($http);
        Gate::authorize('view', $product);
        $version = $this->version($product, $release);

        return Inertia::render('Ai/Releases/Show', [
            'product' => [
                'slug' => $product->slug,
                'name' => $product->name,
                'company_id' => (int) $product->primaryCompany()?->id,
            ],
            'release' => $this->detail->for($version),
            'suggestion' => $this->suggestions->releaseNotes($version, $user),
            'ai' => $this->ai->for($user),
            'ai_usage' => $this->ai->usage($user),
            'now' => now()->toIso8601String(),
        ]);
    }

    public function update(ReleaseNotesRequest $http, AiProduct $product, string $release, ReleaseNotes $notes): RedirectResponse
    {
        $version = $this->version($product, $release);
        $notes->save($version, $http->notes(), $http->suggestionId(), $this->user($http));

        return redirect()->route('ai.releases.show', ['product' => $product, 'release' => $version->tag])
            ->with('success', 'Notas guardadas.');
    }

    public function notify(ReleaseNotesRequest $http, AiProduct $product, string $release, ReleaseNotes $notes): RedirectResponse
    {
        $version = $this->version($product, $release);
        $count = $notes->notify($version, (string) $http->notes(), $http->suggestionId(), $this->user($http));

        return redirect()->route('ai.releases.show', ['product' => $product, 'release' => $version->tag])
            ->with('success', $count === 1 ? 'Se avisó a 1 solicitante.' : "Se avisó a {$count} solicitantes.");
    }

    private function user(Request $http): User
    {
        /** @var User $user */
        $user = $http->user();

        return $user;
    }

    private function version(AiProduct $product, string $tag): AiProductVersion
    {
        /** @var AiProductVersion $version */
        $version = $product->versions()->where('tag', $tag)->firstOrFail();
        $version->setRelation('product', $product);

        return $version;
    }
}
