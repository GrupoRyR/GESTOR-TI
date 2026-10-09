<?php

namespace App\Actions\Ai;

use App\Domain\Ai\FirstResponse;
use App\Domain\Ai\ProductResolver;
use App\Domain\Ai\RequestAttachments;
use App\Enums\Ai\ApprovalKind;
use App\Enums\Ai\AreaApprovalStatus;
use App\Enums\Ai\RequestStatus;
use App\Enums\Ai\RequestType;
use App\Enums\Ai\Urgency;
use App\Models\AiProduct;
use App\Models\AiRequest;
use App\Models\Area;
use App\Models\User;
use App\Notifications\AiApprovalRequested;
use App\Support\AreaApproval;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Files a request from the wizard (or by TI on someone's behalf, which
 * needs `ai.manage`). Input shape is validated by the controller's form
 * request (B3); this action owns the domain rules:
 *
 * - company = the requester's company; area = the given one (must belong to
 *   that company) or the one of the requester's employee record;
 * - the primary area plus `benefited_area_ids` go to `ai_request_area`;
 * - title = the given one or the first sentence of the problem (the wizard
 *   has no title field);
 * - first response due = now + Settings `ai.first_response_business_days`
 *   business days (Bogotá, weekends and Settings `ai.holidays` skipped);
 * - area approval: when Settings `areas.approval_enabled.{company}` is on
 *   and the area has an approver, the request waits for them
 *   (`area_approval_status = pending`) and they are notified;
 * - `criteria` (list of texts) become acceptance criteria;
 * - the requester follows it (`ai_request_followers`);
 * - attachments are validated first (nothing is created on a bad file) and
 *   stored on the private disk;
 * - product: `product` (slug, the `?producto={slug}` entry point) or
 *   `product_id`; it must serve the requester's company. The version
 *   defaults to the product's current one.
 *
 * Data keys: type, product (slug), product_id, product_version, title, problem, who_suffers,
 * current_process, hours_per_week, people, expected_result, success_criteria,
 * systems, systems_notes, urgency, deadline, deadline_reason, area_id,
 * benefited_area_ids, criteria.
 */
class CreateRequest
{
    private const TITLE_MAX = 120;

    public function __construct(
        private readonly FirstResponse $firstResponse,
        private readonly RequestAttachments $attachments,
        private readonly AreaApproval $areaApproval,
        private readonly ProductResolver $products,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $files
     */
    public function handle(User $actor, array $data, array $files = [], ?User $requester = null): AiRequest
    {
        $requester ??= $actor;

        Gate::forUser($actor)->authorize('create', AiRequest::class);

        if ($requester->id !== $actor->id) {
            Gate::forUser($actor)->authorize('createOnBehalf', AiRequest::class);
        }

        if ($requester->company_id === null) {
            throw ValidationException::withMessages(['requester_id' => 'La persona no tiene empresa asignada.']);
        }

        $this->attachments->validate($files);
        $area = $this->area($requester, $data['area_id'] ?? null);
        $product = $this->product($requester, $data);
        $approver = $this->areaApprover($area);
        $stored = [];

        try {
            $request = DB::transaction(function () use ($actor, $requester, $data, $files, $area, $approver, $product, &$stored): AiRequest {
                $request = AiRequest::create([
                    'company_id' => $requester->company_id,
                    'area_id' => $area->id,
                    'requester_id' => $requester->id,
                    'created_by_id' => $actor->id,
                    'type' => RequestType::from((string) $data['type']),
                    'title' => $this->title($data),
                    'product_id' => $product?->id,
                    'product_version' => $product !== null
                        ? $this->products->version($product, $data['product_version'] ?? null)
                        : $this->text($data, 'product_version'),
                    'problem' => trim((string) $data['problem']),
                    'who_suffers' => $this->text($data, 'who_suffers'),
                    'current_process' => $this->text($data, 'current_process'),
                    'hours_per_week' => $data['hours_per_week'] ?? null,
                    'people_count' => $data['people'] ?? null,
                    'expected_result' => $this->text($data, 'expected_result'),
                    'success_criteria' => $this->text($data, 'success_criteria'),
                    'systems' => array_values(array_filter((array) ($data['systems'] ?? []), 'filled')),
                    'systems_notes' => $this->text($data, 'systems_notes'),
                    'urgency' => isset($data['urgency']) ? Urgency::from((string) $data['urgency']) : null,
                    'deadline' => $data['deadline'] ?? null,
                    'deadline_reason' => $this->text($data, 'deadline_reason'),
                    'status' => RequestStatus::Received,
                    'area_approval_status' => $approver !== null ? AreaApprovalStatus::Pending : null,
                    'first_response_due_at' => $this->firstResponse->dueAt(now()),
                ]);

                $this->syncAreas($request, $area, (array) ($data['benefited_area_ids'] ?? []));

                // The requester follows their request ("Seguir").
                $request->followers()->syncWithoutDetaching([$requester->id]);

                foreach (array_values(array_filter((array) ($data['criteria'] ?? []), 'filled')) as $i => $text) {
                    $request->criteria()->create(['position' => $i + 1, 'text' => trim((string) $text)]);
                }

                $request->statusChanges()->create([
                    'from_status' => null,
                    'to_status' => RequestStatus::Received,
                    'user_id' => $actor->id,
                ]);

                if ($approver !== null) {
                    $request->approvals()->create([
                        'kind' => ApprovalKind::Area,
                        'user_id' => $approver->id,
                        'requested_by_id' => $actor->id,
                        'requested_at' => now(),
                    ]);
                }

                foreach ($files as $file) {
                    $stored[] = $this->attachments->store($request, $file, $actor);
                }

                return $request;
            });
        } catch (Throwable $exception) {
            foreach ($stored as $attachment) {
                Storage::disk($attachment->disk)->delete($attachment->path);
            }

            throw $exception;
        }

        // Outside the transaction: a delivery failure never loses the request.
        $approver?->notify(AiApprovalRequested::for($request, ApprovalKind::Area));

        return $request;
    }

    private function area(User $requester, mixed $areaId): Area
    {
        $areaId ??= $requester->employee?->area_id;

        $area = $areaId === null ? null : Area::withoutGlobalScopes()
            ->whereKey($areaId)
            ->where('company_id', $requester->company_id)
            ->first();

        if ($area === null) {
            throw ValidationException::withMessages(['area_id' => 'Escoge un área de tu empresa.']);
        }

        return $area;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function product(User $requester, array $data): ?AiProduct
    {
        $slug = trim((string) ($data['product'] ?? ''));
        $id = $data['product_id'] ?? null;

        if ($slug === '' && blank($id)) {
            return null;
        }

        $product = $slug !== ''
            ? $this->products->forUser($slug, $requester)
            : $this->products->byIdForUser((int) $id, $requester);

        if ($product === null) {
            throw ValidationException::withMessages(['product' => 'Escoge una aplicación de tu empresa.']);
        }

        return $product;
    }

    private function areaApprover(Area $area): ?User
    {
        if ($area->approver_user_id === null || ! $this->areaApproval->enabled($area->company_id)) {
            return null;
        }

        return $area->approver;
    }

    /**
     * @param  array<int, mixed>  $benefited
     */
    private function syncAreas(AiRequest $request, Area $primary, array $benefited): void
    {
        $ids = Area::withoutGlobalScopes()
            ->where('company_id', $request->company_id)
            ->whereKey(array_map('intval', $benefited))
            ->whereKeyNot($primary->id)
            ->pluck('id');

        $request->benefitedAreas()->attach([$primary->id => ['is_primary' => true]]);

        foreach ($ids as $id) {
            $request->benefitedAreas()->attach($id, ['is_primary' => false]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function title(array $data): string
    {
        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            $problem = trim(preg_replace('/\s+/', ' ', (string) $data['problem']) ?? '');
            $title = preg_split('/(?<=[.!?])\s/', $problem, 2)[0] ?? $problem;
        }

        return Str::limit(rtrim($title, '.'), self::TITLE_MAX - 3, '...');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function text(array $data, string $key): ?string
    {
        $value = isset($data[$key]) ? trim((string) $data[$key]) : '';

        return $value === '' ? null : $value;
    }
}
