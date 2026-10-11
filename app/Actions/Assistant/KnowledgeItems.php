<?php

namespace App\Actions\Assistant;

use App\Domain\Assistant\KnowledgeTypes;
use App\Models\AiProduct;
use App\Models\KnowledgeItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writes of the knowledge base (`assistant.knowledge.manage`). Every change
 * goes to the activity log `knowledge` with a Spanish sentence ("creó la
 * ficha", "cambió destinatarios y horario", "la marcó como revisada"),
 * which the card shows under "Cambios recientes". Properties carry field
 * names only, never values.
 *
 * `$data` is the validated `KnowledgeCardPayload`
 * (App\Http\Requests\Assistant\KnowledgeItemRequest::payload()).
 */
final class KnowledgeItems
{
    /** Labels of the card attributes in "cambió …" sentences. */
    private const ATTRIBUTE_LABELS = [
        'title' => 'título',
        'description' => 'descripción',
        'company_ids' => 'empresas',
        'area_id' => 'área',
        'owner_id' => 'responsable',
        'visibility' => 'visibilidad',
        'review_at' => 'fecha de revisión',
        'related' => 'relacionadas',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data, string $source = 'manual'): KnowledgeItem
    {
        return DB::transaction(function () use ($actor, $data, $source): KnowledgeItem {
            $item = KnowledgeItem::query()->create([
                'type' => $data['type'],
                'title' => $data['title'],
                'description' => $data['description'],
                'fields' => KnowledgeTypes::normalize($data['type'], $data['fields']),
                'visibility' => $data['visibility'],
                'area_id' => $data['area_id'],
                'owner_id' => $data['owner_id'],
                'review_at' => $data['review_at'],
                'source' => $source,
                'created_by_id' => $actor->id,
                'updated_by_id' => $actor->id,
            ]);

            $item->companies()->sync($data['company_ids']);
            $this->link($item, $data['related_card_keys'], $data['related_product_slugs']);
            $this->log($actor, $item, 'created', 'creó la ficha');

            return $item;
        });
    }

    /**
     * Saves the payload (the type never changes) and logs what changed;
     * an update without changes logs nothing.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, KnowledgeItem $item, array $data): KnowledgeItem
    {
        return DB::transaction(function () use ($actor, $item, $data): KnowledgeItem {
            $changed = $this->changes($item, $data);

            $item->fill([
                'title' => $data['title'],
                'description' => $data['description'],
                'fields' => KnowledgeTypes::normalize($item->type, $data['fields']),
                'visibility' => $data['visibility'],
                'area_id' => $data['area_id'],
                'owner_id' => $data['owner_id'],
                'review_at' => $data['review_at'],
            ]);

            if ($changed === []) {
                return $item;
            }

            $item->updated_by_id = $actor->id;
            $item->save();
            $item->companies()->sync($data['company_ids']);
            $this->link($item, $data['related_card_keys'], $data['related_product_slugs']);
            // Links and companies alone do not touch the row.
            $item->touch();

            $labels = array_map(fn (string $name) => $this->label($item->type, $name), $changed);
            $this->log($actor, $item, 'updated', 'cambió '.self::sentence($labels), ['changed' => $changed]);

            return $item;
        });
    }

    /**
     * "Marcar como revisada": next review 6 months from today.
     */
    public function review(User $actor, KnowledgeItem $item): KnowledgeItem
    {
        $item->forceFill([
            'last_reviewed_at' => now(),
            'last_reviewed_by_id' => $actor->id,
            'review_at' => CarbonImmutable::today()->addMonthsNoOverflow(KnowledgeTypes::REVIEW_MONTHS)->toDateString(),
            'updated_by_id' => $actor->id,
        ])->save();

        $this->log($actor, $item, 'reviewed', 'la marcó como revisada');

        return $item;
    }

    public function delete(User $actor, KnowledgeItem $item): void
    {
        $this->log($actor, $item, 'deleted', 'eliminó la ficha');
        $item->delete();
    }

    /**
     * Names of what the payload changes: card attributes, `fields.{key}`,
     * `company_ids` and `related`.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function changes(KnowledgeItem $item, array $data): array
    {
        $changed = [];

        if ($item->title !== $data['title']) {
            $changed[] = 'title';
        }
        if ($item->description !== $data['description']) {
            $changed[] = 'description';
        }

        $before = KnowledgeTypes::normalize($item->type, $item->fields ?? []);
        foreach (KnowledgeTypes::normalize($item->type, $data['fields']) as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changed[] = "fields.{$key}";
            }
        }

        $companies = $item->companies()->pluck('companies.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $wanted = collect($data['company_ids'])->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($companies !== $wanted) {
            $changed[] = 'company_ids';
        }

        foreach (['area_id', 'owner_id'] as $attribute) {
            $current = $item->{$attribute} === null ? null : (int) $item->{$attribute};
            if ($current !== $data[$attribute]) {
                $changed[] = $attribute;
            }
        }
        if ($item->visibility !== $data['visibility']) {
            $changed[] = 'visibility';
        }
        if ($item->review_at->toDateString() !== $data['review_at']) {
            $changed[] = 'review_at';
        }

        $links = [
            $item->relatedItems()->pluck('key')->all(),
            $item->relatedProducts()->pluck('slug')->all(),
        ];
        if ($links !== [array_values($data['related_card_keys']), array_values($data['related_product_slugs'])]) {
            $changed[] = 'related';
        }

        return $changed;
    }

    /**
     * Replaces the "Relacionadas" links, in the chosen order.
     *
     * @param  list<string>  $cardKeys
     * @param  list<string>  $productSlugs
     */
    private function link(KnowledgeItem $item, array $cardKeys, array $productSlugs): void
    {
        DB::table('knowledge_links')->where('knowledge_item_id', $item->id)->delete();

        $cards = KnowledgeItem::query()->whereIn('key', $cardKeys)->whereKeyNot($item->id)->pluck('id', 'key');
        $products = AiProduct::query()->whereIn('slug', $productSlugs)->pluck('id', 'slug');
        $rows = [];
        $position = 0;

        foreach ($cardKeys as $key) {
            if (isset($cards[$key])) {
                $rows[] = ['knowledge_item_id' => $item->id, 'linked_item_id' => $cards[$key], 'ai_product_id' => null, 'position' => $position++];
            }
        }
        foreach ($productSlugs as $slug) {
            if (isset($products[$slug])) {
                $rows[] = ['knowledge_item_id' => $item->id, 'linked_item_id' => null, 'ai_product_id' => $products[$slug], 'position' => $position++];
            }
        }

        if ($rows !== []) {
            DB::table('knowledge_links')->insert($rows);
        }
    }

    private function label(string $type, string $name): string
    {
        if (str_starts_with($name, 'fields.')) {
            return mb_strtolower(KnowledgeTypes::label($type, substr($name, 7)));
        }

        return self::ATTRIBUTE_LABELS[$name] ?? $name;
    }

    /**
     * "a", "a y b", "a, b y c".
     *
     * @param  list<string>  $parts
     */
    private static function sentence(array $parts): string
    {
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts).' y '.$last;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(User $actor, KnowledgeItem $item, string $event, string $description, array $properties = []): void
    {
        activity('knowledge')
            ->causedBy($actor)
            ->performedOn($item)
            ->event($event)
            ->withProperties(['card' => $item->key, ...$properties])
            ->log($description);
    }
}
