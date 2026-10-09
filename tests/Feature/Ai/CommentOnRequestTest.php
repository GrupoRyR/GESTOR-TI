<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\CommentOnRequest;
use App\Enums\Ai\CommentVisibility;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequestComment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CommentOnRequestTest extends AiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('local');
    }

    private function comment(): CommentOnRequest
    {
        return app(CommentOnRequest::class);
    }

    public function test_internal_notes_are_only_visible_to_ai_managers(): void
    {
        $request = $this->request();
        $this->comment()->handle($request, $this->jhon, 'Revisar con Leonardo', CommentVisibility::Internal);
        $this->comment()->handle($request, $this->jhon, '¿Cuántos pedidos se bloquean al día?', CommentVisibility::Public);
        $this->comment()->handle($request, $this->paola, 'Unos 15', CommentVisibility::Public);

        $forRequester = AiRequestComment::query()->visibleTo($this->paola)->where('ai_request_id', $request->id)->pluck('body')->all();
        $forIt = AiRequestComment::query()->visibleTo($this->jhon)->where('ai_request_id', $request->id)->pluck('body')->all();
        $forAreaViewer = AiRequestComment::query()->visibleTo($this->ventasApprover)->where('ai_request_id', $request->id)->pluck('body')->all();

        $this->assertSame(['¿Cuántos pedidos se bloquean al día?', 'Unos 15'], $forRequester);
        $this->assertSame(['¿Cuántos pedidos se bloquean al día?', 'Unos 15'], $forAreaViewer);
        $this->assertSame(['Revisar con Leonardo', '¿Cuántos pedidos se bloquean al día?', 'Unos 15'], $forIt);
    }

    public function test_only_ai_managers_write_internal_notes(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->comment()->handle($this->request(), $this->paola, 'nota', CommentVisibility::Internal);
    }

    public function test_a_stranger_cannot_comment(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->comment()->handle($this->request(), $this->derivadosUser, 'hola', CommentVisibility::Public);
    }

    public function test_a_closed_request_takes_no_public_comments(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->comment()->handle($this->request(['status' => RequestStatus::Closed]), $this->paola, 'hola', CommentVisibility::Public);
    }

    public function test_an_empty_body_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->comment()->handle($this->request(), $this->paola, '   ', CommentVisibility::Public);
    }

    public function test_a_public_it_reply_counts_as_first_response_but_an_internal_note_does_not(): void
    {
        $request = $this->request();

        $this->comment()->handle($request, $this->jhon, 'nota', CommentVisibility::Internal);
        $this->assertNull($request->refresh()->first_responded_at);

        $this->comment()->handle($request, $this->jhon, 'Hola Paola, lo revisamos', CommentVisibility::Public);
        $this->assertNotNull($request->refresh()->first_responded_at);
    }

    public function test_the_requester_answer_sends_a_needs_info_request_back_to_evaluation(): void
    {
        $request = $this->request(['status' => RequestStatus::NeedsInfo, 'first_responded_at' => now()]);

        $this->comment()->handle($request, $this->paola, 'Son unos 15 pedidos al día', CommentVisibility::Public);

        $request->refresh();
        $this->assertSame(RequestStatus::Evaluating, $request->status);
        $this->assertSame($this->paola->id, $request->statusChanges()->latest('id')->first()->user_id);
    }

    public function test_comment_attachments_go_to_the_private_disk(): void
    {
        $request = $this->request();

        $comment = $this->comment()->handle($request, $this->paola, 'Adjunto la captura', CommentVisibility::Public, [
            UploadedFile::fake()->image('captura.jpg'),
        ]);

        $attachment = $comment->attachments()->first();
        $this->assertNotNull($attachment);
        $this->assertSame($request->id, $attachment->ai_request_id);
        Storage::disk('local')->assertExists($attachment->path);
    }
}
