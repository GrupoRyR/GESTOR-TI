<?php

namespace App\Support\Mail;

use App\Domain\Ai\Roadmap;
use App\Enums\Ai\RequestStatus;
use App\Mail\TemplateMail;
use App\Models\AiProductVersion;
use App\Models\AiRequest;
use App\Models\User;
use App\Support\Ai\Presenters\RequestPresenter;
use App\Support\Ai\RequesterCopy;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\URL;

/**
 * Builds the Gestor IA request emails from the approved templates with the
 * request's data. Requester emails carry public data only (key, title,
 * status, public questions and release notes): never costs, hours, rates,
 * estimates or internal notes.
 */
final class RequestEmails
{
    /**
     * Status → [glyph, family]; colors per family (design status tokens).
     */
    public const FAMILY_COLORS = [
        'gray' => ['#F0F0EC', '#45484E'],
        'amber' => ['#FFF4DB', '#7A4F00'],
        'blue' => ['#E8EEFA', '#1E4FA8'],
        'green' => ['#E6F5EC', '#1F7A4D'],
        'slate' => ['#E6EAF0', '#334155'],
        'red' => ['#FEEEEC', '#B42318'],
    ];

    public const PHASES = [1 => 'Evaluación', 2 => 'Aprobación', 3 => 'Plan', 4 => 'Construcción', 5 => 'Entrega'];

    /**
     * `new-request`, to IT.
     */
    public static function newRequest(AiRequest $request): TemplateMail
    {
        $request->loadMissing(['area', 'requester', 'company']);
        $hours = RequestPresenter::number($request->hours_per_week);

        return TemplateMail::make(EmailTemplate::NewRequest, $request->company, [
            'action_url' => self::requestUrl($request),
            'request_key' => $request->key,
            'request_title' => $request->title,
            'area_name' => (string) $request->area?->name,
            'requester_name' => (string) $request->requester?->name,
            'time_spent_today' => $hours === null ? 'Sin dato' : str_replace('.', ',', (string) $hours).' h por semana',
            'first_response_due' => MailDates::moment($request->first_response_due_at ?? now()),
        ]);
    }

    /**
     * `request-received`, to the requester.
     */
    public static function requestReceived(AiRequest $request): TemplateMail
    {
        $request->loadMissing('company');

        return TemplateMail::make(EmailTemplate::RequestReceived, $request->company, [
            'action_url' => self::requestUrl($request),
            'request_key' => $request->key,
            'request_title' => $request->title,
            'review_due_date' => MailDates::day($request->first_response_due_at ?? now()),
        ]);
    }

    /**
     * `status-changed`, to the requester, for the status the notice was
     * created with.
     */
    public static function statusChanged(AiRequest $request, RequestStatus $status): TemplateMail
    {
        $request->loadMissing(['company', 'area']);
        [$glyph, $family] = self::statusStyle($status);
        [$bg, $fg] = self::FAMILY_COLORS[$family];
        $current = self::phase($status);
        $where = RequesterCopy::where($request->replicate()->forceFill(['status' => $status]));
        $rank = $status === RequestStatus::PendingApproval ? app(Roadmap::class)->rank($request) : null;
        $phases = [];

        foreach (self::PHASES as $number => $label) {
            $state = $number < $current ? 'done' : ($number === $current ? 'current' : 'pending');
            $phases[] = [
                'state' => $state,
                'glyph' => match ($state) {
                    'done' => '✓',
                    'current' => $glyph,
                    'pending' => '',
                },
                'label' => $label.' · '.match ($state) {
                    'done' => 'hecha',
                    'current' => 'aquí está ahora',
                    'pending' => 'pendiente',
                },
                'note' => $state === 'current' ? rtrim($where['label'], '.').'.' : '',
                'dot_bg' => match ($state) {
                    'done' => '#17191C',
                    'current' => $bg,
                    'pending' => '#FFFFFF',
                },
                'dot_fg' => match ($state) {
                    'done' => '#FFFFFF',
                    'current' => $fg,
                    'pending' => '#5E6168',
                },
                'dot_border' => match ($state) {
                    'done' => '#17191C',
                    'current' => $bg,
                    'pending' => '#D6D6D0',
                },
                'color' => match ($state) {
                    'done' => '#17191C',
                    'current' => $fg,
                    'pending' => '#5E6168',
                },
            ];
        }

        return TemplateMail::make(EmailTemplate::StatusChanged, $request->company, [
            'action_url' => self::requestUrl($request),
            'request_key' => $request->key,
            'request_title' => $request->title,
            'status_label' => $status->label(),
            'status_text' => mb_strtolower($status->label()),
            'status_glyph' => $glyph,
            'status_family' => $family,
            'status_bg' => $bg,
            'status_fg' => $fg,
            'current_phase' => mb_strtolower(self::PHASES[$current]),
            'phases' => $phases,
            'next_step_text' => $where['explanation'],
            'roadmap_position' => $rank,
            'roadmap_area' => (string) ($request->area?->name ?? 'tu área'),
        ]);
    }

    /**
     * `it-question`, to the requester. The "reply to this email" block
     * shows only when Settings `mail.reply_by_email` is on (the app does
     * not process email replies yet).
     */
    public static function itQuestion(AiRequest $request, string $question, string $askerName, CarbonInterface $askedAt): TemplateMail
    {
        $request->loadMissing('company');

        return TemplateMail::make(EmailTemplate::ItQuestion, $request->company, [
            'action_url' => self::requestUrl($request),
            'request_key' => $request->key,
            'request_title' => $request->title,
            'asker_name' => $askerName,
            'asked_at' => MailDates::moment($askedAt, comma: true),
            'question_text' => $question,
            'reply_by_email' => (bool) app(Settings::class)->get('mail.reply_by_email', false),
        ]);
    }

    /**
     * `delivered`, to the requester: the release that carries the request
     * (the given one, else the latest version linked to it) and the five
     * signed one-click rating links.
     */
    public static function delivered(AiRequest $request, User $requester, ?AiProductVersion $version = null, ?string $title = null, ?string $notes = null): TemplateMail
    {
        $request->loadMissing(['company', 'product']);
        $version ??= $request->productVersions()->with('product')->reorder()->latest('ai_product_versions.id')->first();
        $released = $version?->published_at ?? $version?->released_at ?? $request->inProductionSince() ?? now();
        $notes ??= $version?->user_notes;
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/', (string) $notes) ?: []), fn (string $p): bool => $p !== ''));

        return TemplateMail::make(EmailTemplate::Delivered, $request->company, [
            'action_url' => route('ai.ratings.create', $request->key),
            'request_key' => $request->key,
            'request_title' => $title ?? $request->title,
            'requester_first_name' => preg_split('/\s+/', trim($requester->name))[0] ?? $requester->name,
            'product_name' => (string) ($version?->product?->name ?? $request->product?->name ?? 'el Gestor'),
            'release_version' => ltrim((string) ($version?->version ?? $version?->tag ?? $request->product_version ?? ''), 'vV'),
            'released_on' => MailDates::day($released),
            'change_title' => $request->title,
            'change_paragraphs' => $paragraphs,
            ...self::ratingLinks($request, $requester),
        ]);
    }

    /**
     * `rating_url_1` … `rating_url_5`: signed, expiring, single-purpose
     * links (`ai.ratings.quick`) that save the score for this requester.
     *
     * @return array<string, string>
     */
    public static function ratingLinks(AiRequest $request, User $requester): array
    {
        $expires = now()->addDays((int) config('gestor.mail.rating_link_days', 30));
        $links = [];

        foreach (range(1, 5) as $score) {
            $links["rating_url_{$score}"] = URL::temporarySignedRoute('ai.ratings.quick', $expires, [
                'request' => $request->key,
                'score' => $score,
                'user' => $requester->id,
            ]);
        }

        return $links;
    }

    /**
     * @return array{0: string, 1: string} glyph and status family
     */
    public static function statusStyle(RequestStatus $status): array
    {
        return match ($status) {
            RequestStatus::Received, RequestStatus::Evaluating => ['○', 'gray'],
            RequestStatus::NeedsInfo, RequestStatus::PendingApproval, RequestStatus::InTesting => ['◐', 'amber'],
            RequestStatus::Approved, RequestStatus::InProduction => ['✓', 'green'],
            RequestStatus::Backlog => ['○', 'slate'],
            RequestStatus::Closed => ['✓', 'slate'],
            RequestStatus::Rejected => ['✕', 'red'],
            RequestStatus::Planned, RequestStatus::InDevelopment => ['◐', 'blue'],
        };
    }

    /**
     * Phase of the rail (resources/js/lib/status.ts `phase`).
     */
    public static function phase(RequestStatus $status): int
    {
        return match ($status) {
            RequestStatus::Received, RequestStatus::Evaluating, RequestStatus::NeedsInfo => 1,
            RequestStatus::PendingApproval, RequestStatus::Approved, RequestStatus::Backlog, RequestStatus::Rejected => 2,
            RequestStatus::Planned => 3,
            RequestStatus::InDevelopment, RequestStatus::InTesting => 4,
            RequestStatus::InProduction, RequestStatus::Closed => 5,
        };
    }

    private static function requestUrl(AiRequest $request): string
    {
        return route('ai.requests.show', $request->key);
    }
}
