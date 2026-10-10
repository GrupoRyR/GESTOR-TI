<?php

namespace App\Http\Requests\Assistant;

use App\Domain\Ai\SecretGuard;
use App\Domain\Assistant\KnowledgeTypes;
use App\Models\AiProduct;
use App\Models\Area;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Body of `assistant.knowledge.store` and `.update` (`KnowledgeCardPayload`
 * of types/pages/assistant-knowledge.ts). Errors use Laravel keys
 * (`title`, `fields.recipients`, `fields.steps.0`, `company_ids`, …) and
 * Spanish messages. Route middleware already requires
 * `assistant.knowledge.manage`.
 *
 * - The fields validated are those of the type (on update, the card's
 *   type: it cannot change); others are dropped.
 * - Companies must be ones the person may act on (CurrentCompany::canAccess),
 *   the area must belong to one of them, related cards and products must
 *   be visible to the person.
 * - "Nunca contraseñas": every text is checked with App\Domain\Ai\SecretGuard.
 */
class KnowledgeItemRequest extends FormRequest
{
    public const SECRET_MESSAGE = 'Parece una contraseña o una llave. Las credenciales van en Accesos o en la bóveda, nunca en una ficha.';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $card = $this->card();
        $type = $this->type();

        $rules = [
            'type' => ['required', 'string', Rule::in($card !== null ? [$card->type] : KnowledgeTypes::TYPES)],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'fields' => ['nullable', 'array'],
            'company_ids' => ['required', 'array', 'min:1'],
            'company_ids.*' => ['integer', 'distinct', Rule::exists('companies', 'id')->where('is_active', true)],
            'area_id' => ['nullable', 'integer', Rule::exists('areas', 'id')],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'visibility' => ['required', 'string', Rule::in(KnowledgeTypes::VISIBILITIES)],
            'review_at' => ['required', 'date_format:Y-m-d'],
            'related_card_keys' => ['nullable', 'array', 'max:30'],
            'related_card_keys.*' => ['string', 'max:20', 'distinct'],
            'related_product_slugs' => ['nullable', 'array', 'max:30'],
            'related_product_slugs.*' => ['string', 'max:150', 'distinct'],
            'unanswered_id' => ['nullable', 'integer'],
            'draft_id' => ['nullable', 'integer'],
        ];

        foreach (KnowledgeTypes::fields($type) as $key => [, $required]) {
            if ($key === KnowledgeTypes::LIST_FIELD) {
                $rules["fields.{$key}"] = ['required', 'array', 'min:1', 'max:50'];
                $rules["fields.{$key}.*"] = ['nullable', 'string', 'max:500'];

                continue;
            }

            $rules["fields.{$key}"] = [$required ? 'required' : 'nullable', 'string', 'max:5000'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'type.*' => $this->card() !== null ? 'El tipo de una ficha no se puede cambiar.' : 'Elige el tipo de ficha.',
            'title.required' => 'Escribe el título.',
            'title.max' => 'El título puede tener hasta 160 caracteres.',
            'description.*' => 'La descripción puede tener hasta 5.000 caracteres.',
            'company_ids.*' => 'Elige al menos una empresa.',
            'area_id.*' => 'Elige un área de la lista.',
            'owner_id.*' => 'Elige un responsable de la lista.',
            'visibility.*' => 'Elige quién puede ver la ficha.',
            'review_at.*' => 'Escribe la fecha de revisión.',
            'related_card_keys.*' => 'Alguna ficha relacionada ya no existe.',
            'related_product_slugs.*' => 'Algún producto relacionado ya no existe.',
        ];

        foreach (KnowledgeTypes::fields($this->type()) as $key => [$label]) {
            if ($key === KnowledgeTypes::LIST_FIELD) {
                $messages["fields.{$key}.*"] = 'Escribe al menos un paso.';
                $messages["fields.{$key}.*.*"] = 'Cada paso puede tener hasta 500 caracteres.';

                continue;
            }

            $messages["fields.{$key}.required"] = "Completa «{$label}».";
            $messages["fields.{$key}.*"] = "«{$label}» puede tener hasta 5.000 caracteres.";
        }

        return $messages;
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $user = $this->user();
            if (! $user instanceof User) {
                return;
            }

            $this->checkScope($validator, $user);
            $this->checkSecrets($validator);
        }];
    }

    /**
     * The validated payload, normalized for App\Actions\Assistant\KnowledgeItems.
     *
     * @return array{type: string, title: string, description: ?string, fields: array<string, mixed>, company_ids: list<int>, area_id: ?int, owner_id: ?int, visibility: string, review_at: string, related_card_keys: list<string>, related_product_slugs: list<string>}
     */
    public function payload(): array
    {
        $data = $this->validated();
        $type = $this->type();

        return [
            'type' => $type,
            'title' => (string) $data['title'],
            'description' => isset($data['description']) ? (string) $data['description'] : null,
            'fields' => KnowledgeTypes::normalize($type, (array) ($data['fields'] ?? [])),
            'company_ids' => array_values(array_map('intval', $data['company_ids'])),
            'area_id' => isset($data['area_id']) ? (int) $data['area_id'] : null,
            'owner_id' => isset($data['owner_id']) ? (int) $data['owner_id'] : null,
            'visibility' => (string) $data['visibility'],
            'review_at' => (string) $data['review_at'],
            'related_card_keys' => array_values(array_map('strval', $data['related_card_keys'] ?? [])),
            'related_product_slugs' => array_values(array_map('strval', $data['related_product_slugs'] ?? [])),
        ];
    }

    private function checkScope(Validator $validator, User $user): void
    {
        $companies = app(CurrentCompany::class);
        $companyIds = array_map('intval', (array) $this->input('company_ids', []));

        foreach ($companyIds as $companyId) {
            if (! $companies->canAccess($user, $companyId)) {
                $validator->errors()->add('company_ids', 'Solo puedes crear fichas de tu empresa.');

                return;
            }
        }

        $areaId = $this->input('area_id');
        if ($areaId !== null) {
            $area = Area::withoutGlobalScopes()->find((int) $areaId);
            if ($area === null || ! in_array((int) $area->company_id, $companyIds, true)) {
                $validator->errors()->add('area_id', 'El área debe ser de una de las empresas de la ficha.');
            }
        }

        $keys = array_values(array_map('strval', (array) $this->input('related_card_keys', [])));
        if ($keys !== []) {
            $found = KnowledgeItem::query()->visibleTo($user)->whereIn('key', $keys)
                ->when($this->card() !== null, fn ($query) => $query->whereKeyNot($this->card()->id))
                ->count();
            if ($found !== count($keys)) {
                $validator->errors()->add('related_card_keys', 'Alguna ficha relacionada ya no existe.');
            }
        }

        $slugs = array_values(array_map('strval', (array) $this->input('related_product_slugs', [])));
        if ($slugs !== [] && AiProduct::query()->visibleTo($user)->whereIn('slug', $slugs)->count() !== count($slugs)) {
            $validator->errors()->add('related_product_slugs', 'Algún producto relacionado ya no existe.');
        }
    }

    private function checkSecrets(Validator $validator): void
    {
        $guard = app(SecretGuard::class);
        $texts = [
            'title' => $this->input('title'),
            'description' => $this->input('description'),
        ];

        foreach (KnowledgeTypes::normalize($this->type(), (array) $this->input('fields', [])) as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $index => $step) {
                    $texts["fields.{$key}.{$index}"] = $step;
                }
            } else {
                $texts["fields.{$key}"] = $value;
            }
        }

        foreach ($texts as $field => $text) {
            if (is_string($text) && $guard->find($text) !== null) {
                $validator->errors()->add($field, self::SECRET_MESSAGE);
            }
        }
    }

    private function card(): ?KnowledgeItem
    {
        $card = $this->route('card');

        return $card instanceof KnowledgeItem ? $card : null;
    }

    private function type(): string
    {
        $card = $this->card();
        if ($card !== null) {
            return $card->type;
        }

        $type = $this->input('type');

        return is_string($type) && in_array($type, KnowledgeTypes::TYPES, true) ? $type : '';
    }
}
