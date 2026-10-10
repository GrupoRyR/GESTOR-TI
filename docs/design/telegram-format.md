# Telegram format · Asistente TI bot (backend spec)

Source boards: `C-TelegramAnswers`, `C-TelegramActions` (Stage C) and `B-ApprovalDone` (Stage B). The bot is the same Asistente as the in-app chat: short answers, sources always cited, never invents menu paths, report names or data. Spanish copy below is verbatim from the boards; `[NOMBRE DEL BOT]` is still unknown. Links are absolute URLs built with the named routes noted next to them (`{route(...)}` in the examples).

## 1. Message rules

| Rule | Value |
|---|---|
| `parse_mode` | `HTML` |
| Allowed tags | `<b>`, `<i>`, `<a href>` in answers. `<code>` only in the password message (§4). No tables, headings, Markdown or emoji. |
| Length | At most 4 short paragraphs. Lists: one item per line starting with `• `. |
| Sources | Always last: `<b>Fuentes:</b>` then one link per knowledge card, the card title as link text. |
| Escaping | Escape `&` → `&amp;`, `<` → `&lt;`, `>` → `&gt;` in all AI and user text **before** wrapping it in tags. Escape `"` inside `href`. |
| Long answers | Over 4 paragraphs or 3,500 characters: cut after the last complete paragraph, add the "Ver respuesta completa en el Gestor TI" link. Sources are never cut. (Telegram's hard limit is 4,096 characters after entity parsing.) |
| Buttons | Plain text, no emoji. `callback_data` ≤ 64 bytes (tildes and ñ count 2 bytes), only a short opaque id: never names, emails or passwords. |
| On every tap | The Gestor resolves the id server-side and **re-checks the permission** of the person who tapped; expired or unknown ids answer with `answerCallbackQuery` and change nothing. |

## 2. Answers (`C-TelegramAnswers`)

### a · Answer with sources

Question: `¿Quién recibe el informe de ventas diario y a qué hora sale?`

```html
Sale a las <b>6:00 a. m.</b> desde Toma Pedidos SAP y llega por correo a:
• Gerencia
• Coordinadores de ventas de Segurex
Trae los pedidos del día anterior desde SAP B1.

<b>Fuentes:</b>
<a href="{route('assistant.knowledge.show', {card: 'K-031'})}">Informe de ventas diario</a>
<a href="{route('assistant.knowledge.show', {card})}">Pedidos de Toma Pedidos a SAP B1</a>
```

Inline keyboard (one button per row):

| Label | Type | Data |
|---|---|---|
| `¿Quién es el dueño del informe?` | callback | `ask:s:q88` (9 bytes) |
| `¿Qué pasa si un pedido no sincroniza?` | callback | `ask:s:q89` |
| `Ver la ficha de Toma Pedidos SAP` | url | `ai.products.show` `{product: 'toma-pedidos'}` |

A suggested-question tap is sent as if the person typed the question.

### b · Not documented

```html
No lo tengo documentado. Registré la pregunta para que TI la documente.
```

Only to holders of `assistant.knowledge.manage`, add a url button `Ver preguntas sin respuesta` → `assistant.unanswered.index`. Everyone else gets the text alone.

### c · Typing and unavailable

- While the answer is generated: `sendChatAction` with `typing`, repeated every 4 s (the action lasts 5 s) until the reply is sent.
- If the AI provider fails, times out, has no API key or hit the monthly cap, the same text for everyone:

```html
El asistente no está disponible en este momento, intenta en unos minutos.
```

The reason is shown only to administrators inside the Gestor TI.

### d · Long answer (cut)

```html
…y al final pruebas que abra el correo de la empresa.

<a href="{route('assistant.index')}">Ver respuesta completa en el Gestor TI</a>

<b>Fuentes:</b> <a href="{route('assistant.knowledge.show', {card})}">Conectarse a la VPN</a>
```

## 3. Refusal

Anyone without `assistant.credentials` + `credentials.view` who asks for a password:

```html
No puedo entregar contraseñas. Pídela a TI.
```

Generic on purpose: it never says whether the credential exists.

## 4. Password delivery (`C-TelegramActions` a) · only Jhon and Leonardo

Requires `assistant.credentials` AND `credentials.view`, checked on the request and again on every tap.

1. Ambiguous person (`Dame la contraseña de Office de Juan Pérez`):

```html
Hay dos personas con ese nombre. ¿De cuál?
```

| Label | Data |
|---|---|
| `Juan Pérez · Asesor comercial · Ventas · Segurex` | `cred:pick:a7f3` (14 bytes) |
| `Juan Pérez · Auxiliar de bodega · Logística · Derivados` | `cred:pick:c19e` (14 bytes) |

The ids are short-lived, bound to the requesting chat and person.

2. The credential goes in a **separate message sent by the Gestor directly to Telegram**; it never passes through the AI provider and is never stored in the conversation:

```html
<b>Office · Juan Pérez</b>
Ventas · Segurex
Usuario: juan.perez@segurex.com
Contraseña: <code>Tq8#vL2m-Rx4p</code>
Este mensaje se borra en 60 s
<i>No pasó por la IA · quedó en la auditoría</i>
```

(`Tq8#vL2m-Rx4p` is the board's fake sample.) Escape the value like any text before putting it in `<code>`.

3. After 60 s: `deleteMessage`. If the delete fails (older than 48 h, missing rights, network), `editMessageText` with:

```html
Mensaje borrado por seguridad
```

Both the delivery and the delete/edit outcome are written to the audit log.

## 5. Vault credential (`C-TelegramActions` c)

Vault items (`ai.vault.access`) are never delivered by chat. `Dame la llave de API de DeepSeek`:

```html
Esa credencial está en la bóveda del proyecto. Ábrela ahí; te pedirá confirmar tu identidad.
```

One url button: `Abrir en la bóveda` → `ai.vault.index` `{item}` (opens the locked vault; identity is confirmed there). People without the permission get the refusal of §3.

## 6. `/aprender` (`C-TelegramActions` b)

`/aprender la VPN de Derivados usa FortiClient y la cuenta la crea Leonardo` creates a draft; nothing is published until someone approves it.

```html
<b>Borrador de ficha · pendiente de aprobar</b>
<b>Conectarse a la VPN de Derivados</b>
<i>Procedimiento · Derivados · TI · Solo TI</i>
• Se usa FortiClient.
• La cuenta la crea Leonardo Herrera.
```

| Label | Type | Data |
|---|---|---|
| `Aprobar` | callback | `learn:ok:d31` (12 bytes) |
| `Descartar` | callback | `learn:no:d31` (12 bytes) |
| `Editar en el Gestor TI` | url | `assistant.drafts.index` |

Approve/Discard require `assistant.knowledge.manage` at tap time. After `Aprobar`, edit the draft message: remove the keyboard and add the line `Aprobado por ti · 4:19 p. m.`, then reply:

```html
Ficha K-049 creada: <a href="{route('assistant.knowledge.show', {card: 'K-049'})}">Conectarse a la VPN de Derivados</a>. Desde ya la uso para responder.
```

## 7. Approvals (Stage B, `B-ApprovalDone`) · same pattern

Request to an approver (`ai.projects.approve`, Jhon or Edward):

```html
Leonardo Herrera te pide aprobar GIA-144 · 72 h · $ 11.500.000 · entrega 28 nov.
```

Buttons `Aprobar` / `Rechazar` (callback ids such as `proj:ok:p144` / `proj:no:p144`, proposal) plus a url button to the approval page. Rejecting needs a comment, so `Rechazar` answers with the link to write it in the Gestor. After `Aprobar`, edit the request message to show `Aprobada por ti · 10:12 a. m.` (keyboard removed) and reply:

```html
Listo, Edward. Quedó aprobada GIA-144 con tu comentario. Te aviso si el costo pasa del 80 %.
```

## 8. Checklist for the bot

- Every outgoing text is escaped before tags are added; only the tags of §1 appear.
- Every callback re-checks the permission and that the id belongs to that chat.
- Passwords: separate message, `<code>`, deleted at 60 s (edit fallback), audited, never sent to the AI.
- Vault: link button only.
- Refusal and "no disponible" texts never reveal why.
