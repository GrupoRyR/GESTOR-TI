# Email through Microsoft Graph

## Objective
Send every Gestor TI email through Microsoft Graph (`POST /users/{sender}/sendMail`). Each message goes from the mailbox of the record's company, using an app-only token from that company's tenant. This replaces SMTP.

## Why
- Segurex and Derivados are separate Microsoft 365 tenants. One SMTP account cannot "send as" a mailbox in the other tenant.
- Microsoft is retiring SMTP AUTH with Basic authentication (MC786329; later moved to "disabled by default at the end of December 2026").
- The Entra app already exists, with admin consent in each tenant, for login and employee sync. Sending through it stores no mailbox password.

## Decisions
- 2026-10-20, user: "sí, implementa el envío por Graph".
- The custom mailer is named `graph` (`MAIL_MAILER=graph`). Templates, the per-company sender and logos stay as they are.
- Tests fake Graph with `Http::fake`. A real send needs the Exchange RBAC role "Application Mail.Send" scoped to the company mailbox in each tenant, and NOT `Mail.Send` consented in Entra: both grants add together, so Entra consent would allow every mailbox.

## Delivery
- Branch `feat/mail-graph-transport` from `main`. A single PR. RDD off.
- Checks: `php artisan test`, `vendor/bin/pint --test`, `npm run typecheck`, `npm test`, `npm run build`.

## Tasks
- [x] G1 · `graph` mail transport plus deploy guide.
  - Reuse `App\Services\Microsoft\GraphClient`'s token per tenant.
  - Resolve the tenant from the sender domain or company.
  - HTML, text, attachments, reply-to, cc/bcc.
  - Errors are logged without secrets, and the job retries.
  - Tests.
  - Guide section 5.2 rewritten for Graph.
  - Route: delegated (writer trigger).

## Progress / evidence

### G1 — done (`b260285`)
- Classes: `App\Mail\Transport\GraphTransport` (Symfony `AbstractTransport`, registered with `Mail::extend('graph')` in `AppServiceProvider`), `App\Mail\Transport\GraphMailException` (extends Symfony `TransportException`; English `getMessage()` for logs, Spanish `userMessage()`, `reason`, `status`, `retryable`). `GraphClient` gained `sendMail($token, $mailbox, $payload)` (single attempt, no throw) and `forgetToken($tenantId)`; tokens still come from `GraphClient::token()` (encrypted per-tenant cache).
- Config: `config/mail.php` `mailers.graph = ['transport' => 'graph', 'save_to_sent_items' => env('MAIL_GRAPH_SAVE_TO_SENT', true)]`. Default stays `MAIL_MAILER` (default `log`). No new secrets.
- Request shape (verified against https://learn.microsoft.com/en-us/graph/api/user-sendmail): `POST https://graph.microsoft.com/v1.0/users/{urlencoded sender}/sendMail`, JSON `{message: {subject, body: {contentType: HTML|Text, content}, from, toRecipients, ccRecipients, bccRecipients, replyTo, attachments: [{@odata.type: #microsoft.graph.fileAttachment, name, contentType, contentBytes (base64), isInline[, contentId]}]}, saveToSentItems}`; empty lists omitted; 202 = accepted.
- Tenant resolution: company whose `sender_email` equals the From address (case-insensitive), else company whose `domains` contain the From domain; none → `unknown_sender`, no tenant → `missing_tenant`; both before any HTTP request.
- Limits: attachments over 3 MB raw in total are refused (`attachments_too_large`); Graph caps a request at 4 MB and base64 adds ~33 %. Upload sessions not implemented; the only real attachment is the project report PDF.
- Errors: 401/403 → `forbidden` with the Mail.Send consent/RBAC hint (401 also forgets the cached token); 429 → `throttled`, 5xx → `unavailable`, connection → `connection` (all `retryable`; the queue retries the notification); other 4xx → `rejected` with Graph's sanitized `error.code`. No token, secret or response body ever goes into a message.
- Integraciones: "Enviarme un correo de prueba" shows `No se pudo enviar el correo de prueba: {userMessage}` for Graph errors; other errors keep the generic text.
- Decision: one sendMail attempt per send (not idempotent; no in-request retry). `from` is sent with the company display name.
- Decision (docs, from Microsoft Learn https://learn.microsoft.com/en-us/exchange/permissions-exo/application-rbac): Entra and Exchange RBAC grants are additive, so the guide tells NOT to consent `Mail.Send` in Entra and to grant it only through `New-ServicePrincipal` + `New-ManagementScope` + `New-ManagementRoleAssignment -Role "Application Mail.Send" -CustomResourceScope`, verified with `Test-ServicePrincipalAuthorization`. Entra consent is documented as a non-recommended unscoped alternative.
- Tests: `tests/Feature/Mail/GraphTransportTest.php` (10) and 2 new in `tests/Feature/Admin/IntegrationsAdminTest.php`. RED observed: 10/10 errors (`Mailer [graph] is not defined`, missing exception class); integration readable-error test failed before the controller change.
- Docs: `docs/deploy-cpanel.md` prerequisites row, mail `.env` rows, section 5.2 and troubleshooting rows rewritten for Graph; SMTP kept as fallback.
- Checks: `php artisan test --filter='Mail|Graph|Integration'`: 156 passed (1259 assertions). `php artisan test`: 1281 passed (13469 assertions). `vendor/bin/pint --test`: passed. Frontend checks (`npm run typecheck`, `npm test`, `npm run build`) not run: no frontend change.
- Commit: see `git log` on `feat/mail-graph-transport` (`feat(mail): send email through Microsoft Graph per company tenant`).
