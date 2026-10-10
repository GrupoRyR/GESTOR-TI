<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Tools\ToolCatalog;
use App\Domain\Assistant\Tools\ToolContext;
use App\Models\AssistantCitation;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Services\Ai\AiCostCap;
use App\Services\Ai\AiRun;
use App\Services\Ai\AiRuntime;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage as SdkAssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;

/**
 * The Asistente TI (PROMPT 6.8.2, 6.8.3), channel-independent: the app chat
 * (C3) and the Telegram bot (C4) call `ask()`.
 *
 * 1. The question is stored first (the chat shows "Buscando…" meanwhile).
 * 2. `/aprender …` creates a draft (App\Domain\Assistant\LearnDrafts): the
 *    only write the assistant makes; it works with the AI off.
 * 3. Off (`off_no_key`, `off_cap`): nothing is sent; the caller should
 *    refuse earlier with `unavailableReason()`.
 * 4. The agent (App\Domain\Assistant\AssistantAgent) gets the fixed
 *    instructions, the tool catalog, the last 30 minutes of the
 *    conversation and the question; App\Services\Ai\AiRuntime logs every
 *    provider call and watches the monthly cap.
 * 5. The reply:
 *    - a tool already answered (credential notice, person choice, vault
 *      link, refusal): nothing else;
 *    - provider failure (after one retry): `error`, retryable, logged;
 *    - "No lo tengo documentado", an empty answer or lookups that found
 *      nothing: `no_answer`, and the question goes to "Preguntas sin
 *      respuesta" (App\Domain\Assistant\Unanswered);
 *    - otherwise `answer` with its sources: the card keys it cited among
 *      those the tools returned (else the cards it opened). Each source is
 *      a citation (card usage counters).
 *
 * Tools run with the asking person's visibility; for the company scope of
 * Gestor IA data the caller runs as that person (web: the session user;
 * C4 must authenticate the linked user before calling).
 */
final class AssistantEngine
{
    public const UNAVAILABLE = 'El asistente no está disponible en este momento, intenta en unos minutos.';

    public const OFF = 'El asistente no está activo en este momento.';

    public const CAPPED = 'El asistente llegó al tope de gasto del mes.';

    public const NOT_DOCUMENTED = '**No lo tengo documentado.** Registré la pregunta para que TI la documente.';

    public const LEARN_COMMAND = '/aprender';

    public const FEATURE = 'assistant';

    private const NOT_DOCUMENTED_PHRASE = 'no lo tengo documentado';

    private const HISTORY_LIMIT = 20;

    public function __construct(
        private readonly AiRuntime $runtime,
        private readonly AiCostCap $cap,
        private readonly ToolCatalog $tools,
        private readonly Unanswered $unanswered,
        private readonly LearnDrafts $drafts,
    ) {}

    /**
     * The system prompt: fixed text, the first thing of every request (the
     * provider caches the repeated prefix). Never put per-person or
     * per-question data here.
     */
    public static function instructions(): string
    {
        return <<<'TXT'
        Eres el Asistente TI del Grupo Empresarial RYR. Respondes preguntas del área de TI y de las empresas del grupo usando SOLO lo que devuelven tus herramientas.

        Reglas:
        1. Responde en español, corto y claro: máximo cinco frases o una lista corta. Puedes usar **negrita**.
        2. Antes de responder sobre sistemas, informes, flujos de datos, procedimientos o términos, busca con buscar_conocimiento y abre la ficha con ver_ficha cuando necesites el detalle. Para las aplicaciones propias usa buscar_productos y ver_producto; para solicitudes del Gestor IA, buscar_solicitudes_gestor_ia.
        3. Cita siempre las fichas que usaste, con su clave entre corchetes al final de la frase, por ejemplo [K-031].
        4. Nunca inventes rutas de menú, nombres de informes, horarios, personas ni datos. Lo que una ficha marca como "sin documentar" no lo sabes. Si las herramientas no traen la respuesta, contesta "No lo tengo documentado." y, en una frase, qué buscaste.
        5. Las herramientas ya filtran lo que esta persona puede ver: no supongas que existe nada más.
        6. Nunca pidas, escribas ni repitas contraseñas, llaves o tokens. Si piden una contraseña o una credencial, usa entregar_credencial: el Gestor TI la entrega por aparte y a ti solo te dice cómo terminó.
        7. Solo consultas, no cambias nada. Para enseñarte algo, la persona escribe /aprender seguido de lo que quiere registrar.
        TXT;
    }

    public static function isLearn(string $question): bool
    {
        return Str::startsWith(Str::lower(ltrim($question)), self::LEARN_COMMAND);
    }

    /**
     * Spanish reason when the assistant cannot call the provider now, else null.
     */
    public function unavailableReason(): ?string
    {
        return match ($this->runtime->status()) {
            'off_no_key' => self::OFF,
            'off_cap' => tap(self::CAPPED, fn () => $this->cap->reached()),
            default => null,
        };
    }

    /**
     * Stores the question and answers it. Returns the last message written.
     */
    public function ask(User $user, AssistantConversation $conversation, string $question, string $channel = 'app'): AssistantMessage
    {
        $question = trim($question);
        $message = $this->write($conversation, 'user', 'text', $question);

        if ($conversation->title === AssistantConversation::DEFAULT_TITLE && $conversation->messages()->where('role', 'user')->count() === 1) {
            $conversation->forceFill(['title' => AssistantConversation::titleFrom(self::isLearn($question) ? 'Aprender' : $question)])->save();
        }

        if (self::isLearn($question)) {
            return $this->learn($user, $conversation, $question, $channel);
        }

        return $this->answer($user, $conversation, $message, $channel);
    }

    /**
     * Answers a stored question (also "Reintentar").
     */
    public function answer(User $user, AssistantConversation $conversation, AssistantMessage $question, string $channel = 'app'): AssistantMessage
    {
        $reason = $this->unavailableReason();
        if ($reason !== null) {
            return $this->write($conversation, 'assistant', 'error', $reason, ['retryable' => false], 'failed');
        }

        $context = new ToolContext($conversation, $channel);
        $agent = new AssistantAgent($this->history($conversation, $question), $this->tools->forRun($user, $context));

        $run = $this->runtime->run($agent, (string) $question->body, $user, self::FEATURE);

        if ($context->settled()) {
            $reply = $context->reply;
            $reply->forceFill(['ai_interaction_id' => $run->lastInteraction()?->id])->save();

            return $reply;
        }

        if ($run->failed()) {
            Log::warning('Assistant provider failed', [
                'conversation_id' => $conversation->id,
                'message_id' => $question->id,
                'error' => $run->error === null ? 'empty response' : AiRuntime::describe($run->error),
            ]);

            return $this->write($conversation, 'assistant', 'error', self::UNAVAILABLE, ['retryable' => true], 'failed', $run);
        }

        $text = $run->text();

        if ($text === '' || self::saysNotDocumented($text) || $context->foundNothing()) {
            return $this->notDocumented($user, $conversation, $question, $text, $channel, $run);
        }

        return $this->answered($user, $conversation, $text, $context, $run);
    }

    public static function saysNotDocumented(string $text): bool
    {
        return str_contains(Str::lower(Str::ascii($text)), self::NOT_DOCUMENTED_PHRASE);
    }

    private function answered(User $user, AssistantConversation $conversation, string $text, ToolContext $context, AiRun $run): AssistantMessage
    {
        preg_match_all('/K-\d+/i', $text, $matches);
        $cited = array_values(array_intersect(array_unique(array_map('strtoupper', $matches[0])), $context->cards));
        $sources = $cited !== [] ? $cited : $context->opened;

        // "[K-031]", "[K-031, K-027]" and "(K-031)" are shown as sources, not in the text.
        $clean = trim((string) preg_replace('/\s*[\[(]\s*K-\d+(?:\s*[,;y]\s*K-\d+)*\s*[\])]/iu', '', $text));

        $message = $this->write($conversation, 'assistant', 'answer', $clean === '' ? $text : $clean, ['sources' => $sources, 'suggestions' => []], 'done', $run);

        if ($sources !== []) {
            $now = now();
            foreach (KnowledgeItem::query()->whereIn('key', $sources)->pluck('id') as $cardId) {
                AssistantCitation::query()->create(['knowledge_item_id' => $cardId, 'message_id' => $message->id, 'user_id' => $user->id, 'cited_at' => $now]);
            }
        }

        return $message;
    }

    private function notDocumented(User $user, AssistantConversation $conversation, AssistantMessage $question, string $text, string $channel, AiRun $run): AssistantMessage
    {
        $explanation = trim((string) preg_replace('/^\W*no lo tengo documentado\W*/iu', '', $text));

        $message = $this->write($conversation, 'assistant', 'no_answer', self::NOT_DOCUMENTED, [
            'explanation' => $explanation === '' ? null : $explanation,
            'times_asked' => 1,
            'suggestions' => [],
            'suggestions_label' => null,
        ], 'done', $run);

        $row = $this->unanswered->record($user, (string) $question->body, $channel === 'telegram' ? 'telegram' : 'app', $conversation->id, $message->id);
        $message->forceFill(['payload' => [...$message->payload, 'times_asked' => $row->times_asked]])->save();

        return $message;
    }

    private function learn(User $user, AssistantConversation $conversation, string $question, string $channel): AssistantMessage
    {
        $text = trim(mb_substr(ltrim($question), mb_strlen(self::LEARN_COMMAND)));

        try {
            $draft = $this->drafts->fromText($user, $text, $channel === 'telegram' ? 'telegram' : 'app');
        } catch (DraftRejected $rejected) {
            return $this->write($conversation, 'assistant', 'refused', $rejected->getMessage());
        }

        $title = (string) ($draft->proposed['title'] ?? '');

        return $this->write($conversation, 'assistant', 'answer',
            "Listo: guardé el borrador «{$title}» para que TI lo revise y lo apruebe. No se publica hasta que lo aprueben.",
            ['sources' => [], 'suggestions' => []],
        );
    }

    /**
     * The last 30 minutes of the conversation before the question: user
     * questions and assistant texts only (never notices or links).
     *
     * @return list<Message>
     */
    private function history(AssistantConversation $conversation, AssistantMessage $question): array
    {
        $since = now()->subMinutes((int) config('gestor.assistant.context_minutes', 30));

        return $conversation->messages()
            ->where('id', '<', $question->id)
            ->where('created_at', '>=', $since)
            ->whereIn('kind', AssistantMessage::CONTEXT_KINDS)
            ->whereIn('role', ['user', 'assistant'])
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->filter(fn (AssistantMessage $message) => filled($message->body))
            ->map(fn (AssistantMessage $message): Message => $message->role === 'user'
                ? new UserMessage((string) $message->body)
                : new SdkAssistantMessage((string) $message->body))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function write(AssistantConversation $conversation, string $role, string $kind, ?string $body, ?array $payload = null, string $status = 'done', ?AiRun $run = null): AssistantMessage
    {
        $message = $conversation->messages()->create([
            'role' => $role,
            'kind' => $kind,
            'body' => $body,
            'payload' => $payload,
            'status' => $status,
            'ai_interaction_id' => $run?->lastInteraction()?->id,
        ]);

        $conversation->forceFill(['last_activity_at' => now()])->save();

        return $message;
    }
}
