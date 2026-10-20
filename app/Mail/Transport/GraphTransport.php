<?php

namespace App\Mail\Transport;

use App\Models\Company;
use App\Services\Microsoft\GraphClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

/**
 * `graph` mailer (`MAIL_MAILER=graph`): sends each message through
 * Microsoft Graph `POST /users/{sender}/sendMail` (JSON) with an app-only
 * token of the sender's tenant (App\Services\Microsoft\GraphClient).
 *
 * - Tenant: the company whose `sender_email` is the From address, else the
 *   company whose `domains` contain the From domain; none, or a company
 *   without `tenant_id`, refuses the message before any request.
 * - Body: Graph takes one body, so HTML when present, else the text part.
 * - Attachments go inline as `fileAttachment` (base64). Graph caps a
 *   request at 4 MB, so attachments above MAX_ATTACHMENT_BYTES (3 MB raw)
 *   are refused; upload sessions are not implemented (the largest real
 *   attachment, the project report PDF, is far smaller).
 * - 202 is success. Errors become GraphMailException (a Symfony
 *   TransportException): 429/5xx/connection are `retryable`, 401/403 carry
 *   the Mail.Send consent hint. A single attempt per send: sendMail is not
 *   idempotent, the queue retries the notification.
 */
class GraphTransport extends AbstractTransport
{
    public const MAX_ATTACHMENT_BYTES = 3 * 1024 * 1024;

    public function __construct(
        private readonly GraphClient $graph,
        private readonly bool $saveToSentItems = true,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        $email = $original instanceof Email ? $original : MessageConverter::toEmail($original);

        $from = $email->getFrom()[0] ?? $message->getEnvelope()->getSender();
        $sender = strtolower($from->getAddress());

        if ($sender === '') {
            throw GraphMailException::missingSender();
        }

        $company = self::companyFor($sender) ?? throw GraphMailException::unknownSender($sender);
        $tenantId = trim((string) $company->tenant_id);
        $companyName = (string) ($company->short_name ?: $company->name);

        if ($tenantId === '') {
            throw GraphMailException::missingTenant($sender, $companyName);
        }

        $payload = [
            'message' => $this->message($email, $from),
            'saveToSentItems' => $this->saveToSentItems,
        ];

        try {
            $token = $this->graph->token($tenantId);
        } catch (RequestException $exception) {
            throw GraphMailException::token($companyName, $exception->response->status());
        } catch (ConnectionException) {
            throw GraphMailException::token($companyName, null);
        } catch (RuntimeException) {
            throw GraphMailException::notConfigured();
        }

        try {
            $response = $this->graph->sendMail($token, $sender, $payload);
        } catch (ConnectionException) {
            throw GraphMailException::connection($sender);
        }

        if ($response->status() === 202 || $response->successful()) {
            return;
        }

        if ($response->status() === 401) {
            // A revoked or stale cached token: the retry asks for a new one.
            $this->graph->forgetToken($tenantId);
        }

        $code = $response->json('error.code');
        $code = is_string($code) && preg_match('/^[A-Za-z0-9_.]{1,80}$/', $code) === 1 ? $code : null;

        throw GraphMailException::rejected($sender, $response->status(), $code);
    }

    /**
     * The company that owns the sender mailbox: exact `sender_email` first,
     * then a company whose `domains` contain the sender's domain.
     */
    public static function companyFor(string $sender): ?Company
    {
        $sender = strtolower(trim($sender));
        $companies = Company::query()->orderBy('id')->get();

        $exact = $companies->first(fn (Company $company): bool => strtolower(trim((string) $company->sender_email)) === $sender);

        if ($exact !== null) {
            return $exact;
        }

        $domain = substr((string) strrchr($sender, '@'), 1);

        return $domain === '' ? null : $companies->first(
            fn (Company $company): bool => in_array($domain, array_map(fn ($d): string => strtolower(trim((string) $d)), (array) ($company->domains ?? [])), true)
        );
    }

    /**
     * Graph `message` resource.
     *
     * @return array<string, mixed>
     */
    private function message(Email $email, Address $from): array
    {
        $html = self::stringBody($email->getHtmlBody());
        $body = $html !== null
            ? ['contentType' => 'HTML', 'content' => $html]
            : ['contentType' => 'Text', 'content' => (string) self::stringBody($email->getTextBody())];

        return array_filter([
            'subject' => (string) $email->getSubject(),
            'body' => $body,
            'from' => self::recipient($from),
            'toRecipients' => self::recipients($email->getTo()),
            'ccRecipients' => self::recipients($email->getCc()),
            'bccRecipients' => self::recipients($email->getBcc()),
            'replyTo' => self::recipients($email->getReplyTo()),
            'attachments' => $this->attachments($email),
        ], fn (mixed $value): bool => $value !== []);
    }

    /**
     * @param  resource|string|null  $body
     */
    private static function stringBody(mixed $body): ?string
    {
        if (is_resource($body)) {
            $body = stream_get_contents($body);
        }

        return is_string($body) && $body !== '' ? $body : null;
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<array{emailAddress: array{address: string, name?: string}}>
     */
    private static function recipients(array $addresses): array
    {
        return array_values(array_map(self::recipient(...), $addresses));
    }

    /**
     * @return array{emailAddress: array{address: string, name?: string}}
     */
    private static function recipient(Address $address): array
    {
        $emailAddress = ['address' => $address->getAddress()];

        if ($address->getName() !== '') {
            $emailAddress['name'] = $address->getName();
        }

        return ['emailAddress' => $emailAddress];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attachments(Email $email): array
    {
        $attachments = [];
        $total = 0;

        foreach ($email->getAttachments() as $part) {
            /** @var DataPart $part */
            $bytes = $part->getBody();
            $total += strlen($bytes);
            $inline = $part->getDisposition() === 'inline';

            $attachment = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $part->getFilename() ?? 'adjunto',
                'contentType' => $part->getContentType(),
                'contentBytes' => base64_encode($bytes),
                'isInline' => $inline,
            ];

            if ($inline) {
                $attachment['contentId'] = $part->getContentId();
            }

            $attachments[] = $attachment;
        }

        if ($total > self::MAX_ATTACHMENT_BYTES) {
            throw GraphMailException::attachmentsTooLarge($total, self::MAX_ATTACHMENT_BYTES);
        }

        return $attachments;
    }

    public function __toString(): string
    {
        return 'graph';
    }
}
