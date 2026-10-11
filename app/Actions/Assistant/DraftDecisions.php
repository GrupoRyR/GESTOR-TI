<?php

namespace App\Actions\Assistant;

use App\Domain\Assistant\KnowledgeTypes;
use App\Models\Area;
use App\Models\Company;
use App\Models\KnowledgeDraft;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Decisions on `/aprender` drafts (`assistant.knowledge.manage`), from the
 * Gestor TI and, in C4, from the Telegram buttons:
 *
 * - `approve`: publishes the proposed card (source `learn`) with the
 *   visibility and review date the approver chose. Needs a title and a
 *   company; type fields and the owner marked "Falta" do not block (the
 *   card is completed later). Companies the approver may not act on and an
 *   area outside them are dropped.
 * - `merge` ("Agregar a K-031"): fills the empty fields of that card with
 *   the draft's (same type only) and appends the draft text to its
 *   description.
 * - `approvedWith`: "Editar antes de aprobar" created the card through the
 *   knowledge form.
 * - `discard`: with a reason.
 *
 * Callers check `isPending()` first; a decided draft never changes again.
 */
final class DraftDecisions
{
    public const NEEDS_TITLE = 'Falta el título. Usa “Editar antes de aprobar” para completarlo.';

    public const NEEDS_COMPANY = 'Falta la empresa. Usa “Editar antes de aprobar” para completarla.';

    public function __construct(
        private readonly KnowledgeItems $items,
        private readonly CurrentCompany $tenancy,
    ) {}

    /**
     * Why the draft cannot be approved as is, or null.
     */
    public function blocker(KnowledgeDraft $draft, User $approver): ?string
    {
        if (trim((string) ($draft->proposed['title'] ?? '')) === '') {
            return self::NEEDS_TITLE;
        }

        return $this->companies($draft, $approver) === [] ? self::NEEDS_COMPANY : null;
    }

    public function approve(KnowledgeDraft $draft, User $approver, string $visibility, string $reviewAt): KnowledgeItem
    {
        $proposed = $draft->proposed;
        $type = (string) $proposed['type'];
        $companyIds = $this->companies($draft, $approver);
        $areaId = isset($proposed['area_id']) ? (int) $proposed['area_id'] : null;
        if ($areaId !== null && ! in_array((int) Area::withoutGlobalScopes()->whereKey($areaId)->value('company_id'), $companyIds, true)) {
            $areaId = null;
        }
        $ownerId = isset($proposed['owner_id']) && User::query()->whereKey($proposed['owner_id'])->where('active', true)->exists()
            ? (int) $proposed['owner_id'] : null;

        return DB::transaction(function () use ($draft, $approver, $proposed, $type, $companyIds, $areaId, $ownerId, $visibility, $reviewAt): KnowledgeItem {
            $card = $this->items->create($approver, [
                'type' => $type,
                'title' => mb_substr(trim((string) $proposed['title']), 0, 160),
                'description' => $proposed['description'] ?? null,
                'fields' => KnowledgeTypes::normalize($type, (array) ($proposed['fields'] ?? [])),
                'company_ids' => $companyIds,
                'area_id' => $areaId,
                'owner_id' => $ownerId,
                'visibility' => $visibility,
                'review_at' => $reviewAt,
                'related_card_keys' => [],
                'related_product_slugs' => [],
            ], 'learn');

            $this->decide($draft, $approver, 'approved', $card);

            return $card;
        });
    }

    public function approvedWith(KnowledgeDraft $draft, KnowledgeItem $card, User $approver): void
    {
        $this->decide($draft, $approver, 'approved', $card);
    }

    public function merge(KnowledgeDraft $draft, KnowledgeItem $card, User $approver): KnowledgeItem
    {
        $proposed = $draft->proposed;
        $current = KnowledgeTypes::normalize($card->type, $card->fields ?? []);
        $fields = $current;

        if ($proposed['type'] === $card->type) {
            foreach (KnowledgeTypes::normalize($card->type, (array) ($proposed['fields'] ?? [])) as $key => $value) {
                if (($fields[$key] === null || $fields[$key] === []) && $value !== null && $value !== []) {
                    $fields[$key] = $value;
                }
            }
        }

        $description = $card->description;
        $addition = trim((string) ($proposed['description'] ?? ''));
        if ($addition !== '' && ! str_contains((string) $description, $addition)) {
            $description = $description === null || trim($description) === '' ? $addition : rtrim($description)."\n\n".$addition;
        }

        return DB::transaction(function () use ($draft, $card, $approver, $fields, $description): KnowledgeItem {
            $card->loadMissing('companies');
            $this->items->update($approver, $card, [
                'title' => $card->title,
                'description' => $description,
                'fields' => $fields,
                'company_ids' => $card->companies->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'area_id' => $card->area_id === null ? null : (int) $card->area_id,
                'owner_id' => $card->owner_id === null ? null : (int) $card->owner_id,
                'visibility' => $card->visibility,
                'review_at' => $card->review_at->toDateString(),
                'related_card_keys' => $card->relatedItems()->pluck('key')->all(),
                'related_product_slugs' => $card->relatedProducts()->pluck('slug')->all(),
            ]);

            $this->decide($draft, $approver, 'merged', $card);

            return $card;
        });
    }

    public function discard(KnowledgeDraft $draft, User $approver, string $reason): void
    {
        $draft->discard_reason = $reason;
        $this->decide($draft, $approver, 'discarded', null);
    }

    /**
     * Proposed companies that are active and the approver may act on.
     *
     * @return list<int>
     */
    private function companies(KnowledgeDraft $draft, User $approver): array
    {
        $ids = array_values(array_map('intval', (array) ($draft->proposed['company_ids'] ?? [])));

        return array_values(array_filter($ids, fn (int $id) => $this->tenancy->canAccess($approver, $id)
            && Company::query()->active()->whereKey($id)->exists()));
    }

    private function decide(KnowledgeDraft $draft, User $by, string $status, ?KnowledgeItem $card): void
    {
        $draft->forceFill([
            'status' => $status,
            'knowledge_item_id' => $card?->id,
            'decided_by_id' => $by->id,
            'decided_at' => now(),
        ])->save();

        activity('knowledge')
            ->causedBy($by)
            ->performedOn($draft)
            ->event("draft_{$status}")
            ->withProperties(['draft_id' => $draft->id, 'card' => $card?->key])
            ->log(match ($status) {
                'approved' => "aprobó un borrador de /aprender como {$card?->key}",
                'merged' => "agregó un borrador de /aprender a {$card?->key}",
                default => 'descartó un borrador de /aprender',
            });
    }
}
