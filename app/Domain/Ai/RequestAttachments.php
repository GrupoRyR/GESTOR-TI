<?php

namespace App\Domain\Ai;

use App\Models\AiRequest;
use App\Models\AiRequestAttachment;
use App\Models\AiRequestComment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Request files on the private disk (`gestor.ai.attachments.disk`, `local`
 * = storage/app/private, never public) under `ai/requests/{id}`. Allowed
 * types and size come from config/gestor.php `ai.attachments` and mirror the
 * design's wizard rules (images, PDF, Excel, CSV; 10 MB; 10 files).
 * Downloads go through a signed, authorized route (B3), never a public URL.
 */
final class RequestAttachments
{
    /**
     * Validates every file before anything is stored. Error keys are
     * `{$field}` (count) and `{$field}.{i}` (each file), like Laravel's.
     *
     * @param  array<int, UploadedFile>  $files
     *
     * @throws ValidationException
     */
    public function validate(array $files, string $field = 'attachments'): void
    {
        $config = (array) config('gestor.ai.attachments');

        Validator::make([$field => $files], [
            $field => ['array', 'max:'.(int) $config['max_files']],
            $field.'.*' => ['file', 'max:'.(int) $config['max_kb'], 'mimetypes:'.implode(',', (array) $config['mimetypes'])],
        ], [
            $field.'.max' => 'Puedes adjuntar hasta :max archivos.',
            $field.'.*.max' => 'Cada archivo puede pesar hasta '.intdiv((int) $config['max_kb'], 1024).' MB.',
            $field.'.*.mimetypes' => 'Solo imágenes, PDF o Excel.',
        ])->validate();
    }

    public function store(AiRequest $request, UploadedFile $file, ?User $uploader, ?AiRequestComment $comment = null): AiRequestAttachment
    {
        $disk = (string) config('gestor.ai.attachments.disk', 'local');
        $path = $file->store('ai/requests/'.$request->id, $disk);

        return $request->attachments()->create([
            'ai_request_comment_id' => $comment?->id,
            'user_id' => $uploader?->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => (string) ($file->getMimeType() ?? $file->getClientMimeType()),
            'size' => (int) $file->getSize(),
        ]);
    }
}
