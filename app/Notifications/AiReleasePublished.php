<?php

namespace App\Notifications;

use App\Models\AiProductVersion;
use App\Models\AiRequest;
use App\Models\User;
use App\Support\Mail\RequestEmails;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Route;

/**
 * "Ya está disponible lo que pediste" (PROMPT 6.4.6), event
 * `ai.release.published`: one notice per requester when IT notifies a
 * GitHub release from `Ai/Releases/Show`. Same paragraphs as
 * resources/js/lib/releaseNotice.ts `releaseNoticeMessage`: greeting with
 * the requester's requests, the user notes IT confirmed and the rating ask.
 * Never carries the technical release notes, costs or internal notes.
 *
 * It replaces the per-request "Ya está en producción" notice
 * (AiRatingRequested) for the requests the release moves, so a requester
 * gets one message per release.
 *
 * Mail: the `delivered` template for the requester's first request of the
 * release (all their titles in the heading, the confirmed user notes as
 * "Qué cambia para ti") with its signed one-click rating links.
 */
class AiReleasePublished extends GestorNotification
{
    public const CLOSING = '¿Cómo te fue? Califica la entrega en un minuto.';

    /**
     * @param  list<array{key: string, title: string}>  $requests
     */
    public function __construct(
        public readonly string $productName,
        public readonly string $tag,
        public readonly string $requesterName,
        public readonly array $requests,
        public readonly string $notes,
        public readonly ?int $versionId = null,
    ) {}

    /**
     * @param  iterable<AiRequest>  $requests  the requester's requests in the release
     */
    public static function for(AiProductVersion $version, User $requester, iterable $requests, string $notes): self
    {
        $list = [];
        $companyId = null;

        foreach ($requests as $request) {
            $list[] = ['key' => $request->key, 'title' => $request->title];
            $companyId ??= $request->company_id;
        }

        return (new self(
            (string) $version->product?->name,
            (string) ($version->tag ?? $version->version),
            $requester->name,
            $list,
            trim($notes),
            $version->id,
        ))->forCompany($companyId);
    }

    public function eventType(): string
    {
        return 'ai.release.published';
    }

    public function title(): string
    {
        return "{$this->productName} {$this->tag} · Ya está disponible";
    }

    public function body(): string
    {
        $firstName = preg_split('/\s+/', trim($this->requesterName))[0] ?? $this->requesterName;
        $what = self::joinSpanish(array_map(fn (array $request) => "{$request['title']} ({$request['key']})", $this->requests));

        return implode("\n\n", [
            "Hola, {$firstName}. Ya está disponible lo que pediste: {$what}, en {$this->productName} {$this->tag}.",
            $this->notes,
            self::CLOSING,
        ]);
    }

    public function actionUrl(): ?string
    {
        $first = $this->requests[0]['key'] ?? null;

        return $first !== null && Route::has('ai.ratings.create') ? route('ai.ratings.create', $first) : null;
    }

    public function actionText(): string
    {
        return 'Calificar';
    }

    public function toMail(object $notifiable): MailMessage|Mailable
    {
        $first = $this->requests[0]['key'] ?? null;
        $request = $first !== null && $notifiable instanceof User ? self::findRequest($first) : null;

        if ($request === null || ! $notifiable instanceof User) {
            return parent::toMail($notifiable);
        }

        $version = $this->versionId === null ? null : AiProductVersion::query()->with('product')->find($this->versionId);
        $title = self::joinSpanish(array_map(fn (array $item): string => $item['title'], $this->requests));

        return $this->templateMail($notifiable, RequestEmails::delivered($request, $notifiable, $version, $title, $this->notes));
    }

    /**
     * "a", "a y b", "a, b y c" (lib/releaseNotice.ts `joinSpanish`).
     *
     * @param  list<string>  $items
     */
    private static function joinSpanish(array $items): string
    {
        if (count($items) <= 1) {
            return implode('', $items);
        }

        $last = array_pop($items);

        return implode(', ', $items).' y '.$last;
    }
}
