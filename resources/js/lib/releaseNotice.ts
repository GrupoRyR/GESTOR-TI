/**
 * Notice each requester gets when a release ships their request (Telegram +
 * bell). The server sends exactly these paragraphs; the page shows the same
 * text as a preview before "Avisar a N solicitantes". It never carries
 * costs, internal notes or technical release notes.
 */

export interface NoticeRequester {
    name: string;
    company: string;
    area: string;
}

export interface NoticeRecipient {
    requester: NoticeRequester;
    requests: { key: string; title: string }[];
}

export const NOTES_PLACEHOLDER = '[Aquí van las notas para los usuarios que confirmes]';

export const NOTICE_CLOSING = '¿Cómo te fue? Califica la entrega en un minuto.';

/** One recipient per requester, first seen first, with their requests. */
export function noticeRecipients(included: { key: string; title: string; requester: NoticeRequester }[]): NoticeRecipient[] {
    const recipients: NoticeRecipient[] = [];
    for (const { key, title, requester } of included) {
        const found = recipients.find((recipient) => recipient.requester.name === requester.name);
        if (found) found.requests.push({ key, title });
        else recipients.push({ requester, requests: [{ key, title }] });
    }
    return recipients;
}

function joinSpanish(items: string[]): string {
    if (items.length <= 1) return items.join('');
    return `${items.slice(0, -1).join(', ')} y ${items[items.length - 1]}`;
}

export interface NoticeInput {
    requesterName: string;
    requests: { key: string; title: string }[];
    productName: string;
    tag: string;
    /** User-facing notes Jhon confirmed. */
    notes: string;
}

/** Greeting, the confirmed notes (or a placeholder) and the rating ask, as paragraphs. */
export function releaseNoticeMessage({ requesterName, requests, productName, tag, notes }: NoticeInput): string[] {
    const firstName = requesterName.trim().split(/\s+/)[0] ?? requesterName;
    const what = joinSpanish(requests.map((request) => `${request.title} (${request.key})`));
    const body = notes.trim();
    return [`Hola, ${firstName}. Ya está disponible lo que pediste: ${what}, en ${productName} ${tag}.`, body || NOTES_PLACEHOLDER, NOTICE_CLOSING];
}
