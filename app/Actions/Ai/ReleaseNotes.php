<?php

namespace App\Actions\Ai;

use App\Enums\Ai\RequestStatus;
use App\Models\AiProductVersion;
use App\Models\AiRequest;
use App\Models\User;
use App\Notifications\AiReleasePublished;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * User notes of a GitHub release and "Avisar a N solicitantes"
 * (`ai.releases.notes.update`, `ai.releases.notify`, PROMPT 6.4.6, 6.4.8).
 *
 * - `save()`: stores the notes IT confirmed (the text as typed; an AI
 *   suggestion is never stored as-is). Refused once notified: the sent
 *   text stays what the requesters received.
 * - `notify()`: saves the notes, then, once per release (row lock +
 *   `notified_at`): moves every included request that is "En pruebas" to
 *   "En producción" through TransitionRequest (history, timestamps,
 *   rating requested) WITHOUT its own requester notice, and sends one
 *   AiReleasePublished per requester (deduplicated by user) with all of
 *   their requests. Requests in other statuses keep their status (moving
 *   to production stays a TI decision after the requester's validation);
 *   their requesters are still told the release is out.
 *
 * Both are audited (`ai` log) without the notes text.
 */
class ReleaseNotes
{
    public const MAX_LENGTH = 5000;

    public function __construct(private readonly TransitionRequest $transition) {}

    public function save(AiProductVersion $version, ?string $notes, ?int $suggestionId, User $actor): AiProductVersion
    {
        Gate::forUser($actor)->authorize('manageVersions', $version->product);

        DB::transaction(function () use ($version, $notes, $suggestionId, $actor): void {
            $locked = $this->lock($version);
            $text = $notes === null || trim($notes) === '' ? null : $notes;

            $locked->forceFill(['user_notes' => $text])->save();
            $this->audit($locked, 'ai_release_notes_updated', $actor, $suggestionId, "{$locked->tag}: notas para usuarios guardadas");
        });

        return $version->refresh();
    }

    /**
     * @return int requesters notified
     */
    public function notify(AiProductVersion $version, string $notes, ?int $suggestionId, User $actor): int
    {
        Gate::forUser($actor)->authorize('manageVersions', $version->product);

        if (trim($notes) === '') {
            throw ValidationException::withMessages(['user_notes' => 'Escribe las notas para los usuarios antes de avisar.']);
        }

        /** @var array<int, array{user: User, requests: list<AiRequest>}> $recipients */
        $recipients = DB::transaction(function () use ($version, $notes, $suggestionId, $actor): array {
            $locked = $this->lock($version);
            $requests = $locked->requests()->with('requester')->orderBy('ai_requests.id')->get();

            if ($requests->isEmpty()) {
                throw ValidationException::withMessages(['user_notes' => 'Esta versión no incluye solicitudes: no hay a quién avisar.']);
            }

            $locked->forceFill(['user_notes' => $notes, 'notified_at' => now()])->save();
            $note = "Publicada en {$locked->product?->name} {$locked->tag}.";

            foreach ($requests as $request) {
                if ($request->status === RequestStatus::InTesting) {
                    $this->transition->apply($request, RequestStatus::InProduction, $actor, $note, null, false);
                }
            }

            $recipients = $this->recipients($requests);
            $this->audit($locked, 'ai_release_notified', $actor, $suggestionId, "{$locked->tag}: aviso a ".count($recipients).' solicitante(s)', [
                'requests' => $requests->pluck('key')->all(),
                'requesters' => count($recipients),
            ]);

            return $recipients;
        });

        $version->refresh();

        foreach ($recipients as ['user' => $user, 'requests' => $requests]) {
            $user->notify(AiReleasePublished::for($version, $user, $requests, $notes));
        }

        return count($recipients);
    }

    /**
     * Locked fresh row; refuses any change once requesters were notified.
     */
    private function lock(AiProductVersion $version): AiProductVersion
    {
        /** @var AiProductVersion $locked */
        $locked = AiProductVersion::query()->with('product')->lockForUpdate()->findOrFail($version->id);

        if ($locked->notified_at !== null) {
            throw ValidationException::withMessages(['user_notes' => 'Ya se avisó a los solicitantes de esta versión.']);
        }

        return $locked;
    }

    /**
     * One entry per requester, first seen first (lib/releaseNotice.ts
     * `noticeRecipients`), with their requests.
     *
     * @param  Collection<int, AiRequest>  $requests
     * @return array<int, array{user: User, requests: list<AiRequest>}>
     */
    private function recipients(Collection $requests): array
    {
        $recipients = [];

        foreach ($requests as $request) {
            if ($request->requester === null) {
                continue;
            }

            $recipients[$request->requester->id] ??= ['user' => $request->requester, 'requests' => []];
            $recipients[$request->requester->id]['requests'][] = $request;
        }

        return $recipients;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(AiProductVersion $version, string $event, User $actor, ?int $suggestionId, string $description, array $extra = []): void
    {
        activity('ai')
            ->performedOn($version)
            ->causedBy($actor)
            ->event($event)
            ->withProperties([
                'product' => $version->product?->slug,
                'tag' => $version->tag,
                'notes_length' => mb_strlen((string) $version->user_notes),
                'suggestion_id' => $suggestionId,
                ...$extra,
            ])
            ->log($description);
    }
}
