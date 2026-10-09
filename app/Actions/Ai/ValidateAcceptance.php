<?php

namespace App\Actions\Ai;

use App\Domain\Ai\RequestAttachments;
use App\Enums\Ai\AcceptanceResult;
use App\Models\AcceptanceCriterion;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Enviar validación" (RequestValidationPayload of the design): the
 * requester marks each acceptance criterion pass/fail while the request is
 * "En pruebas"; a failed one needs a comment and may carry one photo
 * (private disk). Moving to production stays a TI decision.
 */
class ValidateAcceptance
{
    public function __construct(private readonly RequestAttachments $attachments) {}

    /**
     * @param  list<array{id: int, result: string, comment?: ?string, photo?: ?UploadedFile}>  $results
     */
    public function handle(AiRequest $request, User $requester, array $results): AiRequest
    {
        Gate::forUser($requester)->authorize('validate', $request);

        $criteria = $request->criteria()->get()->keyBy('id');
        $errors = [];

        foreach ($results as $i => $row) {
            $result = AcceptanceResult::tryFrom((string) ($row['result'] ?? ''));
            $comment = trim((string) ($row['comment'] ?? ''));

            if (! $criteria->has((int) ($row['id'] ?? 0))) {
                $errors["criteria.{$i}.id"] = 'Ese criterio no es de esta solicitud.';
            } elseif ($result === null) {
                $errors["criteria.{$i}.result"] = 'Marca si se cumple o no.';
            } elseif ($result === AcceptanceResult::Fail && $comment === '') {
                $errors["criteria.{$i}.comment"] = 'Cuéntanos qué falló.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $photos = array_values(array_filter(array_map(fn (array $row) => $row['photo'] ?? null, $results)));
        $this->attachments->validate($photos, 'photos');

        DB::transaction(function () use ($request, $requester, $results, $criteria): void {
            foreach ($results as $row) {
                /** @var AcceptanceCriterion $criterion */
                $criterion = $criteria[(int) $row['id']];
                $photo = $row['photo'] ?? null;
                $comment = trim((string) ($row['comment'] ?? ''));

                $criterion->fill([
                    'result' => AcceptanceResult::from((string) $row['result']),
                    'result_comment' => $comment === '' ? null : $comment,
                    'validated_by_id' => $requester->id,
                    'validated_at' => now(),
                ]);

                if ($photo instanceof UploadedFile) {
                    $criterion->attachment_id = $this->attachments->store($request, $photo, $requester)->id;
                }

                $criterion->save();
            }
        });

        return $request;
    }
}
